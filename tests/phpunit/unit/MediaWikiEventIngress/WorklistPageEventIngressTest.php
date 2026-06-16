<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\MediaWikiEventIngress;

use Generator;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\CampaignEvents\MediaWikiEventIngress\WorklistPageEventIngress;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\Worklist\WorklistSecondaryStore;
use MediaWiki\Page\Event\PageCreatedEvent;
use MediaWiki\Page\Event\PageDeletedEvent;
use MediaWiki\Page\Event\PageHistoryVisibilityChangedEvent;
use MediaWiki\Page\Event\PageLatestRevisionChangedEvent;
use MediaWiki\Page\Event\PageMovedEvent;
use MediaWiki\Page\ExistingPageRecord;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWiki\Title\TitleFormatter;
use MediaWiki\User\UserIdentityValue;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\CampaignEvents\MediaWikiEventIngress\WorklistPageEventIngress
 */
class WorklistPageEventIngressTest extends MediaWikiUnitTestCase {
	public static function setUpBeforeClass(): void {
		if ( !defined( 'CONTENT_MODEL_WORKLIST' ) ) {
			// Gotta redefine the constant due to T428794
			define( 'CONTENT_MODEL_WORKLIST', 'worklist' );
		}
	}

	public function getEventIngress(
		WorklistSecondaryStore $worklistSecondaryStore,
		?TitleFactory $titleFactory = null,
		?RevisionLookup $revisionLookup = null,
		?CampaignsCentralUserLookup $centralUserLookup = null,
	): WorklistPageEventIngress {
		// Needed because `getPrefixedText` has no return type declaration, so an unconfigured mock would return null.
		$titleFormatter = $this->createMock( TitleFormatter::class );
		$titleFormatter->method( 'getPrefixedText' )->willReturn( 'Some prefixedtext' );
		return new WorklistPageEventIngress(
			$worklistSecondaryStore,
			$titleFactory ?? $this->createMock( TitleFactory::class ),
			$titleFormatter,
			$revisionLookup ?? $this->createMock( RevisionLookup::class ),
			$centralUserLookup ?? $this->createMock( CampaignsCentralUserLookup::class ),
		);
	}

	private function mockTitleFactoryWithContentModel( string $contentModel ): TitleFactory {
		$title = $this->createMock( Title::class );
		$title->expects( $this->atLeastOnce() )
			->method( 'getContentModel' )
			->willReturn( $contentModel );
		$titleFactory = $this->createMock( TitleFactory::class );
		$titleFactory->expects( $this->atLeastOnce() )
			->method( 'newFromPageReference' )
			->willReturn( $title );
		return $titleFactory;
	}

	private function mockRevisionWithContentModel( string $contentModel ): RevisionRecord {
		$revision = $this->createMock( RevisionRecord::class );
		$revision->expects( $this->atLeastOnce() )
			->method( 'getMainContentModel' )
			->willReturn( $contentModel );
		return $revision;
	}

	private function mockRevisionLookupWithFirstRevisionID( int $id, ?string $authorName = null ): RevisionLookup {
		$firstRev = $this->createMock( RevisionRecord::class );
		$firstRev->expects( $this->atLeastOnce() )->method( 'getId' )->willReturn( $id );
		if ( $authorName !== null ) {
			$firstRev->expects( $this->atLeastOnce() )
				->method( 'getUser' )
				->willReturn( new UserIdentityValue( 1, $authorName ) );
		}

		$revisionLookup = $this->createMock( RevisionLookup::class );
		$revisionLookup->expects( $this->atLeastOnce() )
			->method( 'getFirstRevision' )
			->willReturn( $firstRev );
		return $revisionLookup;
	}

	public function testHandlePageCreatedEvent__wrongContentModel() {
		// Use no-op mock to assert the secondary store isn't invoked
		$worklistSecondaryStore = $this->createNoOpMock( WorklistSecondaryStore::class );
		$titleFactory = $this->mockTitleFactoryWithContentModel( CONTENT_MODEL_WIKITEXT );
		$eventIngress = $this->getEventIngress( $worklistSecondaryStore, $titleFactory );

		$eventIngress->handlePageCreatedEvent( $this->createMock( PageCreatedEvent::class ) );
	}

	public function testHandlePageCreatedEvent__creatorNotGlobal() {
		$worklistSecondaryStore = $this->createNoOpMock( WorklistSecondaryStore::class );
		$titleFactory = $this->mockTitleFactoryWithContentModel( CONTENT_MODEL_WORKLIST );
		$centralUserLookup = $this->createMock( CampaignsCentralUserLookup::class );
		$centralUserLookup->expects( $this->atLeastOnce() )
			->method( 'newFromUserIdentity' )
			->willThrowException( new UserNotGlobalException( 1 ) );
		$eventIngress = $this->getEventIngress( $worklistSecondaryStore, $titleFactory, null, $centralUserLookup );

		$eventIngress->handlePageCreatedEvent( $this->createMock( PageCreatedEvent::class ) );
	}

	public function testHandlePageCreatedEvent__success() {
		$worklistSecondaryStore = $this->createMock( WorklistSecondaryStore::class );
		$worklistSecondaryStore->expects( $this->once() )->method( 'createWorklist' );

		$titleFactory = $this->mockTitleFactoryWithContentModel( CONTENT_MODEL_WORKLIST );
		$eventIngress = $this->getEventIngress( $worklistSecondaryStore, $titleFactory );

		$eventIngress->handlePageCreatedEvent( $this->createMock( PageCreatedEvent::class ) );
		DeferredUpdates::doUpdates();
	}

	public function testHandlePageDeletedEvent__wrongContentModel() {
		$worklistSecondaryStore = $this->createNoOpMock( WorklistSecondaryStore::class );
		$eventIngress = $this->getEventIngress( $worklistSecondaryStore );

		$revision = $this->mockRevisionWithContentModel( CONTENT_MODEL_WIKITEXT );
		$event = $this->createMock( PageDeletedEvent::class );
		$event->expects( $this->atLeastOnce() )
			->method( 'getLatestRevisionBefore' )
			->willReturn( $revision );

		$eventIngress->handlePageDeletedEvent( $event );
	}

	public function testHandlePageDeletedEvent__success() {
		$worklistSecondaryStore = $this->createMock( WorklistSecondaryStore::class );
		$worklistSecondaryStore->expects( $this->once() )->method( 'deleteWorklist' );

		$revision = $this->mockRevisionWithContentModel( CONTENT_MODEL_WORKLIST );
		$event = $this->createMock( PageDeletedEvent::class );
		$event->expects( $this->atLeastOnce() )
			->method( 'getLatestRevisionBefore' )
			->willReturn( $revision );
		$eventIngress = $this->getEventIngress( $worklistSecondaryStore );

		$eventIngress->handlePageDeletedEvent( $event );
		DeferredUpdates::doUpdates();
	}

	public function testHandlePageMovedEvent__wrongContentModel() {
		$worklistSecondaryStore = $this->createNoOpMock( WorklistSecondaryStore::class );
		$titleFactory = $this->mockTitleFactoryWithContentModel( CONTENT_MODEL_WIKITEXT );
		$eventIngress = $this->getEventIngress( $worklistSecondaryStore, $titleFactory );

		$eventIngress->handlePageMovedEvent( $this->createMock( PageMovedEvent::class ) );
	}

	public function testHandlePageMovedEvent__success() {
		$worklistSecondaryStore = $this->createMock( WorklistSecondaryStore::class );
		$worklistSecondaryStore->expects( $this->once() )->method( 'moveWorklist' );
		$titleFactory = $this->mockTitleFactoryWithContentModel( CONTENT_MODEL_WORKLIST );
		$eventIngress = $this->getEventIngress( $worklistSecondaryStore, $titleFactory );

		$eventIngress->handlePageMovedEvent( $this->createMock( PageMovedEvent::class ) );
		DeferredUpdates::doUpdates();
	}

	public function testHandlePageLatestRevisionChangedEvent__pageCreation() {
		$worklistSecondaryStore = $this->createNoOpMock( WorklistSecondaryStore::class );
		$eventIngress = $this->getEventIngress( $worklistSecondaryStore );

		$event = $this->createMock( PageLatestRevisionChangedEvent::class );
		$event->expects( $this->atLeastOnce() )->method( 'getPageRecordBefore' )->willReturn( null );

		$eventIngress->handlePageLatestRevisionChangedEvent( $event );
	}

	/** @dataProvider provideHandlePageLatestRevisionChangedEvent */
	public function testHandlePageLatestRevisionChangedEvent(
		string $contentModelBefore,
		string $contentModelAfter,
		?string $expectedMethod
	) {
		if ( $expectedMethod !== null ) {
			$worklistSecondaryStore = $this->createMock( WorklistSecondaryStore::class );
			$worklistSecondaryStore->expects( $this->once() )->method( $expectedMethod );
		} else {
			$worklistSecondaryStore = $this->createNoOpMock( WorklistSecondaryStore::class );
		}

		$eventIngress = $this->getEventIngress( $worklistSecondaryStore );

		$revisionBefore = $this->mockRevisionWithContentModel( $contentModelBefore );
		$revisionAfter = $this->mockRevisionWithContentModel( $contentModelAfter );
		$event = $this->createMock( PageLatestRevisionChangedEvent::class );
		$event->method( 'getPageRecordBefore' )->willReturn( $this->createMock( ExistingPageRecord::class ) );
		$event->expects( $this->atLeastOnce() )->method( 'getLatestRevisionBefore' )->willReturn( $revisionBefore );
		$event->expects( $this->atLeastOnce() )->method( 'getLatestRevisionAfter' )->willReturn( $revisionAfter );

		$eventIngress->handlePageLatestRevisionChangedEvent( $event );
		DeferredUpdates::doUpdates();
	}

	public static function provideHandlePageLatestRevisionChangedEvent(): Generator {
		// Can't use the constant due to T428794, and data providers run before setUpBeforeClass
		$CONTENT_MODEL_WORKLIST = 'worklist';

		yield 'Was not worklist, is still not a worklist' => [ CONTENT_MODEL_WIKITEXT, CONTENT_MODEL_WIKITEXT, null ];
		yield 'Was not worklist, is now a worklist' => [
			CONTENT_MODEL_WIKITEXT,
			$CONTENT_MODEL_WORKLIST,
			'createWorklist'
		];
		yield 'Was worklist, is no longer a worklist' => [
			$CONTENT_MODEL_WORKLIST,
			CONTENT_MODEL_WIKITEXT,
			'deleteWorklist'
		];
		yield 'Was worklist, is still a worklist' => [ $CONTENT_MODEL_WORKLIST, $CONTENT_MODEL_WORKLIST, null ];
	}

	public function testHandlePageHistoryVisibilityChangedEvent__wrongContentModel() {
		$worklistSecondaryStore = $this->createNoOpMock( WorklistSecondaryStore::class );
		$titleFactory = $this->mockTitleFactoryWithContentModel( CONTENT_MODEL_WIKITEXT );
		$eventIngress = $this->getEventIngress( $worklistSecondaryStore, $titleFactory );

		$eventIngress->handlePageHistoryVisibilityChangedEvent(
			$this->createMock( PageHistoryVisibilityChangedEvent::class )
		);
	}

	public function testHandlePageHistoryVisibilityChangedEvent__unaffectedRevision() {
		$affectedIDs = range( 1, 100 );
		$unaffectedID = 999;

		$worklistSecondaryStore = $this->createNoOpMock( WorklistSecondaryStore::class );
		$titleFactory = $this->mockTitleFactoryWithContentModel( CONTENT_MODEL_WORKLIST );
		$revisionLookup = $this->mockRevisionLookupWithFirstRevisionID( $unaffectedID );
		$eventIngress = $this->getEventIngress( $worklistSecondaryStore, $titleFactory, $revisionLookup );

		$event = $this->createMock( PageHistoryVisibilityChangedEvent::class );
		$event->expects( $this->atLeastOnce() )->method( 'getAffectedRevisionIDs' )->willReturn( $affectedIDs );

		$eventIngress->handlePageHistoryVisibilityChangedEvent( $event );
	}

	/** @dataProvider provideHandlePageHistoryVisibilityChangedEvent */
	public function testHandlePageHistoryVisibilityChangedEvent(
		int $visibilityBefore,
		int $visibilityAfter,
		bool $expectsChange,
		?string $expectedNewName
	) {
		$affectedIDs = range( 1, 100 );
		$affectedID = 50;

		if ( $expectsChange ) {
			$worklistSecondaryStore = $this->createMock( WorklistSecondaryStore::class );
			$worklistSecondaryStore->expects( $this->once() )
				->method( 'updateWorklistCreatorName' )
				->with( $this->anything(), $this->anything(), $expectedNewName );
		} else {
			$worklistSecondaryStore = $this->createNoOpMock( WorklistSecondaryStore::class );
		}

		$titleFactory = $this->mockTitleFactoryWithContentModel( CONTENT_MODEL_WORKLIST );
		$revisionLookup = $this->mockRevisionLookupWithFirstRevisionID( $affectedID, $expectedNewName );
		$eventIngress = $this->getEventIngress( $worklistSecondaryStore, $titleFactory, $revisionLookup );

		$event = $this->createMock( PageHistoryVisibilityChangedEvent::class );
		$event->expects( $this->atLeastOnce() )->method( 'getAffectedRevisionIDs' )->willReturn( $affectedIDs );
		$event->expects( $this->atLeastOnce() )->method( 'getVisibilityBefore' )->willReturn( $visibilityBefore );
		$event->expects( $this->atLeastOnce() )->method( 'getVisibilityAfter' )->willReturn( $visibilityAfter );

		$eventIngress->handlePageHistoryVisibilityChangedEvent( $event );
		DeferredUpdates::doUpdates();
	}

	public static function provideHandlePageHistoryVisibilityChangedEvent(): Generator {
		yield 'Was fully visible, bits deleted but not author' => [ 0, RevisionRecord::DELETED_TEXT, false, null ];
		yield 'Was fully visible, author deleted' => [ 0, RevisionRecord::SUPPRESSED_ALL, true, null ];

		yield 'Had bits deleted but not author, has more bits deleted but still not author' => [
			RevisionRecord::DELETED_TEXT,
			RevisionRecord::DELETED_TEXT | RevisionRecord::DELETED_COMMENT,
			false,
			null,
		];
		yield 'Had bits deleted but not author, author deleted' => [
			RevisionRecord::DELETED_TEXT,
			RevisionRecord::SUPPRESSED_ALL,
			true,
			null,
		];

		yield 'Author was deleted, bits changed but author remains deleted' => [
			RevisionRecord::SUPPRESSED_USER,
			RevisionRecord::DELETED_USER | RevisionRecord::DELETED_TEXT,
			false,
			null,
		];
		yield 'Author was deleted, it no longer is but some bits remain deleted' => [
			RevisionRecord::SUPPRESSED_ALL,
			RevisionRecord::DELETED_COMMENT,
			true,
			'Username',
		];
		yield 'Author was deleted, now revision is fully visible' => [
			RevisionRecord::SUPPRESSED_ALL,
			0,
			true,
			'Username',
		];
	}
}
