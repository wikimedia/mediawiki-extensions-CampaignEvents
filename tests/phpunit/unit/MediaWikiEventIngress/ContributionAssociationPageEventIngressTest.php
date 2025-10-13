<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\MediaWikiEventIngress;

use Generator;
use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionStore;
use MediaWiki\Extension\CampaignEvents\EventContribution\UpdateContributionRecordsJob;
use MediaWiki\Extension\CampaignEvents\MediaWikiEventIngress\ContributionAssociationPageEventIngress;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\Page\Event\PageCreatedEvent;
use MediaWiki\Page\Event\PageDeletedEvent;
use MediaWiki\Page\Event\PageHistoryVisibilityChangedEvent;
use MediaWiki\Page\Event\PageMovedEvent;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Storage\PageUpdateCauses;
use MediaWiki\Title\TitleFormatter;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\CampaignEvents\MediaWikiEventIngress\ContributionAssociationPageEventIngress
 */
class ContributionAssociationPageEventIngressTest extends MediaWikiUnitTestCase {
	public function getEventIngress(
		EventContributionStore $eventContributionStore,
		JobQueueGroup $jobQueueGroup,
		?TitleFormatter $titleFormatter = null,
	): ContributionAssociationPageEventIngress {
		return new ContributionAssociationPageEventIngress(
			$eventContributionStore,
			$titleFormatter ?? $this->createMock( TitleFormatter::class ),
			$jobQueueGroup,
			new HashConfig( [ 'CampaignEventsEnableContributionTracking' => true ] )
		);
	}

	/** @dataProvider provideHandlePageDeletedEvent */
	public function testHandlePageDeletedEvent( bool $hasContributions ) {
		$eventContributionsStore = $this->createMock( EventContributionStore::class );
		$eventContributionsStore->expects( $this->once() )
			->method( 'hasContributionsForPage' )
			->willReturn( $hasContributions );

		$jobQueueGroup = $this->createMock( JobQueueGroup::class );
		$jobQueueGroup->expects( $hasContributions ? $this->once() : $this->never() )
			->method( 'push' )
			->willReturnCallback( function ( $job ) {
				$this->assertInstanceOf( UpdateContributionRecordsJob::class, $job );
				$this->assertSame( UpdateContributionRecordsJob::TYPE_DELETE, $job->getParams()['type'] );
			} );
		$eventIngress = $this->getEventIngress( $eventContributionsStore, $jobQueueGroup );

		$eventIngress->handlePageDeletedEvent( $this->createMock( PageDeletedEvent::class ) );
	}

	public static function provideHandlePageDeletedEvent(): Generator {
		yield 'No contributions' => [ false ];
		yield 'Has contributions' => [ true ];
	}

	/** @dataProvider provideHandlePageCreatedEvent */
	public function testHandlePageCreatedEvent(
		bool $eventIsUndeletion,
		bool $hasContributions,
		bool $expectsQuery,
		bool $expectsJob
	) {
		$eventContributionsStore = $this->createMock( EventContributionStore::class );
		$eventContributionsStore->expects( $expectsQuery ? $this->once() : $this->never() )
			->method( 'hasContributionsForPage' )
			->willReturn( $hasContributions );

		$jobQueueGroup = $this->createMock( JobQueueGroup::class );
		$jobQueueGroup->expects( $expectsJob ? $this->once() : $this->never() )
			->method( 'push' )
			->willReturnCallback( function ( $job ) {
				$this->assertInstanceOf( UpdateContributionRecordsJob::class, $job );
				$this->assertSame( UpdateContributionRecordsJob::TYPE_RESTORE, $job->getParams()['type'] );
			} );
		$eventIngress = $this->getEventIngress( $eventContributionsStore, $jobQueueGroup );

		$event = $this->createMock( PageCreatedEvent::class );
		$event->expects( $this->atLeastOnce() )
			->method( 'hasCause' )
			->with( PageUpdateCauses::CAUSE_UNDELETE )
			->willReturn( $eventIsUndeletion );
		$eventIngress->handlePageCreatedEvent( $event );
	}

	public static function provideHandlePageCreatedEvent(): Generator {
		yield 'Cause other than undeletion, no contributions' => [ false, false, false, false ];
		yield 'Cause other than undeletion, has contributions' => [ false, true, false, false ];
		yield 'Undeletion, no contributions' => [ true, false, true, false ];
		yield 'Undeletion, has contributions' => [ true, true, true, true ];
	}

	/** @dataProvider provideHandlePageMovedEvent */
	public function testHandlePageMovedEvent( bool $hasContributions ) {
		$eventContributionsStore = $this->createMock( EventContributionStore::class );
		$eventContributionsStore->expects( $this->once() )
			->method( 'hasContributionsForPage' )
			->willReturn( $hasContributions );

		$newPrefixedText = 'New prefixedtext';
		$titleFormatter = $this->createMock( TitleFormatter::class );
		$titleFormatter->method( 'getPrefixedText' )->willReturn( $newPrefixedText );

		$jobQueueGroup = $this->createMock( JobQueueGroup::class );
		$jobQueueGroup->expects( $hasContributions ? $this->once() : $this->never() )
			->method( 'push' )
			->willReturnCallback( function ( $job ) use ( $newPrefixedText ) {
				$this->assertInstanceOf( UpdateContributionRecordsJob::class, $job );
				$this->assertSame( UpdateContributionRecordsJob::TYPE_MOVE, $job->getParams()['type'] );
				$this->assertSame( $newPrefixedText, $job->getParams()['newPrefixedText'] );
			} );

		$eventIngress = $this->getEventIngress( $eventContributionsStore, $jobQueueGroup, $titleFormatter );

		$eventIngress->handlePageMovedEvent( $this->createMock( PageMovedEvent::class ) );
	}

	public static function provideHandlePageMovedEvent(): Generator {
		yield 'No contributions' => [ false ];
		yield 'Has contributions' => [ true ];
	}

	/** @dataProvider provideHandlePageHistoryVisibilityChangedEvent */
	public function testHandlePageHistoryVisibilityChangedEvent(
		array $revIDs,
		array $visibilitiesBefore,
		array $visibilitiesAfter,
		bool $hasContributions,
		bool $expectsQuery,
		bool $expectsJob,
		array $expectedDeleted,
		array $expectedRestored
	) {
		$eventContributionsStore = $this->createMock( EventContributionStore::class );
		$eventContributionsStore->expects( $expectsQuery ? $this->once() : $this->never() )
			->method( 'hasContributionsForPage' )
			->willReturn( $hasContributions );

		$jobQueueGroup = $this->createMock( JobQueueGroup::class );
		$jobQueueGroup->expects( $expectsJob ? $this->once() : $this->never() )
			->method( 'push' )
			->willReturnCallback( function ( $job ) use ( $expectedDeleted, $expectedRestored ) {
				$this->assertInstanceOf( UpdateContributionRecordsJob::class, $job );
				$this->assertSame( UpdateContributionRecordsJob::TYPE_REV_DELETE, $job->getParams()['type'] );
				$this->assertSame( $expectedDeleted, $job->getParams()['deletedRevIDs'] );
				$this->assertSame( $expectedRestored, $job->getParams()['restoredRevIDs'] );
			} );
		$eventIngress = $this->getEventIngress( $eventContributionsStore, $jobQueueGroup );

		$event = $this->createMock( PageHistoryVisibilityChangedEvent::class );
		$event->method( 'getAffectedRevisionIDs' )->willReturn( $revIDs );
		$event->method( 'getVisibilityBefore' )
			->willReturnCallback( static fn ( int $revID ) => $visibilitiesBefore[$revID] );
		$event->method( 'getVisibilityAfter' )
			->willReturnCallback( static fn ( int $revID ) => $visibilitiesAfter[$revID] );
		$eventIngress->handlePageHistoryVisibilityChangedEvent( $event );
	}

	public static function provideHandlePageHistoryVisibilityChangedEvent(): Generator {
		foreach ( [ false, true ] as $hasContributions ) {
			foreach ( [ false, true ] as $singleRevID ) {
				$revIDs = $singleRevID ? [ 42 ] : range( 1, 5 );

				$revDesc = $singleRevID ? 'Single revision' : 'Multiple revisions';
				$pageDesc = $hasContributions ? 'page has contributions' : 'page does not have contributions';
				yield "$revDesc, undeleted, $pageDesc" => [
					$revIDs,
					array_fill_keys( $revIDs, RevisionRecord::DELETED_TEXT ),
					array_fill_keys( $revIDs, 0 ),
					$hasContributions,
					true,
					$hasContributions,
					[],
					$revIDs,
				];
				yield "$revDesc, changed flags, $pageDesc" => [
					$revIDs,
					array_fill_keys( $revIDs, RevisionRecord::DELETED_TEXT ),
					array_fill_keys( $revIDs, RevisionRecord::SUPPRESSED_USER ),
					$hasContributions,
					false,
					false,
					[],
					[],
				];
				yield "$revDesc, newly deleted, $pageDesc" => [
					$revIDs,
					array_fill_keys( $revIDs, 0 ),
					array_fill_keys( $revIDs, RevisionRecord::DELETED_COMMENT ),
					$hasContributions,
					true,
					$hasContributions,
					$revIDs,
					[],
				];
			}

			yield "Mixed visibility changes, $pageDesc" => [
				range( 1, 5 ),
				[
					1 => 0,
					2 => RevisionRecord::DELETED_TEXT,
					3 => RevisionRecord::SUPPRESSED_USER,
					4 => 0,
					5 => RevisionRecord::SUPPRESSED_ALL,
				],
				[
					// Note, it's not actually possible to change different bits for different revision. We're only
					// doing that here to test multiple scenarios at once.
					1 => RevisionRecord::SUPPRESSED_USER,
					2 => RevisionRecord::DELETED_TEXT,
					3 => 0,
					4 => 0,
					5 => RevisionRecord::DELETED_RESTRICTED,
				],
				$hasContributions,
				true,
				$hasContributions,
				[ 1 ],
				[ 3 ],
			];
		}
	}
}
