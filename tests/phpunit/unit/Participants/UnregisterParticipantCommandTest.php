<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Participants;

use Generator;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsAuthority;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Participants\UnregisterParticipantCommand;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Permissions\PermissionStatus;
use MediaWikiUnitTestCase;
use MWTimestamp;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Participants\UnregisterParticipantCommand
 * @covers ::__construct
 */
class UnregisterParticipantCommandTest extends MediaWikiUnitTestCase {

	private const TEST_TIME = '20220227120000';
	private const PAST_TIME = '20220227100000';
	private const FUTURE_TIME = '20220227150000';

	/**
	 * @inheritDoc
	 */
	protected function setUp(): void {
		parent::setUp();
		MWTimestamp::setFakeTime( self::TEST_TIME );
	}

	/**
	 * @param ParticipantsStore|null $participantsStore
	 * @param PermissionChecker|null $permChecker
	 * @param CampaignsCentralUserLookup|null $centralUserLookup
	 * @return UnregisterParticipantCommand
	 */
	private function getCommand(
		ParticipantsStore $participantsStore = null,
		PermissionChecker $permChecker = null,
		CampaignsCentralUserLookup $centralUserLookup = null
	): UnregisterParticipantCommand {
		if ( !$permChecker ) {
			$permChecker = $this->createMock( PermissionChecker::class );
			$permChecker->method( 'userCanUnregisterForEvents' )->willReturn( true );
			$permChecker->method( 'userCanRemoveParticipants' )->willReturn( true );
		}
		return new UnregisterParticipantCommand(
			$participantsStore ?? $this->createMock( ParticipantsStore::class ),
			$permChecker,
			$centralUserLookup ?? $this->createMock( CampaignsCentralUserLookup::class )
		);
	}

	/**
	 * @return ExistingEventRegistration&MockObject
	 */
	private function getValidRegistration(): ExistingEventRegistration {
		$registration = $this->createMock( ExistingEventRegistration::class );
		$registration->method( 'getEndUTCTimestamp' )->willReturn( self::FUTURE_TIME );
		return $registration;
	}

	/**
	 * @covers ::unregisterIfAllowed
	 * @covers ::authorizeUnregistration
	 */
	public function testUnregisterIfAllowed__permissionError() {
		$permChecker = $this->createMock( PermissionChecker::class );
		$permChecker->expects( $this->once() )->method( 'userCanUnregisterForEvents' )->willReturn( false );
		$status = $this->getCommand( null, $permChecker )->unregisterIfAllowed(
			$this->createMock( ExistingEventRegistration::class ),
			$this->createMock( ICampaignsAuthority::class )
		);
		$this->assertInstanceOf( PermissionStatus::class, $status );
		$this->assertStatusNotGood( $status );
		$this->assertStatusMessage( 'campaignevents-unregister-not-allowed', $status );
	}

	/**
	 * @param ExistingEventRegistration $registration
	 * @param string $errMsg
	 * @covers ::unregisterIfAllowed
	 * @covers ::checkIsUnregistrationAllowed
	 * @covers ::unregisterUnsafe
	 * @dataProvider provideInvalidRegistrationsAndErrors
	 */
	public function testUnregisterIfAllowed__error(
		ExistingEventRegistration $registration,
		string $errMsg
	) {
		$status = $this->getCommand()->unregisterIfAllowed(
			$registration,
			$this->createMock( ICampaignsAuthority::class )
		);
		$this->assertNotInstanceOf( PermissionStatus::class, $status );
		$this->assertStatusNotGood( $status );
		$this->assertStatusMessage( $errMsg, $status );
	}

	public function provideInvalidRegistrationsAndErrors(): Generator {
		$finishedRegistration = $this->createMock( ExistingEventRegistration::class );
		$finishedRegistration->method( 'getEndUTCTimestamp' )->willReturn( self::PAST_TIME );
		$finishedRegistration->method( 'getStatus' )->willReturn( EventRegistration::STATUS_OPEN );
		yield 'Already finished' => [ $finishedRegistration, 'campaignevents-unregister-event-past' ];

		$deletedRegistration = $this->getValidRegistration();
		$deletedRegistration->method( 'getDeletionTimestamp' )->willReturn( '1654000000' );
		yield 'Deleted' => [ $deletedRegistration, 'campaignevents-unregister-registration-deleted' ];
	}

	/**
	 * @param ParticipantsStore $store
	 * @param bool $expectedModified
	 * @covers ::unregisterIfAllowed
	 * @covers ::authorizeUnregistration
	 * @covers ::checkIsUnregistrationAllowed
	 * @covers ::unregisterUnsafe
	 * @dataProvider provideStoreAndModified
	 */
	public function testUnregisterIfAllowed__successful(
		ParticipantsStore $store,
		bool $expectedModified
	) {
		$status = $this->getCommand( $store )->unregisterIfAllowed(
			$this->getValidRegistration(),
			$this->createMock( ICampaignsAuthority::class )
		);
		$this->assertStatusGood( $status );
		$this->assertStatusValue( $expectedModified, $status );
	}

	/**
	 * @param ParticipantsStore $store
	 * @param bool $expectedModified
	 * @covers ::unregisterUnsafe
	 * @dataProvider provideStoreAndModified
	 */
	public function testUnregisterUnsafe__successful(
		ParticipantsStore $store,
		bool $expectedModified
	) {
		$status = $this->getCommand( $store )->unregisterUnsafe(
			$this->getValidRegistration(),
			$this->createMock( ICampaignsAuthority::class )
		);
		$this->assertStatusGood( $status );
		$this->assertStatusValue( $expectedModified, $status );
	}

	public function provideStoreAndModified(): Generator {
		$modifiedStore = $this->createMock( ParticipantsStore::class );
		$modifiedStore->method( 'removeParticipantFromEvent' )->willReturn( true );
		yield 'Modified' => [ $modifiedStore, true ];

		$notModifiedStore = $this->createMock( ParticipantsStore::class );
		$notModifiedStore->method( 'removeParticipantFromEvent' )->willReturn( false );
		yield 'Not modified' => [ $notModifiedStore, false ];
	}

	/**
	 * @param string $expectedMsg
	 * @param CampaignsCentralUserLookup|null $centralUserLookup
	 * @covers ::unregisterUnsafe
	 * @dataProvider provideUnregisterUnsafeErrors
	 */
	public function testUnregisterUnsafe__error(
		string $expectedMsg,
		CampaignsCentralUserLookup $centralUserLookup = null
	) {
		$status = $this->getCommand( null, null, $centralUserLookup )->unregisterUnsafe(
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
	}

	/**
	 * @covers ::unregisterUnsafe
	 */
	public function testCanUnregisterFromClosedEvent() {
		$closedEvent = $this->createMock( ExistingEventRegistration::class );
		$closedEvent->method( 'getStatus' )->willReturn( EventRegistration::STATUS_CLOSED );
		$closedEvent->method( 'getEndUTCTimestamp' )->willReturn( self::FUTURE_TIME );
		$status = $this->getCommand()->unregisterUnsafe(
			$closedEvent,
			$this->createMock( ICampaignsAuthority::class )
		);
		$this->assertStatusGood( $status );
	}

	/**
	 * @covers ::removeParticipantsIfAllowed
	 * @covers ::authorizeRemoveParticipants
	 */
	public function testDoRemoveParticipantsIfAllowed__permissionError() {
		$permChecker = $this->createMock( PermissionChecker::class );
		$permChecker->expects( $this->once() )->method( 'userCanRemoveParticipants' )->willReturn( false );

		$status = $this->getCommand( null, $permChecker )->removeParticipantsIfAllowed(
			$this->getValidRegistration(),
			[],
			$this->createMock( ICampaignsAuthority::class )
		);
		$this->assertInstanceOf( PermissionStatus::class, $status );
		$this->assertStatusNotGood( $status );
		$this->assertStatusMessage( 'campaignevents-unregister-participants-permission-denied', $status );
	}

	/**
	 * @param ExistingEventRegistration $registration
	 * @param string $expectedStatusMessage
	 * @covers ::removeParticipantsIfAllowed
	 * @covers ::authorizeRemoveParticipants
	 * @covers ::removeParticipantsUnsafe
	 * @dataProvider provideDoRemoveParticipantsIfAllowedError
	 */
	public function testDoRemoveParticipantsIfAllowed__error(
		ExistingEventRegistration $registration,
		string $expectedStatusMessage
	) {
		$status = $this->getCommand()->removeParticipantsIfAllowed(
			$registration,
			[],
			$this->createMock( ICampaignsAuthority::class )
		);

		$this->assertNotInstanceOf( PermissionStatus::class, $status );
		$this->assertStatusNotGood( $status );
		$this->assertStatusMessage( $expectedStatusMessage, $status );
	}

	public function provideDoRemoveParticipantsIfAllowedError(): Generator {
		$pastRegistration = $this->createMock( ExistingEventRegistration::class );
		$pastRegistration->method( 'getEndUTCTimestamp' )->willReturn( self::PAST_TIME );
		yield 'Registration in the past' => [
			$pastRegistration,
			'campaignevents-unregister-participants-past-registration'
		];

		$deletedRegistration = $this->getValidRegistration();
		$deletedRegistration->method( 'getDeletionTimestamp' )->willReturn( '1654000000' );
		yield 'Registration deleted' => [
			$deletedRegistration,
			'campaignevents-unregister-participants-registration-deleted'
		];
	}

	/**
	 * @covers ::removeParticipantsIfAllowed
	 * @covers ::authorizeRemoveParticipants
	 * @covers ::removeParticipantsUnsafe
	 */
	public function testDoRemoveParticipantsIfAllowed__success() {
		$status = $this->getCommand()->removeParticipantsIfAllowed(
			$this->getValidRegistration(),
			[],
			$this->createMock( ICampaignsAuthority::class )
		);

		$this->assertNotInstanceOf( PermissionStatus::class, $status );
		$this->assertStatusGood( $status );
		$this->assertStatusOK( $status );
	}
}
