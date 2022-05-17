<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Participants;

use Generator;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsUser;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Participants\UnregisterParticipantCommand;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Permissions\PermissionStatus;
use MediaWikiUnitTestCase;
use MWTimestamp;

/**
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Participants\UnregisterParticipantCommand
 * @covers ::__construct
 */
class UnregisterParticipantCommandTest extends MediaWikiUnitTestCase {

	private const TEST_TIME = 1646000000;

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
	 * @return UnregisterParticipantCommand
	 */
	private function getCommand(
		ParticipantsStore $participantsStore = null,
		PermissionChecker $permChecker = null
	): UnregisterParticipantCommand {
		if ( !$permChecker ) {
			$permChecker = $this->createMock( PermissionChecker::class );
			$permChecker->method( 'userCanUnregisterForEvents' )->willReturn( true );
		}
		return new UnregisterParticipantCommand(
			$participantsStore ?? $this->createMock( ParticipantsStore::class ),
			$permChecker
		);
	}

	private function getValidRegistration(): ExistingEventRegistration {
		$registration = $this->createMock( ExistingEventRegistration::class );
		$registration->method( 'getEndTimestamp' )->willReturn( (string)( self::TEST_TIME + 1 ) );
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
			$this->createMock( ICampaignsUser::class )
		);
		$this->assertInstanceOf( PermissionStatus::class, $status );
		$this->assertStatusNotGood( $status );
		$this->assertStatusMessage( 'campaignevents-unregister-not-allowed', $status );
	}

	/**
	 * @param ExistingEventRegistration $registration
	 * @param string $errMsg
	 * @covers ::unregisterIfAllowed
	 * @covers ::unregisterUnsafe
	 * @dataProvider provideInvalidRegistrationsAndErrors
	 */
	public function testUnregisterIfAllowed__error(
		ExistingEventRegistration $registration,
		string $errMsg
	) {
		$status = $this->getCommand()->unregisterIfAllowed(
			$registration,
			$this->createMock( ICampaignsUser::class )
		);
		$this->assertNotInstanceOf( PermissionStatus::class, $status );
		$this->assertStatusNotGood( $status );
		$this->assertStatusMessage( $errMsg, $status );
	}

	public function provideInvalidRegistrationsAndErrors(): Generator {
		$finishedRegistration = $this->createMock( ExistingEventRegistration::class );
		$finishedRegistration->method( 'getEndTimestamp' )->willReturn( (string)( self::TEST_TIME - 1 ) );
		$finishedRegistration->method( 'getStatus' )->willReturn( EventRegistration::STATUS_OPEN );
		yield 'Already finished' => [ $finishedRegistration, 'campaignevents-unregister-event-past' ];
	}

	/**
	 * @param ParticipantsStore $store
	 * @param bool $expectedModified
	 * @covers ::unregisterIfAllowed
	 * @covers ::unregisterUnsafe
	 * @dataProvider provideStoreAndModified
	 */
	public function testUnregisterIfAllowed__successful(
		ParticipantsStore $store,
		bool $expectedModified
	) {
		$status = $this->getCommand( $store )->unregisterIfAllowed(
			$this->getValidRegistration(),
			$this->createMock( ICampaignsUser::class )
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
			$this->createMock( ICampaignsUser::class )
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
	 * @covers ::unregisterUnsafe
	 */
	public function testCanUnregisterFromClosedEvent() {
		$closedEvent = $this->createMock( ExistingEventRegistration::class );
		$closedEvent->method( 'getStatus' )->willReturn( EventRegistration::STATUS_CLOSED );
		$closedEvent->method( 'getEndTimestamp' )->willReturn( (string)( self::TEST_TIME + 1 ) );
		$status = $this->getCommand()->unregisterUnsafe( $closedEvent, $this->createMock( ICampaignsUser::class ) );
		$this->assertStatusGood( $status );
	}
}
