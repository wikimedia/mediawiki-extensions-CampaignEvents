<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\MediaWikiEventIngress;

use IDBAccessObject;
use MediaWiki\Context\RequestContext;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\WikiLookup;
use MediaWiki\Extension\CampaignEvents\Worklist\WorklistContent;
use MediaWiki\Extension\CampaignEvents\Worklist\WorklistContentHandler;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Title\Title;
use MediaWiki\User\UserIdentity;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use stdClass;

/**
 * @covers \MediaWiki\Extension\CampaignEvents\MediaWikiEventIngress\WorklistPageEventIngress
 * @group Database
 */
class WorklistPageEventIngressTest extends MediaWikiIntegrationTestCase {
	/** The number of pages in {@link self::getNonEmptyContentPages} */
	private const NON_EMPTY_CONTENT_PAGE_COUNT = 2;

	protected function setUp(): void {
		parent::setUp();
		// Temporarily fill in for the extension registration callback, which runs too early. Remove when dropping
		// feature flag wgCampaignEventsEnableWorklists
		$this->mergeMwGlobalArrayValue(
			'wgContentHandlers',
			[ CONTENT_MODEL_WORKLIST => WorklistContentHandler::class ]
		);
		// XXX T407288: test users are not made global by CentralAuth...
		$centralUserLookup = $this->createMock( CampaignsCentralUserLookup::class );
		$centralUserLookup->method( 'newFromUserIdentity' )
			->willReturnCallback( static fn ( UserIdentity $user ): CentralUser => new CentralUser( $user->getId() ) );
		$this->setService( CampaignsCentralUserLookup::SERVICE_NAME, $centralUserLookup );

		$wikiLookup = $this->createMock( WikiLookup::class );
		$wikiLookup->method( 'getAllWikis' )->willReturn( [ WikiMap::getCurrentWikiId() ] );
		$this->setService( WikiLookup::SERVICE_NAME, $wikiLookup );
	}

	private static function getNonEmptyContentPages(): array {
		// Keep this in sync with NON_EMPTY_CONTENT_PAGE_COUNT
		return [ WikiMap::getCurrentWikiId() => [ 'Page 1', 'Page 2' ] ];
	}

	private static function getNonEmptyWorklistContent(): WorklistContent {
		return new WorklistContent( json_encode( self::getNonEmptyContentPages() ) );
	}

	private function runAsyncUpdates( bool $expectsJob = true ): void {
		DeferredUpdates::doUpdates();
		$numExpectedJobs = $expectsJob ? 1 : 0;
		$this->runJobs(
			[ 'minJobs' => $numExpectedJobs, 'maxJobs' => $numExpectedJobs ],
			[ 'type' => 'CampaignEventsUpdateWorklistPagesSecondaryStore' ]
		);
	}

	private function getWorklistRow( int $pageID ): stdClass|false {
		return $this->getDb()->newSelectQueryBuilder()
			->select( '*' )
			->from( 'ce_worklists' )
			->where( [
				'cew_wiki' => WikiMap::getCurrentWikiId(),
				'cew_page_id' => $pageID,
			] )
			->caller( __METHOD__ )
			->fetchRow();
	}

	private function getWorklistCount(): int {
		return (int)$this->getDb()->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( 'ce_worklists' )
			->caller( __METHOD__ )
			->fetchField();
	}

	private function getWorklistPageCount( int $worklistID ): int {
		return (int)$this->getDb()->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( 'ce_worklist_pages' )
			->where( [ 'cewp_cew_id' => $worklistID ] )
			->caller( __METHOD__ )
			->fetchField();
	}

	public function testCreateAndDelete() {
		$page = $this->getNonexistingTestPage();
		$performer = $this->getTestUser()->getUser();
		$editStatus = $this->editPage( $page, self::getNonEmptyWorklistContent(), '', NS_MAIN, $performer );
		$this->assertStatusGood( $editStatus );
		$revID = $editStatus->getNewRevision()->getId();
		$this->runAsyncUpdates();

		$worklistRow = $this->getWorklistRow( $page->getId() );
		$this->assertNotFalse( $worklistRow );
		$this->assertSame( $page->getTitle()->getPrefixedText(), $worklistRow->cew_page_prefixedtext, 'Prefixedtext' );
		$this->assertSame( $performer->getId(), (int)$worklistRow->cew_user_id, 'User ID' );
		$this->assertSame( $performer->getName(), $worklistRow->cew_username, 'Username' );
		$this->assertSame( $revID, (int)$worklistRow->cew_content_rev, 'Content revision' );

		$this->assertSame(
			self::NON_EMPTY_CONTENT_PAGE_COUNT,
			$this->getWorklistPageCount( (int)$worklistRow->cew_id ),
			'Worklist page count'
		);

		$this->deletePage( $page );
		$this->runAsyncUpdates();

		$this->assertSame( 0, $this->getWorklistCount(), 'Expected worklist to be deleted' );
		$this->assertSame(
			0,
			$this->getWorklistPageCount( (int)$worklistRow->cew_id ),
			'Expected worklist pages to be deleted'
		);
	}

	public function testHandlePageMovedEvent() {
		$page = $this->getNonexistingTestPage();
		$editStatus = $this->editPage( $page, self::getNonEmptyWorklistContent() );
		$this->assertStatusGood( $editStatus );
		$editRevID = $editStatus->getNewRevision()->getId();
		$this->runAsyncUpdates();

		$newTitleText = 'Help:A new worklist title';
		$moveStatus = $this->getServiceContainer()
			->getMovePageFactory()
			->newMovePage( $page, Title::newFromText( $newTitleText ) )
			->move( $this->getTestUser()->getUser() );
		$this->assertStatusGood( $moveStatus );
		$nullRev = $moveStatus->getValue()['nullRevision'];
		$this->assertInstanceOf( RevisionRecord::class, $nullRev );
		$this->runAsyncUpdates();

		$worklistRow = $this->getWorklistRow( $page->getId() );

		$this->assertNotFalse( $worklistRow );
		$this->assertSame( $newTitleText, $worklistRow->cew_page_prefixedtext );
		// ID gets updated even if no actual changes were made
		$this->assertSame( $nullRev->getId(), (int)$worklistRow->cew_content_rev, 'Content revision' );

		$this->assertSame(
			self::NON_EMPTY_CONTENT_PAGE_COUNT,
			$this->getWorklistPageCount( (int)$worklistRow->cew_id ),
			'Worklist page count'
		);
	}

	public function testHandlePageLatestRevisionChangedEvent__noLongerWorklist() {
		$page = $this->getNonexistingTestPage();
		$editStatus = $this->editPage( $page, self::getNonEmptyWorklistContent() );
		$this->assertStatusGood( $editStatus );
		$this->runAsyncUpdates();

		$worklistRow = $this->getWorklistRow( $page->getId() );
		$this->assertNotFalse( $worklistRow, 'Expected worklist to be created' );

		$changeStatus = $this->getServiceContainer()
			->getContentModelChangeFactory()
			->newContentModelChange( $this->getTestSysop()->getAuthority(), $page, CONTENT_MODEL_WIKITEXT )
			->doContentModelChange( RequestContext::getMain(), __METHOD__, false );
		$this->assertStatusGood( $changeStatus );
		$this->runAsyncUpdates();

		$this->assertSame( 0, $this->getWorklistCount(), 'Worklist row should be deleted' );
		$this->assertSame(
			0,
			$this->getWorklistPageCount( (int)$worklistRow->cew_id ),
			'Expected worklist pages to be deleted'
		);
	}

	public function testHandlePageLatestRevisionChangedEvent__isNowWorklist() {
		$page = $this->getNonexistingTestPage();
		// A valid worklist, but as wikitext.
		$wikitextContent = json_encode( self::getNonEmptyContentPages() );
		$editStatus = $this->editPage( $page, $wikitextContent );
		$this->assertStatusGood( $editStatus );
		$this->runAsyncUpdates( false );

		$this->assertSame( 0, $this->getWorklistCount(), 'No worklists initially' );

		$performer = $this->getTestSysop()->getUser();
		$changeStatus = $this->getServiceContainer()
			->getContentModelChangeFactory()
			->newContentModelChange( $performer, $page, CONTENT_MODEL_WORKLIST )
			->doContentModelChange( RequestContext::getMain(), __METHOD__, false );
		$this->assertStatusGood( $changeStatus );
		$this->runAsyncUpdates();
		// Reload page ID (ChangeContentModel does not provide it)
		$page->loadPageData( IDBAccessObject::READ_LATEST );
		$revID = $page->getRevisionRecord()->getId();

		$worklistRow = $this->getWorklistRow( $page->getId() );
		$this->assertNotFalse( $worklistRow );
		$this->assertSame( $page->getTitle()->getPrefixedText(), $worklistRow->cew_page_prefixedtext, 'Prefixedtext' );
		$this->assertSame( $performer->getId(), (int)$worklistRow->cew_user_id, 'User ID' );
		$this->assertSame( $performer->getName(), $worklistRow->cew_username, 'Username' );
		$this->assertSame( $revID, (int)$worklistRow->cew_content_rev, 'Content revision' );

		$this->assertSame(
			self::NON_EMPTY_CONTENT_PAGE_COUNT,
			$this->getWorklistPageCount( (int)$worklistRow->cew_id ),
			'Worklist page count'
		);
	}

	public function testHandlePageHistoryVisibilityChangedEvent() {
		$page = $this->getNonexistingTestPage();
		$performer = $this->getTestUser()->getUser();
		$editStatus = $this->editPage( $page, self::getNonEmptyWorklistContent(), '', NS_MAIN, $performer );
		$this->assertStatusGood( $editStatus );
		$firstRevID = $editStatus->getNewRevision()->getId();
		$this->runAsyncUpdates();

		$edit2Status = $this->editPage( $page, new WorklistContent( '{}' ) );
		$this->assertStatusGood( $edit2Status );
		$this->runAsyncUpdates();

		// Delete first revision
		$this->revisionDelete( $firstRevID, [ RevisionRecord::DELETED_USER => 1 ] );
		$this->runAsyncUpdates( false );

		$worklistRowAfterDelete = $this->getWorklistRow( $page->getId() );
		$this->assertNotFalse( $worklistRowAfterDelete );
		$this->assertSame( $performer->getId(), (int)$worklistRowAfterDelete->cew_user_id, 'User ID should remain' );
		$this->assertNull( $worklistRowAfterDelete->cew_username, 'Username should have been deleted' );

		// Then undelete it
		$this->revisionDelete( $firstRevID, [ RevisionRecord::DELETED_USER => 0 ] );
		$this->runAsyncUpdates( false );

		$worklistRowAfterUndelete = $this->getWorklistRow( $page->getId() );
		$this->assertNotFalse( $worklistRowAfterUndelete );
		$this->assertSame( $performer->getId(), (int)$worklistRowAfterUndelete->cew_user_id, 'User ID' );
		$this->assertSame(
			$performer->getName(),
			$worklistRowAfterUndelete->cew_username,
			'Username should have been restored'
		);
	}
}
