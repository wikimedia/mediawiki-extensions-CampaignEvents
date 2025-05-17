<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Event;

use Generator;
use MediaWiki\Extension\CampaignEvents\Event\EditEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\PageEventLookup;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventStore;
use MediaWiki\Extension\CampaignEvents\EventPage\EventPageCacheUpdater;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
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
use MediaWiki\Permissions\Authority;
use MediaWiki\Permissions\PermissionStatus;
use MediaWiki\Utils\MWTimestamp;
use MediaWikiUnitTestCase;
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
	 * @param PageEventLookup|null $pageEventLookup
	 * @param CampaignsCentralUserLookup|null $centralUserLookup
	 * @param OrganizersStore|null $organizersStore
	 * @param TrackingToolEventWatcher|null $trackingToolEventWatcher
	 * @param TrackingToolUpdater|null $trackingToolUpdater
	 * @param ParticipantAnswersStore|null $participantAnswersStore
	 * @param EventAggregatedAnswersStore|null $eventAggregatedAnswersStore
	 * @param IEventLookup|null $eventLookup
	 * @return EditEventCommand
	 */
	private function getCommand(
		?IEventStore $eventStore = null,
		?PermissionChecker $permChecker = null,
		?PageEventLookup $pageEventLookup = null,
		?CampaignsCentralUserLookup $centralUserLookup = null,
		?OrganizersStore $organizersStore = null,
		?TrackingToolEventWatcher $trackingToolEventWatcher = null,
		?TrackingToolUpdater $trackingToolUpdater = null,
		?ParticipantAnswersStore $participantAnswersStore = null,
		?EventAggregatedAnswersStore $eventAggregatedAnswersStore = null,
		?IEventLookup $eventLookup = null
	): EditEventCommand {
		$eventStore ??= $this->createMock( IEventStore::class );

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
			$eventLookup ?? $this->createMock( IEventLookup::class ),
			$organizersStore,
			$permChecker,
			$centralUserLookup,
			$this->createMock( EventPageCacheUpdater::class ),
			$trackingToolEventWatcher,
			$trackingToolUpdater ?? $this->createMock( TrackingToolUpdater::class ),
			new NullLogger(),
			$participantAnswersStore ?? $this->createMock( ParticipantAnswersStore::class ),
			$eventAggregatedAnswersStore ?? $this->createMock( EventAggregatedAnswersStore::class ),
			$pageEventLookup ?? $this->createMock( PageEventLookup::class )
		);
	}

	/**
	 * @covers ::doEditIfAllowed
	 * @covers ::authorizeEdit
	 * @dataProvider provideEventRegistrations
	 */
	public function testDoEditIfAllowed__permissionError( callable $registration ) {
		$registration = $registration( $this );
		$isCreation = $registration->getID() === null;
		$permChecker = $this->createMock( PermissionChecker::class );
		$permMethod = $isCreation ? 'userCanEnableRegistration' : 'userCanEditRegistration';
		$permChecker->expects( $this->once() )->method( $permMethod )->willReturn( false );
		$status = $this->getCommand( null, $permChecker )->doEditIfAllowed(
			$registration,
			$this->createMock( Authority::class ),
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
	 * @param PageEventLookup $pageEventLookup
	 * @param int $existingRegistrationID
	 * @param string $expectedMsg
	 * @covers ::doEditIfAllowed
	 * @dataProvider providePageWithRegistrationAlreadyEnabled
	 */
	public function testDoEditIfAllowed__pageAlreadyHasRegistration(
		callable $pageEventLookup,
		int $existingRegistrationID,
		string $expectedMsg
	) {
		$pageEventLookup = $pageEventLookup( $this );
		$newRegistration = $this->createMock( EventRegistration::class );
		$newRegistration->method( 'getID' )->willReturn( $existingRegistrationID + 1 );

		$status = $this->getCommand( null, null, $pageEventLookup )->doEditIfAllowed(
			$newRegistration,
			$this->createMock( Authority::class ),
			self::ORGANIZER_USERNAMES
		);
		$this->assertNotInstanceOf( PermissionStatus::class, $status );
		$this->assertStatusNotGood( $status );
		$this->assertStatusMessage( $expectedMsg, $status );
	}

	public static function providePageWithRegistrationAlreadyEnabled(): Generator {
		$existingRegistrationsID = 1;

		yield 'Already has non-deleted registration' => [
			static function ( $testCase ) use ( $existingRegistrationsID ) {
				$nonDeletedRegistration = $testCase->createMock( ExistingEventRegistration::class );
				$nonDeletedRegistration->method( 'getID' )->willReturn( $existingRegistrationsID );
				$nonDeletedEventLookup = $testCase->createMock( PageEventLookup::class );
				$nonDeletedEventLookup->expects( $testCase->once() )
					->method( 'getRegistrationForPage' )
					->willReturn( $nonDeletedRegistration );
				return $nonDeletedEventLookup;
			},
			$existingRegistrationsID,
			'campaignevents-error-page-already-registered'
		];

		yield 'Already has a deleted registration' => [
			static function ( $testCase ) use ( $existingRegistrationsID ) {
				$deletedRegistration = $testCase->createMock( ExistingEventRegistration::class );
				$deletedRegistration->method( 'getID' )->willReturn( $existingRegistrationsID );
				$deletedRegistration->method( 'getDeletionTimestamp' )->willReturn( '1646000000' );
				$deletedEventLookup = $testCase->createMock( PageEventLookup::class );
					$deletedEventLookup->expects( $testCase->once() )
					->method( 'getRegistrationForPage' )
					->willReturn( $deletedRegistration );
				return $deletedEventLookup;
			},
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

		$pageEventLookup = $this->createMock( PageEventLookup::class );
		$pageEventLookup->expects( $this->once() )
			->method( 'getRegistrationForPage' )
			->willReturn( $existingRegistration );
		$status = $this->getCommand( null, null, $pageEventLookup )->doEditUnsafe(
			$newRegistration,
			$this->createMock( Authority::class ),
			self::ORGANIZER_USERNAMES
		);
		$this->assertStatusNotGood( $status );
		$this->assertStatusMessage( 'campaignevents-edit-registration-deleted', $status );
	}

	/**
	 * @covers ::doEditIfAllowed
	 * @covers ::authorizeEdit
	 * @dataProvider provideEventRegistrations
	 */
	public function testDoEditIfAllowed__successful( callable $registration ) {
		$registration = $registration( $this );
		$id = 42;
		$eventStore = $this->createMock( IEventStore::class );
		$eventStore->expects( $this->once() )->method( 'saveRegistration' )->willReturn( $id );
		$status = $this->getCommand( $eventStore )->doEditIfAllowed(
			$registration,
			$this->createMock( Authority::class ),
			self::ORGANIZER_USERNAMES
		);
		$this->assertStatusGood( $status );
		$this->assertStatusValue( $id, $status );
	}

	/**
	 * @covers ::doEditUnsafe
	 * @covers ::validateOrganizers
	 * @covers ::organizerNamesToCentralIDs
	 * @covers ::checkOrganizerNotRemovingTheCreator
	 * @dataProvider provideEditUnsafeErrors
	 */
	public function testDoEditUnsafe__error(
		callable $registration,
		$expectedMsg,
		array $organizers,
		?callable $permChecker = null,
		?callable $centralUserLookup = null,
		?callable $organizersStore = null,
		?callable $trackingToolEventWatcher = null
	) {
		$registration = $registration( $this );
		$expectedMsg = is_callable( $expectedMsg ) ? $expectedMsg( $this ) : $expectedMsg;
		$permChecker = $permChecker !== null ? $permChecker( $this ) : null;
		$centralUserLookup = $centralUserLookup !== null ? $centralUserLookup( $this ) : null;
		$organizersStore = $organizersStore !== null ? $organizersStore( $this ) : null;
		$trackingToolEventWatcher = $trackingToolEventWatcher !== null ? $trackingToolEventWatcher( $this ) : null;
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
			$this->createMock( Authority::class ),
			$organizers,
		);
		$this->assertStatusNotGood( $status );
		$this->assertStatusMessage( $expectedMsg, $status );
	}

	public static function provideEditUnsafeErrors(): Generator {
		$registrations = self::provideEventRegistrations();
		foreach ( $registrations as $testName => [ $registration ] ) {
			yield "$testName, user not global" => [
				$registration,
				'campaignevents-edit-need-central-account',
				self::ORGANIZER_USERNAMES,
				null,
				static function ( $testCase ) {
					$notGlobalLookup = $testCase->createMock( CampaignsCentralUserLookup::class );
					$notGlobalLookup->method( 'newFromAuthority' )
						->willThrowException( $testCase->createMock( UserNotGlobalException::class ) );
					return $notGlobalLookup;
				},
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
			yield "$testName, organizers do not have the organizer right" => [
				$registration,
				'campaignevents-edit-organizers-not-allowed',
				self::ORGANIZER_USERNAMES,
				static function ( $testCase ) {
					$disallowedPermChecker = $testCase->createMock( PermissionChecker::class );
					$disallowedPermChecker->method( 'userCanOrganizeEvents' )->willReturn( false );
					return $disallowedPermChecker;
				},
			];

			yield "$testName, organizers need central account" => [
				$registration,
				'campaignevents-edit-organizer-need-central-account',
				self::ORGANIZER_USERNAMES,
				null,
				static function ( $testCase ) {
					$usersNotGlobalCentralUserLookup = $testCase->createMock( CampaignsCentralUserLookup::class );
					$usersNotGlobalCentralUserLookup->method( 'newFromLocalUsername' )
						->willThrowException( $testCase->createMock( UserNotGlobalException::class ) );
					$usersNotGlobalCentralUserLookup->method( 'isValidLocalUsername' )->willReturn( true );
					return $usersNotGlobalCentralUserLookup;
				},
			];

			$creatorID = 1;
			$notCreatorUsername = 'Not the event creator';
			yield "$testName, event creator not included" => [
				$registration,
				static function ( $testCase ) use ( $registration ) {
					$noCreatorMsg = $registration( $testCase )->getID()
						? 'campaignevents-edit-removed-creator'
						: 'campaignevents-edit-no-creator';
					return $noCreatorMsg;
				},
				[ $notCreatorUsername ],
				null,
				static function ( $testCase ) use ( $creatorID, $notCreatorUsername ) {
					$notCreatorUser = $testCase->createMock( CentralUser::class );
					$notCreatorUser->method( 'getCentralID' )->willReturn( $creatorID + 1 );
					$returnNotCreatorCentralUserLookup = $testCase->createMock( CampaignsCentralUserLookup::class );
					$returnNotCreatorCentralUserLookup->method( 'newFromLocalUsername' )
						->with( $notCreatorUsername )->willReturn( $notCreatorUser );
					$returnNotCreatorCentralUserLookup->method( 'isValidLocalUsername' )->willReturn( true );
					$returnNotCreatorCentralUserLookup->method( 'existsAndIsVisible' )->willReturn( true );
					return $returnNotCreatorCentralUserLookup;
				},
				static function ( $testCase ) use ( $creatorID ) {
					$creatorUser = $testCase->createMock( CentralUser::class );
					$creatorUser->method( 'getCentralID' )->willReturn( $creatorID );
					$creatorOrganizer = $testCase->createMock( Organizer::class );
					$creatorOrganizer->method( 'getUser' )->willReturn( $creatorUser );

					$organizersStore = $testCase->createMock( OrganizersStore::class );
					$organizersStore->method( 'getEventCreator' )->willReturn( $creatorOrganizer );
					return $organizersStore;
				},
			];

			yield "$testName, invalid username" => [
				$registration,
				'campaignevents-edit-invalid-username',
				[ 'invalid-username|<>' ],
				null,
				static function ( $testCase ) {
					$invalidUsernameCentralUserLookup = $testCase->createMock( CampaignsCentralUserLookup::class );
					$invalidUsernameCentralUserLookup->method( 'isValidLocalUsername' )->willReturn( false );
					return $invalidUsernameCentralUserLookup;
				}
			];

			$trackingToolError = 'some-tracking-tool-error';
			yield "$testName, fails tracking tool validation" => [
				$registration,
				$trackingToolError,
				self::ORGANIZER_USERNAMES,
				null,
				null,
				null,
				static function ( $testCase ) use ( $registration, $trackingToolError ) {
					$methodName = $registration( $testCase )->getID() ? 'validateEventUpdate' : 'validateEventCreation';
					$trackingToolWatcher = $testCase->createMock( TrackingToolEventWatcher::class );
					$trackingToolWatcher->expects( $testCase->atLeastOnce() )
						->method( $methodName )
						->willReturn( StatusValue::newFatal( $trackingToolError ) );
					return $trackingToolWatcher;
				}
			];
		}
	}

	/**
	 * @covers ::doEditUnsafe
	 * @dataProvider provideEventRegistrations
	 */
	public function testDoEditUnsafe__successful( callable $registration ) {
		$registration = $registration( $this );
		$id = 42;
		$eventStore = $this->createMock( IEventStore::class );
		$eventStore->expects( $this->once() )->method( 'saveRegistration' )->willReturn( $id );
		$status = $this->getCommand( $eventStore )->doEditUnsafe(
			$registration,
			$this->createMock( Authority::class ),
			self::ORGANIZER_USERNAMES
		);
		$this->assertStatusGood( $status );
		$this->assertStatusValue( $id, $status );
	}

	/**
	 * @covers ::addOrganizers
	 * @dataProvider provideEventRegistrations
	 */
	public function testAddOrganizers( callable $registration ) {
		$registration = $registration( $this );
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
			$this->createMock( Authority::class ),
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

		$performer = $this->createMock( Authority::class );

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

	public static function provideEventRegistrations(): Generator {
		yield 'New (creation)' => [ static function ( $testCase ) {
			return $testCase->createMock( EventRegistration::class );
		} ];
		yield 'Existing (update)' => [ static function ( $testCase ) {
			$existing = $testCase->createMock( EventRegistration::class );
			$existing->method( 'getID' )->willReturn( 1 );
			return $existing;
		} ];
	}

	/**
	 * @covers ::updateTrackingTools
	 * @dataProvider provideUpdateTrackingTools
	 * @note That tracking tool validation is tested in testDoEditUnsafe__error
	 */
	public function testUpdateTrackingTools(
		string $registrationSpec,
		callable $getMocks
	) {
		if ( $registrationSpec === 'existing' ) {
			$registration = $this->createMock( ExistingEventRegistration::class );
			$registration->method( 'getID' )->willReturn( 1 );
		} else {
			$registration = $this->createMock( EventRegistration::class );
		}
		[ $watcher, $updater, $expectedStatus ] = $getMocks( $this );

		$cmd = $this->getCommand( null, null, null, null, null, $watcher, $updater );
		$status = $cmd->doEditUnsafe(
			$registration,
			$this->createMock( Authority::class ),
			self::ORGANIZER_USERNAMES
		);
		$this->assertEquals( $expectedStatus, $status );
	}

	public static function provideUpdateTrackingTools(): Generator {
		$changStatusErrorsToWarnings = static function ( StatusValue $status ): StatusValue {
			$ret = StatusValue::newGood();
			foreach ( $status->getMessages() as $msg ) {
				$ret->warning( $msg );
			}
			return $ret;
		};

		yield 'Existing event, success' => [
			'existing',
			static function ( $testCase ) {
				$newTools = [ $testCase->createMock( TrackingToolAssociation::class ) ];
				$watcher = $testCase->createMock( TrackingToolEventWatcher::class );
				$watcher->method( 'validateEventUpdate' )->willReturn( StatusValue::newGood() );
				$watcher->method( 'onEventUpdated' )->willReturn( StatusValue::newGood( $newTools ) );
				$updater = $testCase->createMock( TrackingToolUpdater::class );
				$updater->expects( $testCase->once() )
					->method( 'replaceEventTools' )
					->with( $testCase->anything(), $newTools );
				return [ $watcher, $updater, StatusValue::newGood() ];
			},
		];

		yield 'Existing event, error' => [
			'existing',
			static function ( $testCase ) use ( $changStatusErrorsToWarnings ) {
				$newTools = [ $testCase->createMock( TrackingToolAssociation::class ) ];
				$watcher = $testCase->createMock( TrackingToolEventWatcher::class );
				$watcher->method( 'validateEventUpdate' )->willReturn( StatusValue::newGood() );
				$updateError = StatusValue::newFatal( 'some-tool-update-error' );
				$watcher->method( 'onEventUpdated' )
					->willReturn( StatusValue::newGood( $newTools )->merge( $updateError ) );
				$updater = $testCase->createMock( TrackingToolUpdater::class );
				$updater->expects( $testCase->once() )
					->method( 'replaceEventTools' )
					->with( $testCase->anything(), $newTools );
				return [ $watcher, $updater, $changStatusErrorsToWarnings( $updateError ) ];
			},
		];

		yield 'New event, success' => [
			'new',
			static function ( $testCase ) {
				$newTools = [ $testCase->createMock( TrackingToolAssociation::class ) ];
				$watcher = $testCase->createMock( TrackingToolEventWatcher::class );
				$watcher->method( 'validateEventCreation' )->willReturn( StatusValue::newGood() );
				$watcher->method( 'onEventCreated' )->willReturn( StatusValue::newGood( $newTools ) );
				$updater = $testCase->createMock( TrackingToolUpdater::class );
				$updater->expects( $testCase->once() )
					->method( 'replaceEventTools' )
					->with( $testCase->anything(), $newTools );
				return [ $watcher, $updater, StatusValue::newGood() ];
			},
		];

		yield 'New event, error' => [
			'new',
			static function ( $testCase ) use ( $changStatusErrorsToWarnings ) {
				$newTools = [ $testCase->createMock( TrackingToolAssociation::class ) ];
				$watcher = $testCase->createMock( TrackingToolEventWatcher::class );
				$watcher->method( 'validateEventCreation' )->willReturn( StatusValue::newGood() );
				$creationError = StatusValue::newFatal( 'some-tool-update-error' );
				$watcher->method( 'onEventCreated' )
					->willReturn( StatusValue::newGood( $newTools )->merge( $creationError ) );
				$updater = $testCase->createMock( TrackingToolUpdater::class );
				$updater->expects( $testCase->once() )
					->method( 'replaceEventTools' )
					->with( $testCase->anything(), $newTools );
				return [ $watcher, $updater, $changStatusErrorsToWarnings( $creationError ) ];
			},
		];
	}

	/**
	 * @param string $newEndDate
	 * @param bool $isPast
	 * @param bool $hasAnswers
	 * @param bool $hasAggregates
	 * @param bool $success
	 * @covers ::checkCanEditEventDates
	 * @covers ::eventHasAnswersOrAggregates
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
		$eventLookup->method( 'getEventByID' )->willReturn( $currentRegistrationData );

		$participantAnswersStore = $this->createMock( ParticipantAnswersStore::class );
		$eventAggregatedAnswersStore = $this->createMock( EventAggregatedAnswersStore::class );

		$participantAnswersStore->method( 'eventHasAnswers' )->willReturn( $hasAnswers );
		$eventAggregatedAnswersStore->method( 'eventHasAggregates' )->willReturn( $hasAggregates );

		$status = $this->getCommand(
			null,
			$permChecker,
			null,
			null,
			null,
			null,
			null,
			$participantAnswersStore,
			$eventAggregatedAnswersStore,
			$eventLookup
		)->doEditIfAllowed(
			$registration,
			$this->createMock( Authority::class ),
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
	public static function provideEventRegistrationsEditEventDates() {
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
