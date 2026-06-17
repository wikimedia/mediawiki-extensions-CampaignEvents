<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Worklist;

use Generator;
use MediaWiki\Content\WikitextContent;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\WikiLookup;
use MediaWiki\Extension\CampaignEvents\Worklist\UpdateWorklistPagesSecondaryStoreJob;
use MediaWiki\Extension\CampaignEvents\Worklist\WorklistContent;
use MediaWiki\Extension\CampaignEvents\Worklist\WorklistContentHandler;
use MediaWiki\Extension\CampaignEvents\Worklist\WorklistPagesSecondaryStore;
use MediaWiki\Extension\CampaignEvents\Worklist\WorklistSecondaryStore;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Utils\MWTimestamp;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\ScopedCallback;

/**
 * @covers \MediaWiki\Extension\CampaignEvents\Worklist\UpdateWorklistPagesSecondaryStoreJob
 * @group Database
 */
class UpdateWorklistPagesSecondaryStoreJobTest extends MediaWikiIntegrationTestCase {
	protected function setUp(): void {
		parent::setUp();
		// Temporarily fill in for the extension registration callback, which runs too early. Remove when dropping
		// feature flag wgCampaignEventsEnableWorklists
		$this->mergeMwGlobalArrayValue(
			'wgContentHandlers',
			[ CONTENT_MODEL_WORKLIST => WorklistContentHandler::class ]
		);
		// Disable events, so we can test the job in isolation.
		$this->setService( 'DomainEventDispatcher', $this->createEventDispatcher() );

		$wikiLookup = $this->createMock( WikiLookup::class );
		$wikiLookup->method( 'getAllWikis' )->willReturn( [ WikiMap::getCurrentWikiId() ] );
		$this->setService( WikiLookup::SERVICE_NAME, $wikiLookup );
	}

	public static function getWorklistContents(): array {
		static $contents = null;
		if ( !$contents ) {
			$contents = [
				new WorklistContent( '{}' ),
				new WorklistContent( json_encode( [ WikiMap::getCurrentWikiId() => [ 'Foo' ] ] ) ),
			];
		}
		return $contents;
	}

	/**
	 * Creates a worklist and sync pages without any specific assertions on the result.
	 * Returns the revision ID of the synced version of the page.
	 */
	private function createWorklist( PageIdentity $page, WorklistContent $content ): int {
		$initialRevID = $this->editPage( $page, $content )->getNewRevision()->getId();
		CampaignEventsServices::getWorklistSecondaryStore()->createWorklist(
			WikiMap::getCurrentWikiId(),
			$page->getId(),
			$this->getServiceContainer()->getTitleFormatter()->getPrefixedText( $page ),
			new CentralUser( 1 ),
			'Worklist creator',
			MWTimestamp::getInstance()
		);
		$initialJob = UpdateWorklistPagesSecondaryStoreJob::newForUpdate(
			$page, 1, new CentralUser( 1 ), $initialRevID
		);
		$this->assertTrue( $initialJob->run(), 'Initial sync' );
		return $initialRevID;
	}

	/**
	 * Deletes a worklist without specific assertions on the results, returning the new rev ID or null
	 */
	private function deleteWorklist( PageIdentity $page, bool $viaPageDeletion ): ?int {
		if ( $viaPageDeletion ) {
			$this->deletePage( $page );
			$triggeringRevID = null;
		} else {
			$editStatus = $this->editPage( $page, new WikitextContent( 'Wikitext!' ) );
			$this->assertStatusGood( $editStatus );
			$triggeringRevID = $editStatus->getNewRevision()->getId();
		}

		CampaignEventsServices::getWorklistSecondaryStore()
			->deleteWorklist( WikiMap::getCurrentWikiId(), $page->getId() );

		return $triggeringRevID;
	}

	public function testRunUpdate__pageChangedBeforeJob() {
		$page = $this->getNonexistingTestPage();
		$contents = self::getWorklistContents();

		$firstEditStatus = $this->editPage( $page, $contents[0] );
		$this->assertStatusGood( $firstEditStatus );
		$firstRevID = $firstEditStatus->getNewRevision()->getId();
		$worklistID = 1;
		$firstJob = UpdateWorklistPagesSecondaryStoreJob::newForUpdate(
			$page, $worklistID, new CentralUser( 1 ), $firstRevID
		);

		// The page is edited before the first job runs
		$secondEditStatus = $this->editPage( $page, $contents[1] );
		$this->assertStatusGood( $secondEditStatus );
		$secondRevID = $secondEditStatus->getNewRevision()->getId();
		$secondJob = UpdateWorklistPagesSecondaryStoreJob::newForUpdate(
			$page, $worklistID, new CentralUser( 1 ), $secondRevID
		);

		// The first job should succeed without making writes
		$oldConnProvider = $this->getServiceContainer()->getConnectionProvider();
		$this->setService( 'ConnectionProvider', $this->createNoOpMock( IConnectionProvider::class ) );
		$this->assertTrue( $firstJob->run(), 'First job should succeed (noop)' );
		$this->setService( 'ConnectionProvider', $oldConnProvider );

		// Second job should also succeed and update the secondary storage
		$worklistsStore = $this->createMock( WorklistSecondaryStore::class );
		$worklistsStore->expects( $this->once() )
			->method( 'getWorklistContentSyncedRev' )
			->willReturn( $firstRevID );
		$worklistsStore->expects( $this->once() )
			->method( 'updateWorklistContentSyncedRev' )
			->with( $worklistID, $secondRevID );
		$this->setService( WorklistSecondaryStore::SERVICE_NAME, $worklistsStore );
		$pagesSecondaryStore = $this->createMock( WorklistPagesSecondaryStore::class );
		$pagesSecondaryStore->expects( $this->once() )->method( 'updateWorklistPages' );
		$this->setService( WorklistPagesSecondaryStore::SERVICE_NAME, $pagesSecondaryStore );
		$this->assertTrue( $secondJob->run(), 'Second job should succeed' );
	}

	/** @dataProvider provideWorklistDeletionIsPageDeletion */
	public function testRunUpdate__worklistDeletedBeforeJob( bool $isPageDeletion ) {
		$page = $this->getNonexistingTestPage();
		$contents = self::getWorklistContents();

		$firstRevID = $this->editPage( $page, $contents[0] )->getNewRevision()->getId();
		$firstJob = UpdateWorklistPagesSecondaryStoreJob::newForUpdate( $page, 1, new CentralUser( 1 ), $firstRevID );

		// The worklist is deleted before the first job runs
		$triggeringRevID = $this->deleteWorklist( $page, $isPageDeletion );
		$secondJob = UpdateWorklistPagesSecondaryStoreJob::newForDeletion( $page, 1, $triggeringRevID );

		// The first job should succeed without making writes
		$oldConnProvider = $this->getServiceContainer()->getConnectionProvider();
		$this->setService( 'ConnectionProvider', $this->createNoOpMock( IConnectionProvider::class ) );
		$this->assertTrue( $firstJob->run(), 'First job should succeed (noop)' );
		$this->setService( 'ConnectionProvider', $oldConnProvider );

		// Second job should also succeed and update the secondary storage
		$pagesSecondaryStore = $this->createMock( WorklistPagesSecondaryStore::class );
		$pagesSecondaryStore->expects( $this->once() )->method( 'deleteAllWorklistPages' );
		$this->setService( WorklistPagesSecondaryStore::SERVICE_NAME, $pagesSecondaryStore );
		$this->assertTrue( $secondJob->run(), 'Second job should succeed' );
	}

	public static function provideWorklistDeletionIsPageDeletion(): Generator {
		yield 'Page deletion' => [ true ];
		yield 'Content model change' => [ false ];
	}

	/** @dataProvider provideWorklistDeletionIsPageDeletion */
	public function testRunDelete__worklistRecreatedBeforeJob( bool $isPageDeletion ) {
		$page = $this->getNonexistingTestPage();
		$contents = self::getWorklistContents();

		// First sync the worklist
		$initialRevID = $this->createWorklist( $page, $contents[0] );
		$worklistID = 1;

		// Then delete the worklist
		$triggeringRevID = $this->deleteWorklist( $page, $isPageDeletion );
		$firstJob = UpdateWorklistPagesSecondaryStoreJob::newForDeletion( $page, $worklistID, $triggeringRevID );

		// The worklist is then recreated before the first job runs
		$secondRevID = $this->editPage( $page, $contents[1] )->getNewRevision()->getId();
		$secondJob = UpdateWorklistPagesSecondaryStoreJob::newForUpdate(
			$page, $worklistID, new CentralUser( 1 ), $secondRevID
		);

		// The first job should succeed without making writes
		$oldConnProvider = $this->getServiceContainer()->getConnectionProvider();
		$this->setService( 'ConnectionProvider', $this->createNoOpMock( IConnectionProvider::class ) );
		$this->assertTrue( $firstJob->run(), 'First job should succeed (noop)' );
		$this->setService( 'ConnectionProvider', $oldConnProvider );

		// Second job should also succeed and update the secondary storage
		$worklistsStore = $this->createMock( WorklistSecondaryStore::class );
		$worklistsStore->expects( $this->once() )
			->method( 'getWorklistContentSyncedRev' )
			->willReturn( $initialRevID );
		$worklistsStore->expects( $this->once() )
			->method( 'updateWorklistContentSyncedRev' )
			->with( $worklistID, $secondRevID );
		$this->setService( WorklistSecondaryStore::SERVICE_NAME, $worklistsStore );
		$pagesSecondaryStore = $this->createMock( WorklistPagesSecondaryStore::class );
		$pagesSecondaryStore->expects( $this->once() )->method( 'updateWorklistPages' );
		$this->setService( WorklistPagesSecondaryStore::SERVICE_NAME, $pagesSecondaryStore );
		$this->assertTrue( $secondJob->run(), 'Second job should succeed' );
	}

	/** @dataProvider provideWorklistDeletionIsPageDeletion */
	public function testRunDelete__otherNonWorklistEditBeforeJob( bool $isPageDeletion ) {
		$page = $this->getNonexistingTestPage();
		$contents = self::getWorklistContents();

		// First sync the worklist
		$this->createWorklist( $page, $contents[0] );

		// Then delete the worklist
		$triggeringRevID = $this->deleteWorklist( $page, $isPageDeletion );
		$firstJob = UpdateWorklistPagesSecondaryStoreJob::newForDeletion( $page, 1, $triggeringRevID );

		// The page is then edited before the first job runs, but it's still not a worklist
		$secondRevID = $this->editPage( $page, 'Different wikitext' )->getNewRevision()->getId();
		$secondJob = UpdateWorklistPagesSecondaryStoreJob::newForDeletion( $page, 1, $secondRevID );

		// The first job should succeed without making writes
		$oldConnProvider = $this->getServiceContainer()->getConnectionProvider();
		$this->setService( 'ConnectionProvider', $this->createNoOpMock( IConnectionProvider::class ) );
		$this->assertTrue( $firstJob->run(), 'First job should succeed (noop)' );
		$this->setService( 'ConnectionProvider', $oldConnProvider );

		// Second job should also succeed and update the secondary storage
		$pagesSecondaryStore = $this->createMock( WorklistPagesSecondaryStore::class );
		$pagesSecondaryStore->expects( $this->once() )->method( 'deleteAllWorklistPages' );
		$this->setService( WorklistPagesSecondaryStore::SERVICE_NAME, $pagesSecondaryStore );
		$this->assertTrue( $secondJob->run(), 'Second job should succeed' );
	}

	public function testRun__cannotAcquireLock() {
		$page = $this->getNonexistingTestPage();
		$contents = self::getWorklistContents();

		static $lockAcquired = false;
		$lockFunction = static function () use ( &$lockAcquired ) {
			if ( $lockAcquired ) {
				return null;
			}
			$lockAcquired = true;
			return new ScopedCallback( static function () use ( &$lockAcquired ) {
				$lockAcquired = false;
			} );
		};

		$firstRevID = $this->editPage( $page, $contents[0] )->getNewRevision()->getId();
		$firstJob = UpdateWorklistPagesSecondaryStoreJob::newForUpdate( $page, 1, new CentralUser( 1 ), $firstRevID );
		$firstJob->testLockFunction = $lockFunction;

		$worklistStore = $this->createMock( WorklistSecondaryStore::class );
		// Note, this method really should only be called once, because the lock is acquired
		// before checking the last synced rev.
		$worklistStore->expects( $this->once() )
			->method( 'getWorklistContentSyncedRev' )
			->willReturnCallback( function () use ( $page, $contents, $lockFunction ) {
				// While the first job is running, enqueue a new one and try to run it
				$secondRevID = $this->editPage( $page, $contents[1] )->getNewRevision()->getId();
				$secondJob = UpdateWorklistPagesSecondaryStoreJob::newForUpdate(
					$page, 1, new CentralUser( 1 ), $secondRevID
				);
				$secondJob->testLockFunction = $lockFunction;
				$this->assertFalse( $secondJob->run(), 'Second job should return false and be re-enqueued' );
				return null;
			} );
		$this->setService( WorklistSecondaryStore::SERVICE_NAME, $worklistStore );

		$this->assertTrue( $firstJob->run(), 'First job should run' );
	}

	public function testRunUpdate__pageChangedAfterLock() {
		$page = $this->getNonexistingTestPage();
		$contents = self::getWorklistContents();

		$revID = $this->editPage( $page, $contents[0] )->getNewRevision()->getId();
		$job = UpdateWorklistPagesSecondaryStoreJob::newForUpdate( $page, 1, new CentralUser( 1 ), $revID );

		$job->testLockFunction = function () use ( $page, $contents ) {
			// Make an edit after (simulated) lock acquisition.
			$editStatus = $this->editPage( $page, $contents[1] );
			$this->assertStatusGood( $editStatus );
			return new ScopedCallback( static function () {
			} );
		};

		$this->setService(
			WorklistPagesSecondaryStore::SERVICE_NAME,
			$this->createNoOpMock( WorklistPagesSecondaryStore::class )
		);
		$this->setService(
			WorklistSecondaryStore::SERVICE_NAME,
			$this->createNoOpMock( WorklistSecondaryStore::class )
		);
		$this->assertTrue( $job->run(), 'Job should succeed (noop)' );
	}

	/** @dataProvider provideWorklistDeletionIsPageDeletion */
	public function testRunDelete__success( bool $isPageDeletion ) {
		$page = $this->getNonexistingTestPage();
		$contents = self::getWorklistContents();

		// First sync the worklist
		$this->createWorklist( $page, $contents[0] );

		// Then delete
		$triggeringRevID = $this->deleteWorklist( $page, $isPageDeletion );

		$worklistID = 1;
		$pagesSecondaryStore = $this->createMock( WorklistPagesSecondaryStore::class );
		$pagesSecondaryStore->expects( $this->once() )->method( 'deleteAllWorklistPages' )->with( $worklistID );
		$this->setService( WorklistPagesSecondaryStore::SERVICE_NAME, $pagesSecondaryStore );
		$worklistsStore = $this->createNoOpMock( WorklistSecondaryStore::class );
		$this->setService( WorklistSecondaryStore::SERVICE_NAME, $worklistsStore );

		$job = UpdateWorklistPagesSecondaryStoreJob::newForDeletion( $page, $worklistID, $triggeringRevID );
		$this->assertTrue( $job->run() );
	}

	public function testRunUpdate__success() {
		$page = $this->getNonexistingTestPage();
		$worklistID = 1;

		$initialPages = [ WikiMap::getCurrentWikiId() => [ 'Page 1' ] ];
		$initialContent = new WorklistContent( json_encode( $initialPages ) );
		$initialRevID = $this->editPage( $page, $initialContent )->getNewRevision()->getId();
		$initialJob = UpdateWorklistPagesSecondaryStoreJob::newForUpdate(
			$page, $worklistID, new CentralUser( 1 ), $initialRevID
		);

		$pagesSecondaryStore = $this->createMock( WorklistPagesSecondaryStore::class );
		$pagesSecondaryStore->expects( $this->once() )
			->method( 'updateWorklistPages' )
			->with( $worklistID, $this->anything(), [], $initialPages );
		$this->setService( WorklistPagesSecondaryStore::SERVICE_NAME, $pagesSecondaryStore );
		$worklistsStore = $this->createMock( WorklistSecondaryStore::class );
		$worklistsStore->expects( $this->once() )
			->method( 'getWorklistContentSyncedRev' )
			->willReturn( null );
		$worklistsStore->expects( $this->once() )
			->method( 'updateWorklistContentSyncedRev' )
			->with( $worklistID, $initialRevID );
		$this->setService( WorklistSecondaryStore::SERVICE_NAME, $worklistsStore );

		$this->assertTrue( $initialJob->run(), 'Initial sync' );

		$newPages = [ WikiMap::getCurrentWikiId() => [ 'Page 2' ] ];
		$newContent = new WorklistContent( json_encode( $newPages ) );
		$newRevID = $this->editPage( $page, $newContent )->getNewRevision()->getId();
		$newJob = UpdateWorklistPagesSecondaryStoreJob::newForUpdate(
			$page, $worklistID, new CentralUser( 1 ), $newRevID
		);

		$pagesSecondaryStore = $this->createMock( WorklistPagesSecondaryStore::class );
		$pagesSecondaryStore->expects( $this->once() )
			->method( 'updateWorklistPages' )
			->with( $worklistID, $this->anything(), $initialPages, $newPages );
		$this->setService( WorklistPagesSecondaryStore::SERVICE_NAME, $pagesSecondaryStore );
		$worklistsStore = $this->createMock( WorklistSecondaryStore::class );
		$worklistsStore->expects( $this->once() )
			->method( 'getWorklistContentSyncedRev' )
			->willReturn( $initialRevID );
		$worklistsStore->expects( $this->once() )
			->method( 'updateWorklistContentSyncedRev' )
			->with( $worklistID, $newRevID );
		$this->setService( WorklistSecondaryStore::SERVICE_NAME, $worklistsStore );

		$this->assertTrue( $newJob->run(), 'Second sync' );
	}
}
