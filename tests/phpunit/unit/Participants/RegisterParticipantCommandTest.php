<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Participants;

use Generator;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsUser;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Participants\RegisterParticipantCommand;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Permissions\PermissionStatus;
use MediaWikiUnitTestCase;
use MWTimestamp;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Participants\RegisterParticipantCommand
 * @covers ::__construct
 */
class RegisterParticipantCommandTest extends MediaWikiUnitTestCase {

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
	 * @return RegisterParticipantCommand
	 */
	private function getCommand(
		ParticipantsStore $participantsStore = null,
		PermissionChecker $permChecker = null
	): RegisterParticipantCommand {
		if ( !$permChecker ) {
			$permChecker = $this->createMock( PermissionChecker::class );
			$permChecker->method( 'userCanRegisterForEvents' )->willReturn( true );
		}
		return new RegisterParticipantCommand(
			$participantsStore ?? $this->createMock( ParticipantsStore::class ),
			$permChecker
		);
	}

	/**
	 * @return ExistingEventRegistration&MockObject
	 */
	private function getValidRegistration(): ExistingEventRegistration {
		$registration = $this->createMock( ExistingEventRegistration::class );
		$registration->method( 'getEndTimestamp' )->willReturn( (string)( self::TEST_TIME + 1 ) );
		$registration->method( 'getStatus' )->willReturn( EventRegistration::STATUS_OPEN );
		return $registration;
	}

	/**
	 * @covers ::registerIfAllowed
	 * @covers ::authorizeRegistration
	 */
	public function testRegisterIfAllowed__permissionError() {
		$permChecker = $this->createMock( PermissionChecker::class );
		$permChecker->expects( $this->once() )->method( 'userCanRegisterForEvents' )->willReturn( false );
		$status = $this->getCommand( null, $permChecker )->registerIfAllowed(
			$this->createMock( ExistingEventRegistration::class ),
			$this->createMock( ICampaignsUser::class )
		);
		$this->assertInstanceOf( PermissionStatus::class, $status );
		$this->assertStatusNotGood( $status );
		$this->assertStatusMessage( 'campaignevents-register-not-allowed', $status );
	}

	/**
	 * @param ExistingEventRegistration $registration
	 * @param string $errMsg
	 * @covers ::registerIfAllowed
	 * @covers ::registerUnsafe
	 * @dataProvider provideInvalidRegistrationsAndErrors
	 */
	public function testRegisterIfAllowed__error(
		ExistingEventRegistration $registration,
		string $errMsg
	) {
		$status = $this->getCommand()->registerIfAllowed(
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
		yield 'Already finished' => [ $finishedRegistration, 'campaignevents-register-event-past' ];

		$closedRegistration = $this->createMock( ExistingEventRegistration::class );
		$closedRegistration->method( 'getEndTimestamp' )->willReturn( (string)( self::TEST_TIME + 1 ) );
		$closedRegistration->method( 'getStatus' )->willReturn( EventRegistration::STATUS_CLOSED );
		yield 'Not open' => [ $closedRegistration, 'campaignevents-register-event-not-open' ];

		$deletedRegistration = $this->getValidRegistration();
		$deletedRegistration->method( 'getDeletionTimestamp' )->willReturn( '1654000000' );
		yield 'Deleted' => [ $deletedRegistration, 'campaignevents-register-registration-deleted' ];
	}

	/**
	 * @param ParticipantsStore $store
	 * @param bool $expectedModified
	 * @covers ::registerIfAllowed
	 * @covers ::authorizeRegistration
	 * @covers ::registerUnsafe
	 * @dataProvider provideStoreAndModified
	 */
	public function testRegisterIfAllowed__successful(
		ParticipantsStore $store,
		bool $expectedModified
	) {
		$status = $this->getCommand( $store )->registerIfAllowed(
			$this->getValidRegistration(),
			$this->createMock( ICampaignsUser::class )
		);
		$this->assertStatusGood( $status );
		$this->assertStatusValue( $expectedModified, $status );
	}

	/**
	 * @param ParticipantsStore $store
	 * @param bool $expectedModified
	 * @covers ::registerUnsafe
	 * @dataProvider provideStoreAndModified
	 */
	public function testRegisterUnsafe__successful(
		ParticipantsStore $store,
		bool $expectedModified
	) {
		$status = $this->getCommand( $store )->registerUnsafe(
			$this->getValidRegistration(),
			$this->createMock( ICampaignsUser::class )
		);
		$this->assertStatusGood( $status );
		$this->assertStatusValue( $expectedModified, $status );
	}

	public function provideStoreAndModified(): Generator {
		$modifiedStore = $this->createMock( ParticipantsStore::class );
		$modifiedStore->method( 'addParticipantToEvent' )->willReturn( true );
		yield 'Modified' => [ $modifiedStore, true ];

		$notModifiedStore = $this->createMock( ParticipantsStore::class );
		$notModifiedStore->method( 'addParticipantToEvent' )->willReturn( false );
		yield 'Not modified' => [ $notModifiedStore, false ];
	}
}
