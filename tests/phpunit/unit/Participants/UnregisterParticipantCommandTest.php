<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Participants;

use Generator;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\EventPage\EventPageCacheUpdater;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsAuthority;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Participants\UnregisterParticipantCommand;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolEventWatcher;
use MediaWiki\Permissions\PermissionStatus;
use MediaWikiUnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use StatusValue;

/**
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Participants\UnregisterParticipantCommand
 * @covers ::__construct
 */
class UnregisterParticipantCommandTest extends MediaWikiUnitTestCase {
	/**
	 * @param ParticipantsStore|null $participantsStore
	 * @param PermissionChecker|null $permChecker
	 * @param CampaignsCentralUserLookup|null $centralUserLookup
	 * @param TrackingToolEventWatcher|null $trackingToolEventWatcher
	 * @return UnregisterParticipantCommand
	 */
	private function getCommand(
		?ParticipantsStore $participantsStore = null,
		?PermissionChecker $permChecker = null,
		?CampaignsCentralUserLookup $centralUserLookup = null,
		?TrackingToolEventWatcher $trackingToolEventWatcher = null
	): UnregisterParticipantCommand {
		if ( !$participantsStore ) {
			$participantsStore = $this->createMock( ParticipantsStore::class );
			$participantsStore->method( 'removeParticipantsFromEvent' )
				->willReturn( [ 'public' => 0, 'private' => 0 ] );
		}
		if ( !$permChecker ) {
			$permChecker = $this->createMock( PermissionChecker::class );
			$permChecker->method( 'userCanCancelRegistration' )->willReturn( true );
			$permChecker->method( 'userCanRemoveParticipants' )->willReturn( true );
		}
		if ( !$trackingToolEventWatcher ) {
			$trackingToolEventWatcher = $this->createMock( TrackingToolEventWatcher::class );
			$trackingToolEventWatcher->method( 'validateParticipantsRemoved' )->willReturn( StatusValue::newGood() );
		}
		return new UnregisterParticipantCommand(
			$participantsStore,
			$permChecker,
			$centralUserLookup ?? $this->createMock( CampaignsCentralUserLookup::class ),
			$this->createMock( EventPageCacheUpdater::class ),
			$trackingToolEventWatcher
		);
	}

	/**
	 * @return ExistingEventRegistration&MockObject
	 */
	private function getValidRegistration(): ExistingEventRegistration {
		$registration = $this->createMock( ExistingEventRegistration::class );
		$registration->method( 'isPast' )->willReturn( false );
		return $registration;
	}

	/**
	 * @covers ::unregisterIfAllowed
	 * @covers ::authorizeUnregistration
	 */
	public function testUnregisterIfAllowed__permissionError() {
		$permChecker = $this->createMock( PermissionChecker::class );
		$permChecker->expects( $this->once() )->method( 'userCanCancelRegistration' )->willReturn( false );
		$status = $this->getCommand( null, $permChecker )->unregisterIfAllowed(
			$this->createMock( ExistingEventRegistration::class ),
			$this->createMock( ICampaignsAuthority::class )
		);
		$this->assertInstanceOf( PermissionStatus::class, $status );
		$this->assertStatusNotGood( $status );
		$this->assertStatusMessage( 'campaignevents-unregister-not-allowed', $status );
	}

	/**
	 * @covers ::unregisterIfAllowed
	 * @covers ::checkIsUnregistrationAllowed
	 * @covers ::unregisterUnsafe
	 */
	public function testUnregisterIfAllowed__deleted() {
		$deletedRegistration = $this->getValidRegistration();
		$deletedRegistration->method( 'getDeletionTimestamp' )->willReturn( '1654000000' );
		$status = $this->getCommand()->unregisterIfAllowed(
			$deletedRegistration,
			$this->createMock( ICampaignsAuthority::class )
		);
		$this->assertNotInstanceOf( PermissionStatus::class, $status );
		$this->assertStatusNotGood( $status );
		$this->assertStatusMessage( 'campaignevents-unregister-registration-deleted', $status );
	}

	/**
	 * @covers ::unregisterIfAllowed
	 * @covers ::authorizeUnregistration
	 * @covers ::checkIsUnregistrationAllowed
	 * @covers ::unregisterUnsafe
	 * @dataProvider provideModified
	 */
	public function testUnregisterIfAllowed__successful( bool $modified ) {
		$store = $this->createMock( ParticipantsStore::class );
		$store->method( 'removeParticipantFromEvent' )->willReturn( $modified );
		$status = $this->getCommand( $store )->unregisterIfAllowed(
			$this->getValidRegistration(),
			$this->createMock( ICampaignsAuthority::class )
		);
		$this->assertStatusGood( $status );
		$this->assertStatusValue( $modified, $status );
	}

	/**
	 * @covers ::unregisterUnsafe
	 * @dataProvider provideModified
	 */
	public function testUnregisterUnsafe__successful( bool $modified ) {
		$store = $this->createMock( ParticipantsStore::class );
		$store->method( 'removeParticipantFromEvent' )->willReturn( $modified );
		$status = $this->getCommand( $store )->unregisterUnsafe(
			$this->getValidRegistration(),
			$this->createMock( ICampaignsAuthority::class )
		);
		$this->assertStatusGood( $status );
		$this->assertStatusValue( $modified, $status );
	}

	public static function provideModified(): Generator {
		yield 'Modified' => [ true ];
		yield 'Not modified' => [ false ];
	}

	/**
	 * @param string $expectedMsg
	 * @param CampaignsCentralUserLookup|null $centralUserLookup
	 * @param TrackingToolEventWatcher|null $trackingToolEventWatcher
	 * @covers ::unregisterUnsafe
	 * @dataProvider provideUnregisterUnsafeErrors
	 */
	public function testUnregisterUnsafe__error(
		string $expectedMsg,
		?CampaignsCentralUserLookup $centralUserLookup = null,
		?TrackingToolEventWatcher $trackingToolEventWatcher = null
	) {
		$status = $this->getCommand( null, null, $centralUserLookup, $trackingToolEventWatcher )->unregisterUnsafe(
			$this->getValidRegistration(),
			$this->createMock( ICampaignsAuthority::class )
		);
		$this->assertStatusNotGood( $status );
		$this->assertStatusMessage( $expectedMsg, $status );
	}

	public function provideUnregisterUnsafeErrors(): Generator {
		$notGlobalLookup = $this->createMock( CampaignsCentralUserLookup::class );
		$notGlobalLookup->method( 'newFromAuthority' )
			->willThrowException( $this->createMock( UserNotGlobalException::class ) );
		yield 'User not global' => [
			'campaignevents-unregister-need-central-account',
			$notGlobalLookup
		];

		$trackingToolError = 'some-tracking-tool-error';
		$trackingToolWatcher = $this->createMock( TrackingToolEventWatcher::class );
		$trackingToolWatcher->expects( $this->atLeastOnce() )
			->method( 'validateParticipantsRemoved' )
			->willReturn( StatusValue::newFatal( $trackingToolError ) );
		yield 'Fails tracking tool validation' => [
			$trackingToolError,
			null,
			$trackingToolWatcher
		];
	}

	/**
	 * @covers ::unregisterUnsafe
	 */
	public function testCanUnregisterFromClosedEvent() {
		$closedEvent = $this->createMock( ExistingEventRegistration::class );
		$closedEvent->method( 'getStatus' )->willReturn( EventRegistration::STATUS_CLOSED );
		$closedEvent->method( 'isPast' )->willReturn( false );
		$status = $this->getCommand()->unregisterUnsafe(
			$closedEvent,
			$this->createMock( ICampaignsAuthority::class )
		);
		$this->assertStatusGood( $status );
	}

	/**
	 * @covers ::unregisterUnsafe
	 */
	public function testCanUnregisterFromFinishedEvent() {
		$finishedEvent = $this->createMock( ExistingEventRegistration::class );
		$finishedEvent->method( 'isPast' )->willReturn( true );
		$status = $this->getCommand()->unregisterUnsafe(
			$finishedEvent,
			$this->createMock( ICampaignsAuthority::class )
		);
		$this->assertStatusGood( $status );
	}

	/**
	 * @covers ::removeParticipantsIfAllowed
	 * @covers ::authorizeRemoveParticipants
	 */
	public function testRemoveParticipantsIfAllowed__permissionError() {
		$permChecker = $this->createMock( PermissionChecker::class );
		$permChecker->expects( $this->once() )->method( 'userCanRemoveParticipants' )->willReturn( false );

		$status = $this->getCommand( null, $permChecker )->removeParticipantsIfAllowed(
			$this->getValidRegistration(),
			[],
			$this->createMock( ICampaignsAuthority::class ),
			UnregisterParticipantCommand::INVERT_USERS
		);
		$this->assertInstanceOf( PermissionStatus::class, $status );
		$this->assertStatusNotGood( $status );
		$this->assertStatusMessage( 'campaignevents-unregister-participants-permission-denied', $status );
	}

	/**
	 * @covers ::removeParticipantsIfAllowed
	 * @covers ::authorizeRemoveParticipants
	 * @covers ::removeParticipantsUnsafe
	 */
	public function testRemoveParticipantsIfAllowed__deletedRegistration() {
		$deletedRegistration = $this->getValidRegistration();
		$deletedRegistration->method( 'getDeletionTimestamp' )->willReturn( '1654000000' );
		$status = $this->getCommand()->removeParticipantsIfAllowed(
			$deletedRegistration,
			[],
			$this->createMock( ICampaignsAuthority::class ),
			UnregisterParticipantCommand::INVERT_USERS
		);

		$this->assertNotInstanceOf( PermissionStatus::class, $status );
		$this->assertStatusNotGood( $status );
		$this->assertStatusMessage( 'campaignevents-unregister-participants-registration-deleted', $status );
	}

	/**
	 * @param bool $invertUsers
	 * @covers ::removeParticipantsIfAllowed
	 * @covers ::authorizeRemoveParticipants
	 * @covers ::removeParticipantsUnsafe
	 * @dataProvider provideDoRemoveParticipantsIfAllowed
	 */
	public function testRemoveParticipantsIfAllowed__success( bool $invertUsers ) {
		$status = $this->getCommand()->removeParticipantsIfAllowed(
			$this->getValidRegistration(),
			[],
			$this->createMock( ICampaignsAuthority::class ),
			$invertUsers
		);

		$this->assertNotInstanceOf( PermissionStatus::class, $status );
		$this->assertStatusGood( $status );
		$this->assertStatusOK( $status );
	}

	/**
	 * @return Generator
	 */
	public static function provideDoRemoveParticipantsIfAllowed(): Generator {
		yield 'Remove participants based on selected participants IDs' => [
			UnregisterParticipantCommand::DO_NOT_INVERT_USERS
		];
		yield 'Remove participants based on unselected participants IDs' => [
			UnregisterParticipantCommand::INVERT_USERS
		];
	}

	/**
	 * @param string $expectedMsg
	 * @param TrackingToolEventWatcher|null $trackingToolEventWatcher
	 * @covers ::removeParticipantsUnsafe
	 * @dataProvider provideRemoveParticipantsUnsafeErrors
	 */
	public function testRemoveParticipantsUnsafe__error(
		string $expectedMsg,
		?TrackingToolEventWatcher $trackingToolEventWatcher = null
	) {
		$cmd = $this->getCommand( null, null, null, $trackingToolEventWatcher );
		$status = $cmd->removeParticipantsUnsafe(
			$this->getValidRegistration(),
			[],
			UnregisterParticipantCommand::DO_NOT_INVERT_USERS
		);
		$this->assertStatusNotGood( $status );
		$this->assertStatusMessage( $expectedMsg, $status );
	}

	public function provideRemoveParticipantsUnsafeErrors(): Generator {
		$trackingToolError = 'some-tracking-tool-error';
		$trackingToolWatcher = $this->createMock( TrackingToolEventWatcher::class );
		$trackingToolWatcher->expects( $this->atLeastOnce() )
			->method( 'validateParticipantsRemoved' )
			->willReturn( StatusValue::newFatal( $trackingToolError ) );
		yield 'Fails tracking tool validation' => [
			$trackingToolError,
			$trackingToolWatcher
		];
	}
}
