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
use MediaWiki\Permissions\PermissionStatus;
use MediaWikiUnitTestCase;
use StatusValue;

/**
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Event\EditEventCommand
 * @covers ::__construct
 */
class EditEventCommandTest extends MediaWikiUnitTestCase {

	private const ORGANIZER_USERNAMES = [ 'organizerA', 'organizerB' ];

	/**
	 * @param IEventStore|null $eventStore
	 * @param PermissionChecker|null $permChecker
	 * @param IEventLookup|null $eventLookup
	 * @param CampaignsCentralUserLookup|null $centralUserLookup
	 * @param OrganizersStore|null $organizersStore
	 * @return EditEventCommand
	 */
	private function getCommand(
		IEventStore $eventStore = null,
		PermissionChecker $permChecker = null,
		IEventLookup $eventLookup = null,
		CampaignsCentralUserLookup $centralUserLookup = null,
		OrganizersStore $organizersStore = null
	): EditEventCommand {
		if ( !$eventStore ) {
			$eventStore = $this->createMock( IEventStore::class );
			$eventStore->method( 'saveRegistration' )->willReturn( StatusValue::newGood( 1 ) );
		}
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

		return new EditEventCommand(
			$eventStore,
			$eventLookup,
			$organizersStore,
			$permChecker,
			$centralUserLookup,
			$this->createMock( EventPageCacheUpdater::class )
		);
	}

	/**
	 * @param EventRegistration $registration
	 * @covers ::doEditIfAllowed
	 * @covers ::authorizeEdit
	 * @dataProvider provideEventRegistrations
	 */
	public function testDoEditIfAllowed__error( EventRegistration $registration ) {
		$expectedMsg = 'foo-bar';
		$eventStore = $this->createMock( IEventStore::class );
		$eventStore->expects( $this->once() )
			->method( 'saveRegistration' )
			->willReturn( StatusValue::newFatal( $expectedMsg ) );

		$status = $this->getCommand( $eventStore )->doEditIfAllowed(
			$registration,
			$this->createMock( ICampaignsAuthority::class ),
			self::ORGANIZER_USERNAMES
		);

		$this->assertNotInstanceOf( PermissionStatus::class, $status );
		$this->assertStatusNotGood( $status );
		$this->assertStatusMessage( $expectedMsg, $status );
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
		$eventStore->expects( $this->once() )->method( 'saveRegistration' )->willReturn( StatusValue::newGood( $id ) );
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
	 * @param IEventStore|null $eventStore
	 * @param CampaignsCentralUserLookup|null $centralUserLookup
	 * @param OrganizersStore|null $organizersStore
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
		IEventStore $eventStore = null,
		CampaignsCentralUserLookup $centralUserLookup = null,
		OrganizersStore $organizersStore = null
	) {
		$command = $this->getCommand( $eventStore, $permChecker, null, $centralUserLookup, $organizersStore );
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
			$storeError = 'some-store-error';
			$eventStore = $this->createMock( IEventStore::class );
			$eventStore->expects( $this->once() )
				->method( 'saveRegistration' )
				->willReturn( StatusValue::newFatal( $storeError ) );
			yield "$testName, store error" => [
				$registration,
				$storeError,
				self::ORGANIZER_USERNAMES,
				null,
				$eventStore,
				null
			];

			$notGlobalLookup = $this->createMock( CampaignsCentralUserLookup::class );
			$notGlobalLookup->method( 'newFromAuthority' )
				->willThrowException( $this->createMock( UserNotGlobalException::class ) );
			yield "$testName, user not global" => [
				$registration,
				'campaignevents-edit-need-central-account',
				self::ORGANIZER_USERNAMES,
				null,
				null,
				$notGlobalLookup
			];
			yield "$testName, empty list of organizers" => [
				$registration,
				'campaignevents-edit-no-organizers',
				[],
				null,
				null,
				null
			];
			$organizers = [];
			for ( $i = 0; $i < EditEventCommand::MAX_ORGANIZERS_PER_EVENT + 1; $i++ ) {
				$organizers[] = 'organizer-' . $i;
			}
			yield "$testName, organizer limit per event error" => [
				$registration,
				'campaignevents-edit-too-many-organizers',
				$organizers,
				null,
				null,
				null
			];
			$disallowedPermChecker = $this->createMock( PermissionChecker::class );
			$disallowedPermChecker->method( 'userCanOrganizeEvents' )->willReturn( false );
			yield "$testName, organizers do not have the organizer right" => [
				$registration,
				'campaignevents-edit-organizers-not-allowed',
				self::ORGANIZER_USERNAMES,
				$disallowedPermChecker,
				null,
				null
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
				null,
				$invalidUsernameCentralUserLookup
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
		$eventStore->expects( $this->once() )->method( 'saveRegistration' )->willReturn( StatusValue::newGood( $id ) );
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
}
