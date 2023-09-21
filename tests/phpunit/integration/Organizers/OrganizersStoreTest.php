<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Organizers;

use Generator;
use InvalidArgumentException;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Organizers\Roles;
use MediaWikiIntegrationTestCase;
use Wikimedia\TestingAccessWrapper;

/**
 * @group Test
 * @group Database
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore
 * @covers ::__construct()
 */
class OrganizersStoreTest extends MediaWikiIntegrationTestCase {
	/** @inheritDoc */
	protected $tablesUsed = [ 'ce_organizers' ];

	private const ORGANIZERS_BY_EVENT = [
		1 => [
			[
				'user' => 101,
				'roles' => [ Roles::ROLE_CREATOR, Roles::ROLE_ORGANIZER ],
				'deleted' => false,
				'clickwrap' => false
			],
			[ 'user' => 102, 'roles' => [ Roles::ROLE_ORGANIZER ], 'deleted' => false, 'clickwrap' => true ],
			[ 'user' => 103, 'roles' => [ Roles::ROLE_ORGANIZER ], 'deleted' => true, 'clickwrap' => false ],
		],
		3 => [
			[ 'user' => 101, 'roles' => [ Roles::ROLE_CREATOR ], 'deleted' => false, 'clickwrap' => false ],
		],
		10 => [
			[ 'user' => 101, 'roles' => [ Roles::ROLE_CREATOR ], 'deleted' => true, 'clickwrap' => false ],
		]
	];

	/**
	 * @inheritDoc
	 */
	public function addDBData(): void {
		$rolesMap = TestingAccessWrapper::constant( OrganizersStore::class, 'ROLES_MAP' );
		$ts = $this->db->timestamp();
		$rows = [];
		foreach ( self::ORGANIZERS_BY_EVENT as $event => $organizers ) {
			foreach ( $organizers as $data ) {
				$dbRoles = 0;
				foreach ( $data['roles'] as $role ) {
					$dbRoles |= $rolesMap[$role];
				}
				$rows[] = [
					'ceo_event_id' => $event,
					'ceo_user_id' => $data['user'],
					'ceo_roles' => $dbRoles,
					'ceo_created_at' => $ts,
					'ceo_deleted_at' => $data['deleted'] ? $ts : null,
					'ceo_agreement_timestamp' => $data['clickwrap'] ? $ts : null,
				];
			}
		}

		$this->db->insert( 'ce_organizers', $rows );
	}

	/**
	 * @param int $eventID
	 * @param array $expectedIDs
	 * @param int|null $lastOrganizerID
	 * @covers ::getEventOrganizers
	 * @covers ::rowToOrganizerObject
	 * @dataProvider provideOrganizers
	 */
	public function testGetEventOrganizers( int $eventID, array $expectedIDs, int $lastOrganizerID = null ) {
		$store = new OrganizersStore(
			CampaignEventsServices::getDatabaseHelper()
		);
		$actualOrganizers = $store->getEventOrganizers( $eventID, null, $lastOrganizerID );
		$actualUserIDs = [];
		foreach ( $actualOrganizers as $organizer ) {
			$actualUserIDs[] = $organizer->getUser()->getCentralID();
		}
		$this->assertSame( $expectedIDs, $actualUserIDs );
	}

	public static function provideOrganizers(): Generator {
		yield 'Has organizers, including a deleted one' => [ 1, [ 101, 102 ] ];
		yield 'Does not have organizers' => [ 2, [] ];
		yield 'Providing ID of the last organizer' => [ 1, [ 102 ], 1 ];
	}

	/**
	 * @param int $eventID
	 * @param int $userID
	 * @param bool $expected
	 * @covers ::isEventOrganizer
	 * @covers ::getEventOrganizer
	 * @dataProvider provideIsOrganizer
	 */
	public function testIsEventOrganizer( int $eventID, int $userID, bool $expected ) {
		$user = new CentralUser( $userID );
		$store = new OrganizersStore(
			CampaignEventsServices::getDatabaseHelper()
		);
		$this->assertSame( $expected, $store->isEventOrganizer( $eventID, $user ) );
	}

	public static function provideIsOrganizer(): Generator {
		yield 'Yes, creator' => [ 1, 101, true ];
		yield 'Yes, secondary role' => [ 1, 102, true ];
		yield 'No, deleted' => [ 1, 103, false ];
		yield 'Nope' => [ 2, 101, false ];
	}

	/**
	 * @param int $eventID
	 * @param int $userID
	 * @param array $expectedIDs
	 * @covers ::updateClickwrapAcceptance
	 * @dataProvider provideClickwrapAcceptance
	 */
	public function testUpdateClickwrapAcceptance( int $eventID, int $userID, array $expectedIDs ) {
		$user = new CentralUser( $userID );
		$store = new OrganizersStore(
			CampaignEventsServices::getDatabaseHelper()
		);
		$store->updateClickwrapAcceptance( $eventID, $user );
		$organizers = $store->getEventOrganizers( $eventID );
		$actualIDs = [];
		foreach ( $organizers as $organizer ) {
			if ( $organizer->getClickwrapAcceptance() ) {
				$actualIDs[] = $organizer->getUser()->getCentralID();
			}
		}

		$this->assertSame( $expectedIDs, $actualIDs );
	}

	public static function provideClickwrapAcceptance(): Generator {
		yield 'Clickwrap accepted by test' => [ 1, 101, [ 101, 102 ] ];
		yield 'Clickwrap accepted before test' => [ 1, 102, [ 102 ] ];
	}

	/**
	 * @param int $eventID
	 * @param int $userID
	 * @param string[] $roles
	 * @param array<int,string[]> $expectedOrganizers Shape: [ user_id => [ role1, role2, ... ], ... ]
	 * @covers ::addOrganizerToEvent
	 * @dataProvider provideOrganizersToAdd
	 */
	public function testAddOrganizerToEvent( int $eventID, int $userID, array $roles, array $expectedOrganizers ) {
		$store = new OrganizersStore(
			CampaignEventsServices::getDatabaseHelper()
		);

		$store->addOrganizerToEvent( $eventID, $userID, $roles );

		$actualOrganizers = $store->getEventOrganizers( $eventID );
		$this->assertSameSize( $expectedOrganizers, $actualOrganizers );
		foreach ( $actualOrganizers as $actualOrg ) {
			$userID = $actualOrg->getUser()->getCentralID();
			$this->assertArrayHasKey( $userID, $expectedOrganizers );
			$this->assertSame( $expectedOrganizers[$userID], $actualOrg->getRoles(), "Roles for user $userID" );
		}
	}

	public static function provideOrganizersToAdd(): Generator {
		yield 'Adding a new role' => [
			3,
			102,
			[ Roles::ROLE_ORGANIZER ],
			[
				101 => [ Roles::ROLE_CREATOR ],
				102 => [ Roles::ROLE_ORGANIZER ],
			]
		];
		yield 'Adding two new roles' => [
			3,
			102,
			[ Roles::ROLE_ORGANIZER, Roles::ROLE_TEST ],
			[
				101 => [ Roles::ROLE_CREATOR ],
				102 => [ Roles::ROLE_ORGANIZER, Roles::ROLE_TEST ],
			]
		];
		yield 'Single role, already there' => [
			3,
			101,
			[ Roles::ROLE_CREATOR ],
			[
				101 => [ Roles::ROLE_CREATOR ]
			]
		];
		yield 'One role already there, one new' => [
			3,
			101,
			[ Roles::ROLE_CREATOR, Roles::ROLE_ORGANIZER ],
			[
				101 => [ Roles::ROLE_CREATOR, Roles::ROLE_ORGANIZER ]
			]
		];
	}

	/**
	 * @param int $eventID
	 * @param array<int,string[]> $organizersToAdd
	 * @param array<int,string[]> $allExpectedOrganizers Full list of expected organizers and their roles (including
	 * ones that weren't changed, excluding deleted). Map of [ user ID => roles ]
	 * @covers ::addOrganizersToEvent
	 * @dataProvider provideAddOrganizersToEvent
	 */
	public function testAddOrganizersToEvent(
		int $eventID,
		array $organizersToAdd,
		array $allExpectedOrganizers
	) {
		$store = new OrganizersStore(
			CampaignEventsServices::getDatabaseHelper()
		);

		$store->addOrganizersToEvent( $eventID, $organizersToAdd );
		$actualOrganizers = $store->getEventOrganizers( $eventID );
		$this->assertSameSize( $allExpectedOrganizers, $actualOrganizers );

		foreach ( $actualOrganizers as $actualOrg ) {
			$userID = $actualOrg->getUser()->getCentralID();
			$this->assertArrayHasKey( $userID, $allExpectedOrganizers );
			$this->assertSame(
				$allExpectedOrganizers[$userID],
				$actualOrg->getRoles(),
				"Roles for user $userID don't match"
			);
		}
	}

	public static function provideAddOrganizersToEvent(): Generator {
		yield 'Adding new organizers' => [
			1,
			[
				104 => [ Roles::ROLE_ORGANIZER ],
				105 => [ Roles::ROLE_ORGANIZER, Roles::ROLE_TEST ],
			],
			[
				101 => [ Roles::ROLE_CREATOR, Roles::ROLE_ORGANIZER ],
				102 => [ Roles::ROLE_ORGANIZER ],
				104 => [ Roles::ROLE_ORGANIZER ],
				105 => [ Roles::ROLE_ORGANIZER, Roles::ROLE_TEST ],
			],
		];
		yield 'Adding a new organizer and changing roles of an existing one' => [
			1,
			[
				102 => [ Roles::ROLE_ORGANIZER, Roles::ROLE_TEST ],
				104 => [ Roles::ROLE_ORGANIZER ],
			],
			[
				101 => [ Roles::ROLE_CREATOR, Roles::ROLE_ORGANIZER ],
				102 => [ Roles::ROLE_ORGANIZER, Roles::ROLE_TEST ],
				104 => [ Roles::ROLE_ORGANIZER ],
			]
		];
		yield 'Restoring a previously-deleted organizers, changing roles of another' => [
			1,
			[
				102 => [ Roles::ROLE_ORGANIZER, Roles::ROLE_TEST ],
				103 => [ Roles::ROLE_ORGANIZER ],
			],
			[
				101 => [ Roles::ROLE_CREATOR, Roles::ROLE_ORGANIZER ],
				102 => [ Roles::ROLE_ORGANIZER, Roles::ROLE_TEST ],
				103 => [ Roles::ROLE_ORGANIZER ],
			]
		];
		yield 'Adding new organizers, restoring some previously deleted organizers, changing roles' => [
			1,
			[
				101 => [ Roles::ROLE_CREATOR ],
				103 => [ Roles::ROLE_ORGANIZER ],
				104 => [ Roles::ROLE_ORGANIZER, Roles::ROLE_TEST ],
			],
			[
				101 => [ Roles::ROLE_CREATOR ],
				102 => [ Roles::ROLE_ORGANIZER ],
				103 => [ Roles::ROLE_ORGANIZER ],
				104 => [ Roles::ROLE_ORGANIZER, Roles::ROLE_TEST ],
			]
		];
	}

	/**
	 * @covers ::addOrganizersToEvent
	 */
	public function testAddOrganizersToEvent__notCreator() {
		$store = new OrganizersStore(
			CampaignEventsServices::getDatabaseHelper()
		);
		$notCreatorID = 102;
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( "User $notCreatorID is not the event creator" );
		$store->addOrganizersToEvent( 1, [ $notCreatorID => [ Roles::ROLE_CREATOR ] ] );
	}

	/**
	 * @param int $eventID
	 * @param string $includeDeleted
	 * @param int|null $expected User ID or null if the event is expected not to have a creator.
	 * @covers ::getEventCreator
	 * @covers ::rowToOrganizerObject
	 * @dataProvider provideGetEventCreator
	 */
	public function testGetEventCreator( int $eventID, string $includeDeleted, ?int $expected ) {
		$store = new OrganizersStore(
			CampaignEventsServices::getDatabaseHelper()
		);

		$eventCreator = $store->getEventCreator( $eventID, $includeDeleted );
		if ( $expected === null ) {
			$this->assertNull( $eventCreator );
		} else {
			$this->assertSame( $expected, $eventCreator->getUser()->getCentralID() );
		}
	}

	public static function provideGetEventCreator(): Generator {
		yield 'Has a creator, include deleted' => [ 1, OrganizersStore::GET_CREATOR_INCLUDE_DELETED, 101 ];
		yield 'Has a creator, exclude deleted' => [ 1, OrganizersStore::GET_CREATOR_EXCLUDE_DELETED, 101 ];
		yield 'Creator was deleted, include deleted' => [ 10, OrganizersStore::GET_CREATOR_INCLUDE_DELETED, 101 ];
		yield 'Creator was deleted, exclude deleted' => [ 10, OrganizersStore::GET_CREATOR_EXCLUDE_DELETED, null ];
		yield 'Does not have a creator at all, include deleted' => [
			2,
			OrganizersStore::GET_CREATOR_INCLUDE_DELETED,
			null
		];
		yield 'Does not have a creator at all, exclude deleted' => [
			2,
			OrganizersStore::GET_CREATOR_EXCLUDE_DELETED,
			null
		];
	}

	/**
	 * @param int $eventID
	 * @param int[] $IDSToKeep
	 * @param array<int,string[]> $expectedRemaining Full list of expected remaining organizers and their roles.
	 * Map of [ user ID => roles ]
	 * @covers ::removeOrganizersFromEventExcept
	 * @dataProvider provideOrganizersToRemove
	 */
	public function testRemoveOrganizersFromEventExcept( int $eventID, array $IDSToKeep, array $expectedRemaining ) {
		$store = new OrganizersStore(
			CampaignEventsServices::getDatabaseHelper()
		);

		$store->removeOrganizersFromEventExcept( $eventID, $IDSToKeep );
		$actualRemaining = $store->getEventOrganizers( $eventID );
		$this->assertSameSize( $expectedRemaining, $actualRemaining );
		foreach ( $actualRemaining as $organizer ) {
			$userID = $organizer->getUser()->getCentralID();
			$this->assertArrayHasKey( $userID, $expectedRemaining );
			$this->assertSame(
				$expectedRemaining[$userID],
				$organizer->getRoles(),
				"Roles for user $userID don't match"
			);
		}
	}

	public static function provideOrganizersToRemove(): Generator {
		yield 'Removing all but the creator' => [
			1,
			[ 101 ],
			[
				101 => [ Roles::ROLE_CREATOR, Roles::ROLE_ORGANIZER ]
			]
		];
		yield 'Removing all but one that is not the creator' => [
			1,
			[ 102 ],
			[
				102 => [ Roles::ROLE_ORGANIZER ]
			]
		];
		yield 'Not actually removing anyone' => [
			1,
			[ 101, 102 ],
			[
				101 => [ Roles::ROLE_CREATOR, Roles::ROLE_ORGANIZER ],
				102 => [ Roles::ROLE_ORGANIZER ]
			]
		];
		yield 'Removing everyone' => [
			1,
			[ 100000 ],
			[]
		];
	}

	public function testGetEventOrganizers__limit() {
		$store = new OrganizersStore(
			CampaignEventsServices::getDatabaseHelper()
		);
		$allOrganizers = $store->getEventOrganizers( 1 );
		$this->assertCount( 2, $allOrganizers, 'precondition: total count' );
		$this->assertSame( 101, $allOrganizers[0]->getUser()->getCentralID(), 'precondition: first user ID' );
		$this->assertCount( 2, $allOrganizers[0]->getRoles(), 'precondition: first user roles' );
		$this->assertSame( 102, $allOrganizers[1]->getUser()->getCentralID(), 'precondition: second user ID' );
		$this->assertCount( 1, $allOrganizers[1]->getRoles(), 'precondition: second user roles' );
		$limit = 1;
		$partialOrganizers = $store->getEventOrganizers( 1, $limit );
		$this->assertCount( $limit, $partialOrganizers );
		$this->assertSame( 101, $partialOrganizers[0]->getUser()->getCentralID(), 'user ID' );
		$this->assertCount( 2, $partialOrganizers[0]->getRoles(), 'roles' );
	}

	/**
	 * @param int $event
	 * @param int $expected
	 * @dataProvider provideOrganizerCount
	 * @covers ::getOrganizerCountForEvent
	 */
	public function testGetOrganizerCountForEvent( int $event, int $expected ) {
		$store = new OrganizersStore(
			CampaignEventsServices::getDatabaseHelper()
		);
		$this->assertSame( $expected, $store->getOrganizerCountForEvent( $event ) );
	}

	public static function provideOrganizerCount(): array {
		return [
			'One organizer' => [ 3, 1 ],
			'Two organizers (third one deleted)' => [ 1, 2 ],
			'No organizers' => [ 1000, 0 ],
		];
	}
}
