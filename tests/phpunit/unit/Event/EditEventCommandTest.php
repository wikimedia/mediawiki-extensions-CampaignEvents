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
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsUser;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Permissions\PermissionStatus;
use MediaWikiUnitTestCase;
use StatusValue;

/**
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Event\EditEventCommand
 * @covers ::__construct
 */
class EditEventCommandTest extends MediaWikiUnitTestCase {

	/**
	 * @param IEventStore|null $eventStore
	 * @param PermissionChecker|null $permChecker
	 * @param IEventLookup|null $eventLookup
	 * @return EditEventCommand
	 */
	private function getCommand(
		IEventStore $eventStore = null,
		PermissionChecker $permChecker = null,
		IEventLookup $eventLookup = null
	): EditEventCommand {
		if ( !$eventLookup ) {
			$eventLookup = $this->createMock( IEventLookup::class );
			$eventLookup->method( 'getEventByPage' )
				->willThrowException( $this->createMock( EventNotFoundException::class ) );
		}
		if ( !$permChecker ) {
			$permChecker = $this->createMock( PermissionChecker::class );
			$permChecker->method( 'userCanEnableRegistration' )->willReturn( true );
			$permChecker->method( 'userCanEditRegistration' )->willReturn( true );
		}
		return new EditEventCommand(
			$eventStore ?? $this->createMock( IEventStore::class ),
			$eventLookup,
			$this->createMock( OrganizersStore::class ),
			$permChecker
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
			$this->createMock( ICampaignsUser::class )
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
			$this->createMock( ICampaignsUser::class )
		);
		$this->assertInstanceOf( PermissionStatus::class, $status );
		$this->assertStatusNotGood( $status );
		$expectedMsg = $isCreation
			? 'campaignevents-create-not-allowed-page'
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
			$this->createMock( ICampaignsUser::class )
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
			$this->createMock( ICampaignsUser::class )
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
			$this->createMock( ICampaignsUser::class )
		);
		$this->assertStatusGood( $status );
		$this->assertStatusValue( $id, $status );
	}

	/**
	 * @param EventRegistration $registration
	 * @covers ::doEditUnsafe
	 * @dataProvider provideEventRegistrations
	 */
	public function testDoEditUnsafe__error( EventRegistration $registration ) {
		$errMsg = 'foo-bar';
		$eventStore = $this->createMock( IEventStore::class );
		$eventStore->expects( $this->once() )
			->method( 'saveRegistration' )
			->willReturn( StatusValue::newFatal( $errMsg ) );
		$status = $this->getCommand( $eventStore )->doEditUnsafe(
			$registration,
			$this->createMock( ICampaignsUser::class )
		);
		$this->assertStatusNotGood( $status );
		$this->assertStatusMessage( $errMsg, $status );
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
			$this->createMock( ICampaignsUser::class )
		);
		$this->assertStatusGood( $status );
		$this->assertStatusValue( $id, $status );
	}

	public function provideEventRegistrations(): Generator {
		yield 'New (creation)' => [ $this->createMock( EventRegistration::class ) ];
		$existing = $this->createMock( EventRegistration::class );
		$existing->method( 'getID' )->willReturn( 1 );
		yield 'Existing (update)' => [ $existing ];
	}
}
