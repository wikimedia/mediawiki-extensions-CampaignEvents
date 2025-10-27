<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Event;

use Generator;
use MediaWiki\Extension\CampaignEvents\Event\DeleteEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventStore;
use MediaWiki\Extension\CampaignEvents\EventPage\EventPageCacheUpdater;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWPermissionsLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageAuthorLookup;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolEventWatcher;
use MediaWiki\Permissions\Authority;
use MediaWiki\Permissions\PermissionStatus;
use MediaWikiUnitTestCase;
use StatusValue;

/**
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Event\DeleteEventCommand
 * @covers ::__construct
 */
class DeleteEventCommandTest extends MediaWikiUnitTestCase {

	/**
	 * @param IEventStore|null $eventStore
	 * @param PermissionChecker|null $permChecker
	 * @param TrackingToolEventWatcher|null $trackingToolEventWatcher
	 * @return DeleteEventCommand
	 */
	private function getCommand(
		?IEventStore $eventStore = null,
		?PermissionChecker $permChecker = null,
		?TrackingToolEventWatcher $trackingToolEventWatcher = null
	): DeleteEventCommand {
		if ( !$trackingToolEventWatcher ) {
			$trackingToolEventWatcher = $this->createMock( TrackingToolEventWatcher::class );
			$trackingToolEventWatcher->method( 'validateEventDeletion' )->willReturn( StatusValue::newGood() );
		}

		return new DeleteEventCommand(
			$eventStore ?? $this->createMock( IEventStore::class ),
			$permChecker ?? new PermissionChecker(
				$this->createMock( OrganizersStore::class ),
				$this->createMock( PageAuthorLookup::class ),
				$this->createMock( CampaignsCentralUserLookup::class ),
				$this->createMock( MWPermissionsLookup::class ),
				$this->createMock( ParticipantsStore::class )
			),
			$trackingToolEventWatcher,
			$this->createMock( EventPageCacheUpdater::class )
		);
	}

	/**
	 * @covers ::deleteIfAllowed
	 * @covers ::authorizeDeletion
	 */
	public function testDeleteIfAllowed__permissionError() {
		$permChecker = $this->createMock( PermissionChecker::class );
		$permChecker->expects( $this->once() )->method( 'userCanDeleteRegistration' )->willReturn( false );
		$status = $this->getCommand( null, $permChecker )->deleteIfAllowed(
			$this->createMock( ExistingEventRegistration::class ),
			$this->createMock( Authority::class )
		);
		$this->assertInstanceOf( PermissionStatus::class, $status );
		$this->assertStatusNotGood( $status );
		$this->assertStatusMessage( 'campaignevents-delete-not-allowed-registration', $status );
	}

	/**
	 * @covers ::deleteIfAllowed
	 * @covers ::authorizeDeletion
	 * @dataProvider provideRegistration
	 */
	public function testDeleteIfAllowed__successful(
		bool $alreadyDeleted,
		bool $expectedVal
	) {
		$registration = $this->createMock( ExistingEventRegistration::class );
		$store = $this->createMock( IEventStore::class );
		$store->method( 'deleteRegistration' )->with( $registration )->willReturn( !$alreadyDeleted );
		$permChecker = $this->createMock( PermissionChecker::class );
		$permChecker->expects( $this->once() )->method( 'userCanDeleteRegistration' )->willReturn( true );
		$status = $this->getCommand( $store, $permChecker )->deleteIfAllowed(
			$registration,
			$this->createMock( Authority::class )
		);
		$this->assertStatusGood( $status );
		$this->assertStatusValue( $expectedVal, $status );
	}

	/**
	 * @covers ::deleteUnsafe
	 * @dataProvider provideRegistration
	 */
	public function testDeleteUnsafe__successful(
		bool $alreadyDeleted,
		bool $expectedVal
	) {
		$registration = $this->createMock( ExistingEventRegistration::class );
		$store = $this->createMock( IEventStore::class );
		$store->method( 'deleteRegistration' )->with( $registration )->willReturn( !$alreadyDeleted );
		$status = $this->getCommand( $store )->deleteUnsafe( $registration );
		$this->assertStatusGood( $status );
		$this->assertStatusValue( $expectedVal, $status );
	}

	public static function provideRegistration(): Generator {
		yield 'Never deleted' => [ false, true ];
		yield 'Already deleted' => [ true, false ];
	}

	/**
	 * @covers ::deleteUnsafe
	 */
	public function testDeleteUnsafe__trackingToolError() {
		$trackingToolError = 'some-tracking-tool-error';
		$trackingToolEventWatcher = $this->createMock( TrackingToolEventWatcher::class );
		$trackingToolEventWatcher->expects( $this->atLeastOnce() )
			->method( 'validateEventDeletion' )
			->willReturn( StatusValue::newFatal( $trackingToolError ) );

		$cmd = $this->getCommand( null, null, $trackingToolEventWatcher );
		$status = $cmd->deleteUnsafe( $this->createMock( ExistingEventRegistration::class ) );
		$this->assertStatusNotGood( $status );
		$this->assertStatusMessage( $trackingToolError, $status );
	}
}
