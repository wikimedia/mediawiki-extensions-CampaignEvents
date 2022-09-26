<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Organizers;

use Generator;
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
			[ 'user' => 101, 'roles' => [ Roles::ROLE_CREATOR, Roles::ROLE_ORGANIZER ], 'deleted' => false ],
			[ 'user' => 102, 'roles' => [ Roles::ROLE_ORGANIZER ], 'deleted' => false ],
			[ 'user' => 103, 'roles' => [ Roles::ROLE_ORGANIZER ], 'deleted' => true ],
		],
		3 => [
			[ 'user' => 101, 'roles' => [ Roles::ROLE_CREATOR ], 'deleted' => false ],
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
					'ceo_deleted_at' => $data['deleted'] ? $ts : null
				];
			}
		}

		$this->db->insert( 'ce_organizers', $rows );
	}

	/**
	 * @param int $eventID
	 * @param array $expectedIDs
	 * @covers ::getEventOrganizers
	 * @dataProvider provideOrganizers
	 */
	public function testGetEventOrganizers( int $eventID, array $expectedIDs ) {
		$store = new OrganizersStore(
			CampaignEventsServices::getDatabaseHelper()
		);
		$actualOrganizers = $store->getEventOrganizers( $eventID );
		$actualUserIDs = [];
		foreach ( $actualOrganizers as $organizer ) {
			$actualUserIDs[] = $organizer->getUser()->getCentralID();
		}
		$this->assertSame( $expectedIDs, $actualUserIDs );
	}

	public function provideOrganizers(): Generator {
		yield 'Has organizers, including a deleted one' => [ 1, [ 101, 102 ] ];
		yield 'Does not have organizers' => [ 2, [] ];
	}

	/**
	 * @param int $eventID
	 * @param int $userID
	 * @param bool $expected
	 * @covers ::isEventOrganizer
	 * @dataProvider provideIsOrganizer
	 */
	public function testIsEventOrganizer( int $eventID, int $userID, bool $expected ) {
		$user = new CentralUser( $userID );
		$store = new OrganizersStore(
			CampaignEventsServices::getDatabaseHelper()
		);
		$this->assertSame( $expected, $store->isEventOrganizer( $eventID, $user ) );
	}

	public function provideIsOrganizer(): Generator {
		yield 'Yes, creator' => [ 1, 101, true ];
		yield 'Yes, secondary role' => [ 1, 102, true ];
		yield 'No, deleted' => [ 1, 103, false ];
		yield 'Nope' => [ 2, 101, false ];
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
		$user = new CentralUser( $userID );
		$store = new OrganizersStore(
			CampaignEventsServices::getDatabaseHelper()
		);

		$store->addOrganizerToEvent( $eventID, $user, $roles );

		$actualOrganizers = $store->getEventOrganizers( $eventID );
		$this->assertCount( count( $expectedOrganizers ), $actualOrganizers );
		foreach ( $actualOrganizers as $actualOrg ) {
			$userID = $actualOrg->getUser()->getCentralID();
			$this->assertArrayHasKey( $userID, $expectedOrganizers );
			$this->assertSame( $expectedOrganizers[$userID], $actualOrg->getRoles(), "Roles for user $userID" );
		}
	}

	public function provideOrganizersToAdd(): Generator {
		yield 'Adding a new role' => [
			2,
			101,
			[ Roles::ROLE_CREATOR ],
			[
				101 => [ Roles::ROLE_CREATOR ]
			]
		];
		yield 'Adding two new roles' => [
			2,
			101,
			[ Roles::ROLE_CREATOR, Roles::ROLE_ORGANIZER ],
			[
				101 => [ Roles::ROLE_CREATOR, Roles::ROLE_ORGANIZER ]
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

	public function provideOrganizerCount(): array {
		return [
			'One organizer' => [ 3, 1 ],
			'Two organizers (third one deleted)' => [ 1, 2 ],
			'No organizers' => [ 1000, 0 ],
		];
	}
}
