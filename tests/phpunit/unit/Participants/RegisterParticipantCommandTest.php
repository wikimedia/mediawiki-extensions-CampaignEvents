<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Participants;

use Generator;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\EventPage\EventPageCacheUpdater;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsAuthority;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\Notifications\UserNotifier;
use MediaWiki\Extension\CampaignEvents\Participants\Participant;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Participants\RegisterParticipantCommand;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\Questions\Answer;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolEventWatcher;
use MediaWiki\Permissions\PermissionStatus;
use MediaWikiUnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use StatusValue;

/**
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Participants\RegisterParticipantCommand
 * @covers ::__construct
 */
class RegisterParticipantCommandTest extends MediaWikiUnitTestCase {
	/**
	 * @param ParticipantsStore|null $participantsStore
	 * @param PermissionChecker|null $permChecker
	 * @param CampaignsCentralUserLookup|null $centralUserLookup
	 * @param TrackingToolEventWatcher|null $trackingToolEventWatcher
	 * @return RegisterParticipantCommand
	 */
	private function getCommand(
		?ParticipantsStore $participantsStore = null,
		?PermissionChecker $permChecker = null,
		?CampaignsCentralUserLookup $centralUserLookup = null,
		?TrackingToolEventWatcher $trackingToolEventWatcher = null
	): RegisterParticipantCommand {
		if ( !$permChecker ) {
			$permChecker = $this->createMock( PermissionChecker::class );
			$permChecker->method( 'userCanRegisterForEvent' )->willReturn( true );
		}
		if ( !$trackingToolEventWatcher ) {
			$trackingToolEventWatcher = $this->createMock( TrackingToolEventWatcher::class );
			$trackingToolEventWatcher->method( 'validateParticipantAdded' )->willReturn( StatusValue::newGood() );
		}
		return new RegisterParticipantCommand(
			$participantsStore ?? $this->createMock( ParticipantsStore::class ),
			$permChecker,
			$centralUserLookup ?? $this->createMock( CampaignsCentralUserLookup::class ),
			$this->createMock( UserNotifier::class ),
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
		$registration->method( 'getStatus' )->willReturn( EventRegistration::STATUS_OPEN );
		return $registration;
	}

	/**
	 * @covers ::registerIfAllowed
	 * @covers ::authorizeRegistration
	 */
	public function testRegisterIfAllowed__permissionError() {
		$permChecker = $this->createMock( PermissionChecker::class );
		$permChecker->expects( $this->once() )->method( 'userCanRegisterForEvent' )->willReturn( false );
		$status = $this->getCommand( null, $permChecker )->registerIfAllowed(
			$this->createMock( ExistingEventRegistration::class ),
			$this->createMock( ICampaignsAuthority::class ),
			RegisterParticipantCommand::REGISTRATION_PUBLIC,
			[]
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
			RegisterParticipantCommand::REGISTRATION_PUBLIC,
			[]
		);
		$this->assertNotInstanceOf( PermissionStatus::class, $status );
		$this->assertStatusNotGood( $status );
		$this->assertStatusMessage( $errMsg, $status );
	}

	public function provideInvalidRegistrationsAndErrors(): Generator {
		$finishedRegistration = $this->createMock( ExistingEventRegistration::class );
		$finishedRegistration->method( 'isPast' )->willReturn( true );
		$finishedRegistration->method( 'getStatus' )->willReturn( EventRegistration::STATUS_OPEN );
		yield 'Already finished' => [ $finishedRegistration, 'campaignevents-register-event-past' ];

		$closedRegistration = $this->createMock( ExistingEventRegistration::class );
		$closedRegistration->method( 'isPast' )->willReturn( false );
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
				RegisterParticipantCommand::REGISTRATION_PUBLIC,
			[]
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
				RegisterParticipantCommand::REGISTRATION_PUBLIC,
			[]
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
			$modifiedStore->method( 'addParticipantToEvent' )->willReturn( ParticipantsStore::MODIFIED_REGISTRATION );
			$modifiedStore->expects( $this->once() )
				->method( 'addParticipantToEvent' )
				->with( $this->anything(), $this->anything(), $isPrivate );
			yield "Modified, $testDescription" => [ $modifiedStore, $isPrivate, true ];

			$notModifiedStore = $this->createMock( ParticipantsStore::class );
			$notModifiedStore->method( 'addParticipantToEvent' )->willReturn( ParticipantsStore::MODIFIED_NOTHING );
			$notModifiedStore->expects( $this->once() )
				->method( 'addParticipantToEvent' )
				->with( $this->anything(), $this->anything(), $isPrivate );
			yield "Not modified, $testDescription" => [ $notModifiedStore, $isPrivate, false ];
		}
	}

	/**
	 * @param string $expectedMsg
	 * @param CampaignsCentralUserLookup|null $centralUserLookup
	 * @param TrackingToolEventWatcher|null $trackingToolEventWatcher
	 * @param ParticipantsStore|null $participantsStore
	 * @param array $answers
	 * @covers ::registerUnsafe
	 * @dataProvider provideRegisterUnsafeErrors
	 */
	public function testRegisterUnsafe__error(
		string $expectedMsg,
		?CampaignsCentralUserLookup $centralUserLookup = null,
		?TrackingToolEventWatcher $trackingToolEventWatcher = null,
		?ParticipantsStore $participantsStore = null,
		array $answers = []
	) {
		$cmd = $this->getCommand( $participantsStore, null, $centralUserLookup, $trackingToolEventWatcher );
		$status = $cmd->registerUnsafe(
			$this->getValidRegistration(),
			$this->createMock( ICampaignsAuthority::class ),
			RegisterParticipantCommand::REGISTRATION_PUBLIC,
			$answers
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

		$participant = new Participant(
			new CentralUser( 1 ),
			'20200220202020',
			42,
			false,
			[],
			'20200220202020',
			'20200220202021',
		);
		$participantStore = $this->createMock( ParticipantsStore::class );
		$participantStore->method( 'getEventParticipant' )->willReturn( $participant );
		$participantStore->method( 'userHasAggregatedAnswers' )->willReturn( true );
		yield 'Active participant with already aggregated answers' => [
			'campaignevents-register-answers-aggregated-error',
			null,
			null,
			$participantStore,
			[ new Answer( 1, 1, null ) ]
		];

		$participantStore = $this->createMock( ParticipantsStore::class );
		$participantStore->method( 'getEventParticipant' )->willReturn( null );
		$participantStore->method( 'userHasAggregatedAnswers' )->willReturn( true );
		yield 'Previous participant with already aggregated answers' => [
			'campaignevents-register-answers-aggregated-error',
			null,
			null,
			$participantStore,
			[ new Answer( 1, 1, null ) ]
		];

		$trackingToolError = 'some-tracking-tool-error';
		$trackingToolWatcher = $this->createMock( TrackingToolEventWatcher::class );
		$trackingToolWatcher->expects( $this->atLeastOnce() )
			->method( 'validateParticipantAdded' )
			->willReturn( StatusValue::newFatal( $trackingToolError ) );
		yield 'Fails tracking tool validation' => [
			$trackingToolError,
			null,
			$trackingToolWatcher
		];
	}

	/**
	 * @dataProvider provideCheckIsRegistrationAllowed
	 * @covers ::checkIsRegistrationAllowed
	 */
	public function testCheckIsRegistrationAllowed(
		?string $deletionTS,
		bool $isPast,
		string $eventStatus,
		string $registrationType,
		int $expected
	): void {
		$event = $this->createMock( ExistingEventRegistration::class );
		$event->method( 'getDeletionTimestamp' )->willReturn( $deletionTS );
		$event->method( 'isPast' )->willReturn( $isPast );
		$event->method( 'getStatus' )->willReturn( $eventStatus );
		$this->assertSame(
			$expected,
			RegisterParticipantCommand::checkIsRegistrationAllowed( $event, $registrationType )->getValue()
		);
	}

	public static function provideCheckIsRegistrationAllowed(): Generator {
		$ts = '1733259876';
		yield 'New registration, deleted' => [
			$ts,
			false,
			EventRegistration::STATUS_OPEN,
			RegisterParticipantCommand::REGISTRATION_NEW,
			RegisterParticipantCommand::CANNOT_REGISTER_DELETED
		];
		yield 'New registration, past' => [
			null,
			true,
			EventRegistration::STATUS_OPEN,
			RegisterParticipantCommand::REGISTRATION_NEW,
			RegisterParticipantCommand::CANNOT_REGISTER_ENDED
		];
		yield 'New registration, closed' => [
			null,
			false,
			EventRegistration::STATUS_CLOSED,
			RegisterParticipantCommand::REGISTRATION_NEW,
			RegisterParticipantCommand::CANNOT_REGISTER_CLOSED
		];
		yield 'New registration, valid' => [
			null,
			false,
			EventRegistration::STATUS_OPEN,
			RegisterParticipantCommand::REGISTRATION_NEW,
			RegisterParticipantCommand::CAN_REGISTER
		];

		yield 'Edit registration, deleted' => [
			$ts,
			false,
			EventRegistration::STATUS_OPEN,
			RegisterParticipantCommand::REGISTRATION_EDIT,
			RegisterParticipantCommand::CANNOT_REGISTER_DELETED
		];
		yield 'Edit registration, past' => [
			null,
			true,
			EventRegistration::STATUS_OPEN,
			RegisterParticipantCommand::REGISTRATION_EDIT,
			RegisterParticipantCommand::CAN_REGISTER
		];
		yield 'Edit registration, closed' => [
			null,
			false,
			EventRegistration::STATUS_CLOSED,
			RegisterParticipantCommand::REGISTRATION_EDIT,
			RegisterParticipantCommand::CAN_REGISTER
		];
		yield 'Edit registration, valid' => [
			null,
			false,
			EventRegistration::STATUS_OPEN,
			RegisterParticipantCommand::REGISTRATION_EDIT,
			RegisterParticipantCommand::CAN_REGISTER
		];
	}
}
