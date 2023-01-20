<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Event;

use Generator;
use MediaWiki\Extension\CampaignEvents\Event\DeleteEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventStore;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsAuthority;
use MediaWiki\Extension\CampaignEvents\MWEntity\IPermissionsLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageAuthorLookup;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Permissions\PermissionStatus;
use MediaWikiUnitTestCase;

/**
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Event\DeleteEventCommand
 * @covers ::__construct
 */
class DeleteEventCommandTest extends MediaWikiUnitTestCase {

	/**
	 * @param IEventStore|null $eventStore
	 * @param PermissionChecker|null $permChecker
	 * @return DeleteEventCommand
	 */
	private function getCommand(
		IEventStore $eventStore = null,
		PermissionChecker $permChecker = null
	): DeleteEventCommand {
		return new DeleteEventCommand(
			$eventStore ?? $this->createMock( IEventStore::class ),
			$permChecker ?? new PermissionChecker(
				$this->createMock( OrganizersStore::class ),
				$this->createMock( PageAuthorLookup::class ),
				$this->createMock( CampaignsCentralUserLookup::class ),
				$this->createMock( IPermissionsLookup::class )
			)
		);
	}

	/**
	 * @covers ::deleteIfAllowed
	 * @covers ::authorizeDeletion
	 */
	public function testDeleteIfAllowed__permissionError() {
		$permChecker = $this->createMock( PermissionChecker::class );
		$permChecker->expects( $this->once() )->method( 'userCanDeleteRegistration' )->willReturn( false );
		$status = $this->getCommand( null, $permChecker )->deleteIfAllowed(
			$this->createMock( ExistingEventRegistration::class ),
			$this->createMock( ICampaignsAuthority::class )
		);
		$this->assertInstanceOf( PermissionStatus::class, $status );
		$this->assertStatusNotGood( $status );
		$this->assertStatusMessage( 'campaignevents-delete-not-allowed-registration', $status );
	}

	/**
	 * @param ExistingEventRegistration $registration
	 * @param IEventStore $store
	 * @param bool $expectedVal
	 * @covers ::deleteIfAllowed
	 * @covers ::authorizeDeletion
	 * @dataProvider provideRegistrationAndStore
	 */
	public function testDeleteIfAllowed__successful(
		ExistingEventRegistration $registration,
		IEventStore $store,
		bool $expectedVal
	) {
		$permChecker = $this->createMock( PermissionChecker::class );
		$permChecker->expects( $this->once() )->method( 'userCanDeleteRegistration' )->willReturn( true );
		$status = $this->getCommand( $store, $permChecker )->deleteIfAllowed(
			$registration,
			$this->createMock( ICampaignsAuthority::class )
		);
		$this->assertStatusGood( $status );
		$this->assertStatusValue( $expectedVal, $status );
	}

	/**
	 * @param ExistingEventRegistration $registration
	 * @param IEventStore $store
	 * @param bool $expectedVal
	 * @covers ::deleteUnsafe
	 * @dataProvider provideRegistrationAndStore
	 */
	public function testDeleteUnsafe__successful(
		ExistingEventRegistration $registration,
		IEventStore $store,
		bool $expectedVal
	) {
		$status = $this->getCommand( $store )->deleteUnsafe( $registration );
		$this->assertStatusGood( $status );
		$this->assertStatusValue( $expectedVal, $status );
	}

	public function provideRegistrationAndStore(): Generator {
		$neverDeleted = $this->createMock( ExistingEventRegistration::class );
		$neverDeletedStore = $this->createMock( IEventStore::class );
		$neverDeletedStore->method( 'deleteRegistration' )->with( $neverDeleted )->willReturn( true );
		yield 'Never deleted' => [ $neverDeleted, $neverDeletedStore, true ];

		$alreadyDeleted = $this->createMock( ExistingEventRegistration::class );
		$alreadyDeletedStore = $this->createMock( IEventStore::class );
		$alreadyDeletedStore->method( 'deleteRegistration' )->with( $alreadyDeleted )->willReturn( false );
		yield 'Already deleted' => [ $alreadyDeleted, $alreadyDeletedStore, false ];
	}
}
