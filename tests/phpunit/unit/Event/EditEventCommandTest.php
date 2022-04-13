<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Event;

use Generator;
use MediaWiki\Extension\CampaignEvents\Event\EditEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventStore;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserBlockChecker;
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
	 * @return EditEventCommand
	 */
	private function getCommand(
		IEventStore $eventStore = null,
		PermissionChecker $permChecker = null
	): EditEventCommand {
		return new EditEventCommand(
			$eventStore ?? $this->createMock( IEventStore::class ),
			$this->createMock( OrganizersStore::class ),
			$permChecker ?? new PermissionChecker(
				$this->createMock( UserBlockChecker::class ),
				$this->createMock( OrganizersStore::class )
			)
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
		$isCreation = $registration->getID() === null;
		$permChecker = $this->createMock( PermissionChecker::class );
		$permMethod = $isCreation ? 'userCanCreateRegistration' : 'userCanEditRegistration';
		$permChecker->method( $permMethod )->willReturn( true );
		$eventStore = $this->createMock( IEventStore::class );
		$eventStore->expects( $this->once() )
			->method( 'saveRegistration' )
			->willReturn( StatusValue::newFatal( $expectedMsg ) );
		$status = $this->getCommand( $eventStore, $permChecker )->doEditIfAllowed(
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
		$permMethod = $isCreation ? 'userCanCreateRegistration' : 'userCanEditRegistration';
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
	 * @param EventRegistration $registration
	 * @covers ::doEditIfAllowed
	 * @covers ::authorizeEdit
	 * @dataProvider provideEventRegistrations
	 */
	public function testDoEditIfAllowed__successful( EventRegistration $registration ) {
		$id = 42;
		$eventStore = $this->createMock( IEventStore::class );
		$eventStore->expects( $this->once() )->method( 'saveRegistration' )->willReturn( StatusValue::newGood( $id ) );
		$permChecker = $this->createMock( PermissionChecker::class );
		$permMethod = $registration->getID() === null ? 'userCanCreateRegistration' : 'userCanEditRegistration';
		$permChecker->expects( $this->once() )->method( $permMethod )->willReturn( true );
		$status = $this->getCommand( $eventStore, $permChecker )->doEditIfAllowed(
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
