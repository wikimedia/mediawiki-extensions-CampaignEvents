<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Participants;

use Generator;
use MediaWiki\DomainEvent\DomainEventDispatcher;
use MediaWiki\Extension\CampaignEvents\DomainEvent\ParticipantRegisteredEvent;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\EventPage\EventPageCacheUpdater;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\Notifications\UserNotifier;
use MediaWiki\Extension\CampaignEvents\Participants\Participant;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Participants\RegisterParticipantCommand;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\Questions\Answer;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolEventWatcher;
use MediaWiki\Permissions\Authority;
use MediaWiki\Permissions\PermissionStatus;
use MediaWiki\Tests\Unit\Permissions\MockAuthorityTrait;
use MediaWikiUnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use StatusValue;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * @covers \MediaWiki\Extension\CampaignEvents\Participants\RegisterParticipantCommand
 */
class RegisterParticipantCommandTest extends MediaWikiUnitTestCase {
	use MockAuthorityTrait;

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
		?TrackingToolEventWatcher $trackingToolEventWatcher = null,
		?DomainEventDispatcher $domainEventDispatcher = null,
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
			$trackingToolEventWatcher,
			$domainEventDispatcher ?? $this->createMock( DomainEventDispatcher::class ),
			$this->createMock( IConnectionProvider::class ),
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

	public function testRegisterIfAllowed__permissionError() {
		$permChecker = $this->createMock( PermissionChecker::class );
		$permChecker->expects( $this->once() )->method( 'userCanRegisterForEvent' )->willReturn( false );
		$status = $this->getCommand( null, $permChecker )->registerIfAllowed(
			$this->createMock( ExistingEventRegistration::class ),
			$this->createMock( Authority::class ),
			RegisterParticipantCommand::REGISTRATION_PUBLIC,
			[],
			RegisterParticipantCommand::SHOW_CONTRIBUTION_ASSOCIATION_PROMPT
		);
		$this->assertInstanceOf( PermissionStatus::class, $status );
		$this->assertStatusNotGood( $status );
		$this->assertStatusMessage( 'campaignevents-register-not-allowed', $status );
	}

	/**
	 * @dataProvider provideInvalidRegistrationsAndErrors
	 */
	public function testRegisterIfAllowed__error(
		string $errMsg,
		bool $isPast,
		string $status,
		?string $deletionTimestamp = null
	) {
		$registration = $this->createMock( ExistingEventRegistration::class );
		$registration->method( 'isPast' )->willReturn( $isPast );
		$registration->method( 'getStatus' )->willReturn( $status );
		$registration->method( 'getDeletionTimestamp' )->willReturn( $deletionTimestamp );
		$status = $this->getCommand()->registerIfAllowed(
			$registration,
			$this->createMock( Authority::class ),
			RegisterParticipantCommand::REGISTRATION_PUBLIC,
			[],
			RegisterParticipantCommand::SHOW_CONTRIBUTION_ASSOCIATION_PROMPT
		);
		$this->assertNotInstanceOf( PermissionStatus::class, $status );
		$this->assertStatusNotGood( $status );
		$this->assertStatusMessage( $errMsg, $status );
	}

	public static function provideInvalidRegistrationsAndErrors(): Generator {
		yield 'Already finished' => [ 'campaignevents-register-event-past', true, EventRegistration::STATUS_OPEN ];
		yield 'Not open' => [ 'campaignevents-register-event-not-open', false, EventRegistration::STATUS_CLOSED ];
		yield 'Deleted' => [
			'campaignevents-register-registration-deleted',
			false,
			EventRegistration::STATUS_OPEN,
			'1654000000'
		];
	}

	private function makeDomainEventDispatcher(
		int $modified,
		ExistingEventRegistration $event,
		Authority $performer,
		CentralUser $centralUser,
		bool $isPrivate,
	): DomainEventDispatcher {
		if ( $modified !== ParticipantsStore::MODIFIED_REGISTRATION ) {
			return $this->createNoOpMock( DomainEventDispatcher::class );
		}

		$domainEventDispatcher = $this->createMock( DomainEventDispatcher::class );
		$domainEventDispatcher->expects( $this->once() )
			->method( 'dispatch' )
			->willReturnCallback(
				function ( ParticipantRegisteredEvent $domainEvent ) use (
					$event, $performer, $centralUser, $isPrivate
				) {
					$this->assertSame( $event, $domainEvent->getEvent() );
					$this->assertSame( $performer->getUser(), $domainEvent->getParticipant() );
					$this->assertSame( $centralUser, $domainEvent->getParticipantCentralUser() );
					$this->assertSame( $isPrivate, $domainEvent->isPrivateParticipant() );
				}
			);
		return $domainEventDispatcher;
	}

	/**
	 * @dataProvider provideSuccessfulCases
	 */
	public function testRegisterIfAllowed__successful(
		int $modified,
		bool $isPrivate,
		string $contributionAssociationMode,
		bool $expectedModified
	) {
		$event = $this->getValidRegistration();
		$performer = $this->mockRegisteredUltimateAuthority();
		$centralUser = new CentralUser( 12347 );

		$store = $this->createMock( ParticipantsStore::class );
		$store->method( 'addParticipantToEvent' )->willReturn( $modified );
		$store->expects( $this->once() )
			->method( 'addParticipantToEvent' )
			->with( $this->anything(), $this->anything(), $isPrivate );

		$centralUserLookup = $this->createMock( CampaignsCentralUserLookup::class );
		$centralUserLookup->expects( $this->once() )
			->method( 'newFromAuthority' )
			->with( $performer )
			->willReturn( $centralUser );

		$domainEventDispatcher = $this->makeDomainEventDispatcher(
			$modified, $event, $performer, $centralUser, $isPrivate
		);

		$command = $this->getCommand(
			$store,
			centralUserLookup: $centralUserLookup,
			domainEventDispatcher: $domainEventDispatcher
		);
		$status = $command->registerIfAllowed(
			$event,
			$performer,
			$isPrivate ?
				RegisterParticipantCommand::REGISTRATION_PRIVATE :
				RegisterParticipantCommand::REGISTRATION_PUBLIC,
			[],
			$contributionAssociationMode
		);
		$this->assertStatusGood( $status );
		$this->assertStatusValue( $expectedModified, $status );
	}

	/**
	 * @dataProvider provideSuccessfulCases
	 */
	public function testRegisterUnsafe__successful(
		int $modified,
		bool $isPrivate,
		string $contributionAssociationMode,
		bool $expectedModified
	) {
		$event = $this->getValidRegistration();
		$performer = $this->mockRegisteredUltimateAuthority();
		$centralUser = new CentralUser( 12347 );

		$store = $this->createMock( ParticipantsStore::class );
		$store->expects( $this->once() )
			->method( 'addParticipantToEvent' )
			->with( $this->anything(), $this->anything(), $isPrivate )
			->willReturn( $modified );

		$centralUserLookup = $this->createMock( CampaignsCentralUserLookup::class );
		$centralUserLookup->expects( $this->once() )
			->method( 'newFromAuthority' )
			->with( $performer )
			->willReturn( $centralUser );

		$domainEventDispatcher = $this->makeDomainEventDispatcher(
			$modified, $event, $performer, $centralUser, $isPrivate
		);

		$command = $this->getCommand(
			$store,
			centralUserLookup: $centralUserLookup,
			domainEventDispatcher: $domainEventDispatcher
		);
		$status = $command->registerUnsafe(
			$event,
			$performer,
			$isPrivate ?
				RegisterParticipantCommand::REGISTRATION_PRIVATE :
				RegisterParticipantCommand::REGISTRATION_PUBLIC,
			[],
			$contributionAssociationMode
		);
		$this->assertStatusGood( $status );
		$this->assertStatusValue( $expectedModified, $status );
	}

	public static function provideSuccessfulCases(): Generator {
		$hidePrompt = RegisterParticipantCommand::HIDE_CONTRIBUTION_ASSOCIATION_PROMPT;
		$showPrompt = RegisterParticipantCommand::SHOW_CONTRIBUTION_ASSOCIATION_PROMPT;
		foreach ( [ true, false ] as $isPrivate ) {
			foreach ( [ $showPrompt, $hidePrompt ] as $contributionAssociationMode ) {
				$extraTestDescription = ( $isPrivate ? 'private' : 'public' ) . ', ';
				$extraTestDescription .= $contributionAssociationMode === $hidePrompt
					? 'hide contrib prompt'
					: 'show contrib prompt';
				yield "Modified, $extraTestDescription" => [
					ParticipantsStore::MODIFIED_REGISTRATION,
					$isPrivate,
					$contributionAssociationMode,
					true
				];
				yield "Not modified, $extraTestDescription" => [
					ParticipantsStore::MODIFIED_NOTHING,
					$isPrivate,
					$contributionAssociationMode,
					false
				];
			}
		}
	}

	private function doTestRegisterUnsafeExpectingError(
		string $expectedMsg,
		?CampaignsCentralUserLookup $centralUserLookup = null,
		?TrackingToolEventWatcher $trackingToolEventWatcher = null,
		?ParticipantsStore $participantsStore = null,
		array $answers = []
	) {
		$cmd = $this->getCommand( $participantsStore, null, $centralUserLookup, $trackingToolEventWatcher );
		$status = $cmd->registerUnsafe(
			$this->getValidRegistration(),
			$this->createMock( Authority::class ),
			RegisterParticipantCommand::REGISTRATION_PUBLIC,
			$answers,
			RegisterParticipantCommand::SHOW_CONTRIBUTION_ASSOCIATION_PROMPT
		);
		$this->assertStatusNotGood( $status );
		$this->assertStatusMessage( $expectedMsg, $status );
	}

	public function testRegisterUnsafe__userNotGlobal() {
		$notGlobalLookup = $this->createMock( CampaignsCentralUserLookup::class );
		$notGlobalLookup->method( 'newFromAuthority' )
			->willThrowException( $this->createMock( UserNotGlobalException::class ) );
		$this->doTestRegisterUnsafeExpectingError(
			'campaignevents-register-need-central-account',
			$notGlobalLookup,
		);
	}

	/** @dataProvider provideRegisterUnsafe__answersAlreadyAggregated */
	public function testRegisterUnsafe__answersAlreadyAggregated( ?Participant $storedParticipant ) {
		$participantStore = $this->createMock( ParticipantsStore::class );
		$participantStore->method( 'getEventParticipant' )->willReturn( $storedParticipant );
		$participantStore->method( 'userHasAggregatedAnswers' )->willReturn( true );
		$this->doTestRegisterUnsafeExpectingError(
			'campaignevents-register-answers-aggregated-error',
			null,
			null,
			$participantStore,
			[ new Answer( 1, 1, null ) ]
		);
	}

	public static function provideRegisterUnsafe__answersAlreadyAggregated() {
		$participant = new Participant(
			new CentralUser( 1 ),
			'20200220202020',
			42,
			false,
			[],
			'20200220202020',
			'20200220202021',
			false,
		);
		yield 'Active participant with already aggregated answers' => [ $participant ];
		yield 'Previous participant with already aggregated answers' => [ null ];
	}

	public function testRegisterUnsafe__invalidTrackingTools() {
		$trackingToolError = 'some-tracking-tool-error';
		$trackingToolWatcher = $this->createMock( TrackingToolEventWatcher::class );
		$trackingToolWatcher->expects( $this->atLeastOnce() )
			->method( 'validateParticipantAdded' )
			->willReturn( StatusValue::newFatal( $trackingToolError ) );
		$this->doTestRegisterUnsafeExpectingError(
			$trackingToolError,
			null,
			$trackingToolWatcher
		);
	}

	/**
	 * @dataProvider provideCheckIsRegistrationAllowed
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
