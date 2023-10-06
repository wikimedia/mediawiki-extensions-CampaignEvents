<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Event;

use Generator;
use MediaWiki\Extension\CampaignEvents\Event\EditEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventNotFoundException;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventStore;
use MediaWiki\Extension\CampaignEvents\EventPage\EventPageCacheUpdater;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsAuthority;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\Organizers\Organizer;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Organizers\Roles;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\Questions\EventAggregatedAnswersStore;
use MediaWiki\Extension\CampaignEvents\Questions\ParticipantAnswersStore;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolAssociation;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolEventWatcher;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolUpdater;
use MediaWiki\Permissions\PermissionStatus;
use MediaWikiUnitTestCase;
use MWTimestamp;
use Psr\Log\NullLogger;
use StatusValue;

/**
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Event\EditEventCommand
 * @covers ::__construct
 */
class EditEventCommandTest extends MediaWikiUnitTestCase {

	private const ORGANIZER_USERNAMES = [ 'organizerA', 'organizerB' ];

	private const FAKE_TIME = 123456789;

	/**
	 * @inheritDoc
	 */
	protected function setUp(): void {
		parent::setUp();
		MWTimestamp::setFakeTime( self::FAKE_TIME );
	}

	/**
	 * @param IEventStore|null $eventStore
	 * @param PermissionChecker|null $permChecker
	 * @param IEventLookup|null $eventLookup
	 * @param CampaignsCentralUserLookup|null $centralUserLookup
	 * @param OrganizersStore|null $organizersStore
	 * @param TrackingToolEventWatcher|null $trackingToolEventWatcher
	 * @param TrackingToolUpdater|null $trackingToolUpdater
	 * @param ParticipantAnswersStore|null $participantAnswersStore
	 * @param EventAggregatedAnswersStore|null $eventAggregatedAnswersStore
	 * @return EditEventCommand
	 */
	private function getCommand(
		IEventStore $eventStore = null,
		PermissionChecker $permChecker = null,
		IEventLookup $eventLookup = null,
		CampaignsCentralUserLookup $centralUserLookup = null,
		OrganizersStore $organizersStore = null,
		TrackingToolEventWatcher $trackingToolEventWatcher = null,
		TrackingToolUpdater $trackingToolUpdater = null,
		ParticipantAnswersStore $participantAnswersStore = null,
		EventAggregatedAnswersStore $eventAggregatedAnswersStore = null
	): EditEventCommand {
		$eventStore ??= $this->createMock( IEventStore::class );
		if ( !$eventLookup ) {
			$eventLookup = $this->createMock( IEventLookup::class );
			$eventLookup->method( 'getEventByPage' )
				->willThrowException( $this->createMock( EventNotFoundException::class ) );
		}
		if ( !$permChecker ) {
			$permChecker = $this->createMock( PermissionChecker::class );
			$permChecker->method( 'userCanEnableRegistration' )->willReturn( true );
			$permChecker->method( 'userCanEditRegistration' )->willReturn( true );
			$permChecker->method( 'userCanOrganizeEvents' )->willReturn( true );
		}

		if ( !$organizersStore ) {
			$organizersStore = $this->createMock( OrganizersStore::class );
			$organizersStore->method( 'getEventCreator' )->willReturn( $this->createMock( Organizer::class ) );
		}

		if ( !$centralUserLookup ) {
			$centralUserLookup = $this->createMock( CampaignsCentralUserLookup::class );
			$centralUserLookup->method( 'isValidLocalUsername' )->willReturn( true );
			$centralUserLookup->method( 'existsAndIsVisible' )->willReturn( true );
		}

		if ( !$trackingToolEventWatcher ) {
			$trackingToolEventWatcher = $this->createMock( TrackingToolEventWatcher::class );
			$trackingToolEventWatcher->method( 'validateEventCreation' )->willReturn( StatusValue::newGood() );
			$trackingToolEventWatcher->method( 'validateEventUpdate' )->willReturn( StatusValue::newGood() );
			$trackingToolEventWatcher->method( 'onEventCreated' )->willReturn( StatusValue::newGood( [] ) );
			$trackingToolEventWatcher->method( 'onEventUpdated' )->willReturn( StatusValue::newGood( [] ) );
		}

		return new EditEventCommand(
			$eventStore,
			$eventLookup,
			$organizersStore,
			$permChecker,
			$centralUserLookup,
			$this->createMock( EventPageCacheUpdater::class ),
			$trackingToolEventWatcher,
			$trackingToolUpdater ?? $this->createMock( TrackingToolUpdater::class ),
			new NullLogger(),
			$participantAnswersStore ?? $this->createMock( ParticipantAnswersStore::class ),
			$eventAggregatedAnswersStore ?? $this->createMock( EventAggregatedAnswersStore::class ),
		);
	}

	/**
	 * @param EventRegistration $registration
	 * @covers ::doEditIfAllowed
	 * @covers ::authorizeEdit
	 * @dataProvider provideEventRegistrations
	 */
	public function testDoEditIfAllowed__permissionError( EventRegistration $registration ) {
		$isCreation = $registration->getID() === null;
		$permChecker = $this->createMock( PermissionChecker::class );
		$permMethod = $isCreation ? 'userCanEnableRegistration' : 'userCanEditRegistration';
		$permChecker->expects( $this->once() )->method( $permMethod )->willReturn( false );
		$status = $this->getCommand( null, $permChecker )->doEditIfAllowed(
			$registration,
			$this->createMock( ICampaignsAuthority::class ),
			self::ORGANIZER_USERNAMES
		);
		$this->assertInstanceOf( PermissionStatus::class, $status );
		$this->assertStatusNotGood( $status );
		$expectedMsg = $isCreation
			? 'campaignevents-enable-registration-not-allowed-page'
			: 'campaignevents-edit-not-allowed-registration';
		$this->assertStatusMessage( $expectedMsg, $status );
	}

	/**
	 * @param IEventLookup $eventLookup
	 * @param int $existingRegistrationID
	 * @param string $expectedMsg
	 * @covers ::doEditIfAllowed
	 * @dataProvider providePageWithRegistrationAlreadyEnabled
	 */
	public function testDoEditIfAllowed__pageAlreadyHasRegistration(
		IEventLookup $eventLookup,
		int $existingRegistrationID,
		string $expectedMsg
	) {
		$newRegistration = $this->createMock( EventRegistration::class );
		$newRegistration->method( 'getID' )->willReturn( $existingRegistrationID + 1 );

		$status = $this->getCommand( null, null, $eventLookup )->doEditIfAllowed(
			$newRegistration,
			$this->createMock( ICampaignsAuthority::class ),
			self::ORGANIZER_USERNAMES
		);
		$this->assertNotInstanceOf( PermissionStatus::class, $status );
		$this->assertStatusNotGood( $status );
		$this->assertStatusMessage( $expectedMsg, $status );
	}

	public function providePageWithRegistrationAlreadyEnabled(): Generator {
		$existingRegistrationsID = 1;

		$nonDeletedRegistration = $this->createMock( ExistingEventRegistration::class );
		$nonDeletedRegistration->method( 'getID' )->willReturn( $existingRegistrationsID );
		$nonDeletedEventLookup = $this->createMock( IEventLookup::class );
		$nonDeletedEventLookup->expects( $this->once() )
			->method( 'getEventByPage' )
			->willReturn( $nonDeletedRegistration );
		yield 'Already has non-deleted registration' => [
			$nonDeletedEventLookup,
			$existingRegistrationsID,
			'campaignevents-error-page-already-registered'
		];

		$deletedRegistration = $this->createMock( ExistingEventRegistration::class );
		$deletedRegistration->method( 'getID' )->willReturn( $existingRegistrationsID );
		$deletedRegistration->method( 'getDeletionTimestamp' )->willReturn( '1646000000' );
		$deletedEventLookup = $this->createMock( IEventLookup::class );
		$deletedEventLookup->expects( $this->once() )
			->method( 'getEventByPage' )
			->willReturn( $deletedRegistration );
		yield 'Already has a deleted registration' => [
			$deletedEventLookup,
			$existingRegistrationsID,
			'campaignevents-error-page-already-registered-deleted'
		];
	}

	/**
	 * @covers ::doEditUnsafe
	 */
	public function testDoEditUnsafe__deletedRegistration() {
		$id = 1;
		$existingRegistration = $this->createMock( ExistingEventRegistration::class );
		$existingRegistration->method( 'getID' )->willReturn( $id );
		$existingRegistration->method( 'getDeletionTimestamp' )->willReturn( '1654000000' );
		$newRegistration = $this->createMock( EventRegistration::class );
		$newRegistration->method( 'getID' )->willReturn( $id );

		$eventLookup = $this->createMock( IEventLookup::class );
		$eventLookup->expects( $this->once() )->method( 'getEventByPage' )->willReturn( $existingRegistration );
		$status = $this->getCommand( null, null, $eventLookup )->doEditUnsafe(
			$newRegistration,
			$this->createMock( ICampaignsAuthority::class ),
			self::ORGANIZER_USERNAMES
		);
		$this->assertStatusNotGood( $status );
		$this->assertStatusMessage( 'campaignevents-edit-registration-deleted', $status );
	}

	/**
	 * @param EventRegistration $registration
	 * @covers ::doEditIfAllowed
	 * @covers ::authorizeEdit
	 * @dataProvider provideEventRegistrations
	 */
	public function testDoEditIfAllowed__successful( EventRegistration $registration ) {
		$id = 42;
		$eventStore = $this->createMock( IEventStore::class );
		$eventStore->expects( $this->once() )->method( 'saveRegistration' )->willReturn( $id );
		$status = $this->getCommand( $eventStore )->doEditIfAllowed(
			$registration,
			$this->createMock( ICampaignsAuthority::class ),
			self::ORGANIZER_USERNAMES
		);
		$this->assertStatusGood( $status );
		$this->assertStatusValue( $id, $status );
	}

	/**
	 * @param EventRegistration $registration
	 * @param string|null $expectedMsg
	 * @param array|null $organizers
	 * @param PermissionChecker|null $permChecker
	 * @param CampaignsCentralUserLookup|null $centralUserLookup
	 * @param OrganizersStore|null $organizersStore
	 * @param TrackingToolEventWatcher|null $trackingToolEventWatcher
	 * @covers ::doEditUnsafe
	 * @covers ::validateOrganizers
	 * @covers ::organizerNamesToCentralIDs
	 * @covers ::checkOrganizerNotRemovingTheCreator
	 * @dataProvider provideEditUnsafeErrors
	 */
	public function testDoEditUnsafe__error(
		EventRegistration $registration,
		string $expectedMsg,
		array $organizers,
		PermissionChecker $permChecker = null,
		CampaignsCentralUserLookup $centralUserLookup = null,
		OrganizersStore $organizersStore = null,
		TrackingToolEventWatcher $trackingToolEventWatcher = null
	) {
		$command = $this->getCommand(
			null,
			$permChecker,
			null,
			$centralUserLookup,
			$organizersStore,
			$trackingToolEventWatcher
		);
		$status = $command->doEditUnsafe(
			$registration,
			$this->createMock( ICampaignsAuthority::class ),
			$organizers,
		);
		$this->assertStatusNotGood( $status );
		$this->assertStatusMessage( $expectedMsg, $status );
	}

	public function provideEditUnsafeErrors(): Generator {
		$registrations = $this->provideEventRegistrations();
		foreach ( $registrations as $testName => [ $registration ] ) {
			$notGlobalLookup = $this->createMock( CampaignsCentralUserLookup::class );
			$notGlobalLookup->method( 'newFromAuthority' )
				->willThrowException( $this->createMock( UserNotGlobalException::class ) );
			yield "$testName, user not global" => [
				$registration,
				'campaignevents-edit-need-central-account',
				self::ORGANIZER_USERNAMES,
				null,
				$notGlobalLookup
			];
			yield "$testName, empty list of organizers" => [
				$registration,
				'campaignevents-edit-no-organizers',
				[]
			];
			$organizers = [];
			for ( $i = 0; $i < EditEventCommand::MAX_ORGANIZERS_PER_EVENT + 1; $i++ ) {
				$organizers[] = 'organizer-' . $i;
			}
			yield "$testName, organizer limit per event error" => [
				$registration,
				'campaignevents-edit-too-many-organizers',
				$organizers
			];
			$disallowedPermChecker = $this->createMock( PermissionChecker::class );
			$disallowedPermChecker->method( 'userCanOrganizeEvents' )->willReturn( false );
			yield "$testName, organizers do not have the organizer right" => [
				$registration,
				'campaignevents-edit-organizers-not-allowed',
				self::ORGANIZER_USERNAMES,
				$disallowedPermChecker
			];

			$usersNotGlobalCentralUserLookup = $this->createMock( CampaignsCentralUserLookup::class );
			$usersNotGlobalCentralUserLookup->method( 'newFromLocalUsername' )
				->willThrowException( $this->createMock( UserNotGlobalException::class ) );
			$usersNotGlobalCentralUserLookup->method( 'isValidLocalUsername' )->willReturn( true );
			yield "$testName, organizers need central account" => [
				$registration,
				'campaignevents-edit-organizer-need-central-account',
				self::ORGANIZER_USERNAMES,
				null,
				$usersNotGlobalCentralUserLookup
			];

			$creatorID = 1;
			$creatorUser = $this->createMock( CentralUser::class );
			$creatorUser->method( 'getCentralID' )->willReturn( $creatorID );
			$creatorOrganizer = $this->createMock( Organizer::class );
			$creatorOrganizer->method( 'getUser' )->willReturn( $creatorUser );

			$organizersStore = $this->createMock( OrganizersStore::class );
			$organizersStore->method( 'getEventCreator' )->willReturn( $creatorOrganizer );

			$notCreatorUsername = 'Not the event creator';
			$notCreatorUser = $this->createMock( CentralUser::class );
			$notCreatorUser->method( 'getCentralID' )->willReturn( $creatorID + 1 );
			$returnNotCreatorCentralUserLookup = $this->createMock( CampaignsCentralUserLookup::class );
			$returnNotCreatorCentralUserLookup->method( 'newFromLocalUsername' )
				->with( $notCreatorUsername )->willReturn( $notCreatorUser );
			$returnNotCreatorCentralUserLookup->method( 'isValidLocalUsername' )->willReturn( true );
			$returnNotCreatorCentralUserLookup->method( 'existsAndIsVisible' )->willReturn( true );

			$noCreatorMsg = $registration->getID()
				? 'campaignevents-edit-removed-creator'
				: 'campaignevents-edit-no-creator';
			yield "$testName, event creator not included" => [
				$registration,
				$noCreatorMsg,
				[ $notCreatorUsername ],
				null,
				$returnNotCreatorCentralUserLookup,
				$organizersStore
			];

			$invalidUsernameCentralUserLookup = $this->createMock( CampaignsCentralUserLookup::class );
			$invalidUsernameCentralUserLookup->method( 'isValidLocalUsername' )->willReturn( false );
			yield "$testName, invalid username" => [
				$registration,
				'campaignevents-edit-invalid-username',
				[ 'invalid-username|<>' ],
				null,
				$invalidUsernameCentralUserLookup
			];

			$trackingToolError = 'some-tracking-tool-error';
			$trackingToolWatcher = $this->createMock( TrackingToolEventWatcher::class );
			$methodName = $registration->getID() ? 'validateEventUpdate' : 'validateEventCreation';
			$trackingToolWatcher->expects( $this->atLeastOnce() )
				->method( $methodName )
				->willReturn( StatusValue::newFatal( $trackingToolError ) );
			yield "$testName, fails tracking tool validation" => [
				$registration,
				$trackingToolError,
				self::ORGANIZER_USERNAMES,
				null,
				null,
				null,
				$trackingToolWatcher
			];
		}
	}

	/**
	 * @param EventRegistration $registration
	 * @covers ::doEditUnsafe
	 * @dataProvider provideEventRegistrations
	 */
	public function testDoEditUnsafe__successful( EventRegistration $registration ) {
		$id = 42;
		$eventStore = $this->createMock( IEventStore::class );
		$eventStore->expects( $this->once() )->method( 'saveRegistration' )->willReturn( $id );
		$status = $this->getCommand( $eventStore )->doEditUnsafe(
			$registration,
			$this->createMock( ICampaignsAuthority::class ),
			self::ORGANIZER_USERNAMES
		);
		$this->assertStatusGood( $status );
		$this->assertStatusValue( $id, $status );
	}

	/**
	 * @covers ::addOrganizers
	 * @dataProvider provideEventRegistrations
	 */
	public function testAddOrganizers( EventRegistration $registration ) {
		$creatorID = 1;
		$organizerIDsMap = [
			'Creator' => $creatorID,
			'Organizer 1' => 2,
			'Organizer 2' => 3,
		];
		$expectedOrganizersAndRoles = [
			$creatorID => [ Roles::ROLE_CREATOR ],
			2 => [ Roles::ROLE_ORGANIZER ],
			3 => [ Roles::ROLE_ORGANIZER ],
		];
		$creator = $this->createMock( CentralUser::class );
		$creator->method( 'getCentralID' )->willReturn( $creatorID );
		$creatorOrganizer = $this->createMock( Organizer::class );
		$creatorOrganizer->method( 'getUser' )->willReturn( $creator );
		$organizersStore = $this->createMock( OrganizersStore::class );
		$organizersStore->method( 'getEventCreator' )->willReturn( $creatorOrganizer );
		// Use the mocked OrganizersStore to perform a soft assertion on the roles.
		$organizersStore->expects( $this->once() )
			->method( 'addOrganizersToEvent' )
			->with( $this->anything(), $expectedOrganizersAndRoles );

		$centralUserLookup = $this->createMock( CampaignsCentralUserLookup::class );
		$centralUserLookup->method( 'newFromLocalUsername' )->willReturnCallback(
			function ( string $username ) use ( $organizerIDsMap ): CentralUser {
				$ret = $this->createMock( CentralUser::class );
				$ret->method( 'getCentralID' )->willReturn( $organizerIDsMap[$username] );
				return $ret;
			}
		);
		$centralUserLookup->method( 'newFromAuthority' )->willReturn( $creator );
		$centralUserLookup->method( 'isValidLocalUsername' )->willReturn( true );

		$command = $this->getCommand( null, null, null, $centralUserLookup, $organizersStore );
		$status = $command->doEditUnsafe(
			$registration,
			$this->createMock( ICampaignsAuthority::class ),
			array_keys( $organizerIDsMap ),
		);
		$this->assertStatusGood( $status );
	}

	/**
	 * @covers ::doEditUnsafe
	 */
	public function testDoEditUnsafe__successfulCreatorDeletedOrNotVisible() {
		$registration = $this->createMock( EventRegistration::class );
		$registration->method( 'getID' )->willReturn( 1 );

		$performer = $this->createMock( ICampaignsAuthority::class );

		$centralUserLookup = $this->createMock( CampaignsCentralUserLookup::class );
		$centralUserLookup->method( 'isValidLocalUsername' )->willReturn( true );
		$centralUserLookup->method( 'existsAndIsVisible' )->willReturn( false );

		$status = $this->getCommand( null, null, null, $centralUserLookup )->doEditUnsafe(
			$registration,
			$performer,
			self::ORGANIZER_USERNAMES
		);
		$this->assertStatusGood( $status );
	}

	public function provideEventRegistrations(): Generator {
		yield 'New (creation)' => [ $this->createMock( EventRegistration::class ) ];
		$existing = $this->createMock( EventRegistration::class );
		$existing->method( 'getID' )->willReturn( 1 );
		yield 'Existing (update)' => [ $existing ];
	}

	/**
	 * @param EventRegistration $registration
	 * @param TrackingToolEventWatcher $watcher
	 * @param TrackingToolUpdater $updater
	 * @param StatusValue $expectedStatus
	 * @covers ::updateTrackingTools
	 * @dataProvider provideUpdateTrackingTools
	 * @note That tracking tool validation is tested in testDoEditUnsafe__error
	 */
	public function testUpdateTrackingTools(
		EventRegistration $registration,
		TrackingToolEventWatcher $watcher,
		TrackingToolUpdater $updater,
		StatusValue $expectedStatus
	) {
		$cmd = $this->getCommand( null, null, null, null, null, $watcher, $updater );
		$status = $cmd->doEditUnsafe(
			$registration,
			$this->createMock( ICampaignsAuthority::class ),
			self::ORGANIZER_USERNAMES
		);
		$this->assertEquals( $expectedStatus, $status );
	}

	public function provideUpdateTrackingTools(): Generator {
		$newTools = [ $this->createMock( TrackingToolAssociation::class ) ];

		$existingEvent = $this->createMock( ExistingEventRegistration::class );
		$existingEvent->method( 'getID' )->willReturn( 1 );
		$newEvent = $this->createMock( EventRegistration::class );

		$changStatusErrorsToWarnings = static function ( StatusValue $status ): StatusValue {
			$ret = StatusValue::newGood();
			foreach ( $status->getErrors() as $err ) {
				$ret->warning( $err['message'], ...$err['params'] );
			}
			return $ret;
		};

		$successUpdateWatcher = $this->createMock( TrackingToolEventWatcher::class );
		$successUpdateWatcher->method( 'validateEventUpdate' )->willReturn( StatusValue::newGood() );
		$successUpdateWatcher->method( 'onEventUpdated' )->willReturn( StatusValue::newGood( $newTools ) );
		$successUpdateUpdater = $this->createMock( TrackingToolUpdater::class );
		$successUpdateUpdater->expects( $this->once() )
			->method( 'replaceEventTools' )
			->with( $this->anything(), $newTools );
		yield 'Existing event, success' => [
			$existingEvent,
			$successUpdateWatcher,
			$successUpdateUpdater,
			StatusValue::newGood()
		];

		$errorUpdateWatcher = $this->createMock( TrackingToolEventWatcher::class );
		$errorUpdateWatcher->method( 'validateEventUpdate' )->willReturn( StatusValue::newGood() );
		$updateError = StatusValue::newFatal( 'some-tool-update-error' );
		$errorUpdateWatcher->method( 'onEventUpdated' )
			->willReturn( StatusValue::newGood( $newTools )->merge( $updateError ) );
		$errorUpdateUpdater = $this->createMock( TrackingToolUpdater::class );
		$errorUpdateUpdater->expects( $this->once() )
			->method( 'replaceEventTools' )
			->with( $this->anything(), $newTools );
		yield 'Existing event, error' => [
			$existingEvent,
			$errorUpdateWatcher,
			$errorUpdateUpdater,
			$changStatusErrorsToWarnings( $updateError )
		];

		$successCreationWatcher = $this->createMock( TrackingToolEventWatcher::class );
		$successCreationWatcher->method( 'validateEventCreation' )->willReturn( StatusValue::newGood() );
		$successCreationWatcher->method( 'onEventCreated' )->willReturn( StatusValue::newGood( $newTools ) );
		$successCreationUpdater = $this->createMock( TrackingToolUpdater::class );
		$successCreationUpdater->expects( $this->once() )
			->method( 'replaceEventTools' )
			->with( $this->anything(), $newTools );
		yield 'New event, success' => [
			$newEvent,
			$successCreationWatcher,
			$successCreationUpdater,
			StatusValue::newGood()
		];

		$errorCreationWatcher = $this->createMock( TrackingToolEventWatcher::class );
		$errorCreationWatcher->method( 'validateEventCreation' )->willReturn( StatusValue::newGood() );
		$creationError = StatusValue::newFatal( 'some-tool-update-error' );
		$errorCreationWatcher->method( 'onEventCreated' )
			->willReturn( StatusValue::newGood( $newTools )->merge( $creationError ) );
		$errorCreationUpdater = $this->createMock( TrackingToolUpdater::class );
		$errorCreationUpdater->expects( $this->once() )
			->method( 'replaceEventTools' )
			->with( $this->anything(), $newTools );
		yield 'New event, error' => [
			$newEvent,
			$errorCreationWatcher,
			$errorCreationUpdater,
			$changStatusErrorsToWarnings( $creationError )
		];
	}

	/**
	 * @param string $newEndDate
	 * @param bool $isPast
	 * @param bool $hasAnswers
	 * @param bool $hasAggregates
	 * @param bool $success
	 * @covers ::checkCanEditEventDates
	 * @covers ::isPastEventWithAnswers
	 * @dataProvider provideEventRegistrationsEditEventDates
	 */
	public function testDoEditIfAllowed__editEventDates(
		string $newEndDate,
		bool $isPast,
		bool $hasAnswers,
		bool $hasAggregates,
		bool $success
	) {
		$permChecker = $this->createMock( PermissionChecker::class );
		$permChecker->method( 'userCanEditRegistration' )->willReturn( true );
		$permChecker->method( 'userCanOrganizeEvents' )->willReturn( true );

		$currentRegistrationData = $this->createMock( ExistingEventRegistration::class );
		$currentRegistrationData->method( 'getID' )->willReturn( 1 );
		$currentRegistrationData->method( 'isPast' )->willReturn( $isPast );

		$registration = $this->createMock( EventRegistration::class );
		$registration->method( 'getID' )->willReturn( 1 );
		$registration->method( 'getEndUTCTimestamp' )->willReturn( $newEndDate );

		$eventLookup = $this->createMock( IEventLookup::class );
		$eventLookup->method( 'getEventByPage' )->willReturn( $currentRegistrationData );
		$eventLookup->method( 'getEventByID' )->willReturn( $currentRegistrationData );

		$participantAnswersStore = $this->createMock( ParticipantAnswersStore::class );
		$eventAggregatedAnswersStore = $this->createMock( EventAggregatedAnswersStore::class );

		$participantAnswersStore->method( 'eventHasAnswers' )->willReturn( $hasAnswers );
		$eventAggregatedAnswersStore->method( 'eventHasAggregates' )->willReturn( $hasAggregates );

		$status = $this->getCommand(
			null,
			$permChecker,
			$eventLookup,
			null,
			null,
			null,
			null,
			$participantAnswersStore,
			$eventAggregatedAnswersStore
		)->doEditIfAllowed(
			$registration,
			$this->createMock( ICampaignsAuthority::class ),
			self::ORGANIZER_USERNAMES
		);

		if ( !$success ) {
			$this->assertStatusNotGood( $status );
			$this->assertStatusMessage( 'campaignevents-event-dates-cannot-be-changed', $status );
		} else {
			$this->assertStatusGood( $status );
		}
	}

	/**
	 * @return array
	 */
	public function provideEventRegistrationsEditEventDates() {
		return [
			'There are answers, end date is past, and is changing the end date to future' => [
				wfTimestamp( TS_MW, self::FAKE_TIME + 1 ),
				true,
				true,
				false,
				false
			],
			'There are aggregates, end date is past, and is changing the end date to future' => [
				wfTimestamp( TS_MW, self::FAKE_TIME + 1 ),
				true,
				false,
				true,
				false
			],
			'There are no answers, and is changing the end date to future' => [
				wfTimestamp( TS_MW, self::FAKE_TIME + 1 ),
				true,
				false,
				false,
				true
			],
			'There are aggregates, but it is not changing event dates' => [
				wfTimestamp( TS_MW, self::FAKE_TIME ),
				false,
				false,
				false,
				true
			]
		];
	}
}
