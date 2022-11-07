<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Participants;

use Generator;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsAuthority;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\Notifications\UserNotifier;
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
	 * @return RegisterParticipantCommand
	 */
	private function getCommand(
		ParticipantsStore $participantsStore = null,
		PermissionChecker $permChecker = null,
		CampaignsCentralUserLookup $centralUserLookup = null
	): RegisterParticipantCommand {
		if ( !$permChecker ) {
			$permChecker = $this->createMock( PermissionChecker::class );
			$permChecker->method( 'userCanRegisterForEvents' )->willReturn( true );
		}
		return new RegisterParticipantCommand(
			$participantsStore ?? $this->createMock( ParticipantsStore::class ),
			$permChecker,
			$centralUserLookup ?? $this->createMock( CampaignsCentralUserLookup::class ),
			$this->createMock( UserNotifier::class )
		);
	}

	/**
	 * @return ExistingEventRegistration&MockObject
	 */
	private function getValidRegistration(): ExistingEventRegistration {
		$registration = $this->createMock( ExistingEventRegistration::class );
		$registration->method( 'getEndUTCTimestamp' )->willReturn( self::FUTURE_TIME );
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
			$this->createMock( ICampaignsAuthority::class ),
			RegisterParticipantCommand::REGISTRATION_PUBLIC
		);
		$this->assertInstanceOf( PermissionStatus::class, $status );
		$this->assertStatusNotGood( $status );
		$this->assertStatusMessage( 'campaignevents-register-not-allowed', $status );
	}

	/**
	 * @param ExistingEventRegistration $registration
	 * @param string $errMsg
	 * @covers ::registerIfAllowed
	 * @covers ::checkIsRegistrationAllowed
	 * @covers ::registerUnsafe
	 * @dataProvider provideInvalidRegistrationsAndErrors
	 */
	public function testRegisterIfAllowed__error(
		ExistingEventRegistration $registration,
		string $errMsg
	) {
		$status = $this->getCommand()->registerIfAllowed(
			$registration,
			$this->createMock( ICampaignsAuthority::class ),
			RegisterParticipantCommand::REGISTRATION_PUBLIC
		);
		$this->assertNotInstanceOf( PermissionStatus::class, $status );
		$this->assertStatusNotGood( $status );
		$this->assertStatusMessage( $errMsg, $status );
	}

	public function provideInvalidRegistrationsAndErrors(): Generator {
		$finishedRegistration = $this->createMock( ExistingEventRegistration::class );
		$finishedRegistration->method( 'getEndUTCTimestamp' )->willReturn( self::PAST_TIME );
		$finishedRegistration->method( 'getStatus' )->willReturn( EventRegistration::STATUS_OPEN );
		yield 'Already finished' => [ $finishedRegistration, 'campaignevents-register-event-past' ];

		$closedRegistration = $this->createMock( ExistingEventRegistration::class );
		$closedRegistration->method( 'getEndUTCTimestamp' )->willReturn( self::FUTURE_TIME );
		$closedRegistration->method( 'getStatus' )->willReturn( EventRegistration::STATUS_CLOSED );
		yield 'Not open' => [ $closedRegistration, 'campaignevents-register-event-not-open' ];

		$deletedRegistration = $this->getValidRegistration();
		$deletedRegistration->method( 'getDeletionTimestamp' )->willReturn( '1654000000' );
		yield 'Deleted' => [ $deletedRegistration, 'campaignevents-register-registration-deleted' ];
	}

	/**
	 * @param ParticipantsStore $store
	 * @param bool $isPrivate
	 * @param bool $expectedModified
	 * @covers ::registerIfAllowed
	 * @covers ::authorizeRegistration
	 * @covers ::checkIsRegistrationAllowed
	 * @covers ::registerUnsafe
	 * @dataProvider provideStoreAndModified
	 */
	public function testRegisterIfAllowed__successful(
		ParticipantsStore $store,
		bool $isPrivate,
		bool $expectedModified
	) {
		$status = $this->getCommand( $store )->registerIfAllowed(
			$this->getValidRegistration(),
			$this->createMock( ICampaignsAuthority::class ),
			$isPrivate ?
				RegisterParticipantCommand::REGISTRATION_PRIVATE :
				RegisterParticipantCommand::REGISTRATION_PUBLIC
		);
		$this->assertStatusGood( $status );
		$this->assertStatusValue( $expectedModified, $status );
	}

	/**
	 * @param ParticipantsStore $store
	 * @param bool $isPrivate
	 * @param bool $expectedModified
	 * @covers ::registerUnsafe
	 * @dataProvider provideStoreAndModified
	 */
	public function testRegisterUnsafe__successful(
		ParticipantsStore $store,
		bool $isPrivate,
		bool $expectedModified
	) {
		$status = $this->getCommand( $store )->registerUnsafe(
			$this->getValidRegistration(),
			$this->createMock( ICampaignsAuthority::class ),
			$isPrivate ?
				RegisterParticipantCommand::REGISTRATION_PRIVATE :
				RegisterParticipantCommand::REGISTRATION_PUBLIC
		);
		$this->assertStatusGood( $status );
		$this->assertStatusValue( $expectedModified, $status );
	}

	public function provideStoreAndModified(): Generator {
		foreach ( [ true, false ] as $isPrivate ) {
			$testDescription = $isPrivate ?
				RegisterParticipantCommand::REGISTRATION_PRIVATE :
				RegisterParticipantCommand::REGISTRATION_PUBLIC;
			$modifiedStore = $this->createMock( ParticipantsStore::class );
			$modifiedStore->method( 'addParticipantToEvent' )->willReturn( true );
			$modifiedStore->expects( $this->once() )
				->method( 'addParticipantToEvent' )
				->with( $this->anything(), $this->anything(), $isPrivate );
			yield "Modified, $testDescription" => [ $modifiedStore, $isPrivate, true ];

			$notModifiedStore = $this->createMock( ParticipantsStore::class );
			$notModifiedStore->method( 'addParticipantToEvent' )->willReturn( false );
			$notModifiedStore->expects( $this->once() )
				->method( 'addParticipantToEvent' )
				->with( $this->anything(), $this->anything(), $isPrivate );
			yield "Not modified, $testDescription" => [ $notModifiedStore, $isPrivate, false ];
		}
	}

	/**
	 * @param string $expectedMsg
	 * @param CampaignsCentralUserLookup|null $centralUserLookup
	 * @covers ::registerUnsafe
	 * @dataProvider provideRegisterUnsafeErrors
	 */
	public function testRegisterUnsafe__error(
		string $expectedMsg,
		CampaignsCentralUserLookup $centralUserLookup = null
	) {
		$status = $this->getCommand( null, null, $centralUserLookup )->registerUnsafe(
			$this->getValidRegistration(),
			$this->createMock( ICampaignsAuthority::class ),
			RegisterParticipantCommand::REGISTRATION_PUBLIC
		);
		$this->assertStatusNotGood( $status );
		$this->assertStatusMessage( $expectedMsg, $status );
	}

	public function provideRegisterUnsafeErrors(): Generator {
		$notGlobalLookup = $this->createMock( CampaignsCentralUserLookup::class );
		$notGlobalLookup->method( 'newFromAuthority' )
			->willThrowException( $this->createMock( UserNotGlobalException::class ) );
		yield 'User not global' => [
			'campaignevents-register-need-central-account',
			$notGlobalLookup
		];
	}
}
