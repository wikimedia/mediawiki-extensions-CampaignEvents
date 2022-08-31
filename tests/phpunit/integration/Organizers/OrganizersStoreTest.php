<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Organizers;

use Generator;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Organizers\Roles;
use MediaWikiIntegrationTestCase;
use stdClass;
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

	/**
	 * @param bool $includeTimestamps If true, ceo_created_at and ceo_deleted_at won't be included. This is
	 * useful when the method is called from a data provider, when the database cannot be accessed.
	 * @return array
	 */
	private function getDefaultRows( bool $includeTimestamps = true ): array {
		$rows = [
			[
				'ceo_event_id' => 1,
				'ceo_user_id' => 101,
				'ceo_role_id' => 1,
			],
			[
				'ceo_event_id' => 1,
				'ceo_user_id' => 102,
				'ceo_role_id' => 2,
			],
			[
				'ceo_event_id' => 1,
				'ceo_user_id' => 103,
				'ceo_role_id' => 2,
			],
		];
		if ( $includeTimestamps ) {
			$ts = $this->db->timestamp();
			$rows[0]['ceo_created_at'] = $ts;
			$rows[0]['ceo_deleted_at'] = null;
			$rows[1]['ceo_created_at'] = $ts;
			$rows[1]['ceo_deleted_at'] = null;
			$rows[2]['ceo_created_at'] = $ts;
			$rows[2]['ceo_deleted_at'] = $ts;
		}
		return $rows;
	}

	/**
	 * @inheritDoc
	 */
	public function addDBData(): void {
		$this->db->insert( 'ce_organizers', $this->getDefaultRows() );
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
		yield 'Yes, first' => [ 1, 101, true ];
		yield 'Yes, second' => [ 1, 102, true ];
		yield 'No, deleted' => [ 1, 103, false ];
		yield 'Nope' => [ 2, 101, false ];
	}

	/**
	 * @param int $eventID
	 * @param int $userID
	 * @param string[] $roles
	 * @param stdClass[] $expectedRows NOTE: These rows do not include ceo_created_at and ceo_deleted_at, because we
	 *   can't use $this->db->timestamp() in the data provider, and we also don't need to check the values for equality.
	 * @covers ::addOrganizerToEvent
	 * @dataProvider provideOrganizersToAdd
	 */
	public function testAddOrganizerToEvent( int $eventID, int $userID, array $roles, array $expectedRows ) {
		$user = new CentralUser( $userID );
		$store = new OrganizersStore(
			CampaignEventsServices::getDatabaseHelper()
		);

		$store->addOrganizerToEvent( $eventID, $user, $roles );

		// $this->assertSelect() strips string keys, which complicates things unnecessarily.
		$dbData = $this->db->select( 'ce_organizers', [ 'ceo_event_id', 'ceo_user_id', 'ceo_role_id' ], [] );
		$this->assertArrayEquals( $expectedRows, array_map( 'get_object_vars', iterator_to_array( $dbData ) ) );
	}

	public function provideOrganizersToAdd(): Generator {
		$rolesMap = TestingAccessWrapper::constant( OrganizersStore::class, 'ROLES_MAP' );
		/**
		 * Changes all values to strings, since that's what SELECT will return.
		 */
		$strVal = static function ( array $rows ): array {
			$ret = [];
			foreach ( $rows as $row ) {
				$ret[] = array_map( 'strval', $row );
			}
			return $ret;
		};
		yield 'Adding a new role' => [
			2,
			101,
			[ Roles::ROLE_CREATOR ],
			$strVal( array_merge(
				$this->getDefaultRows( false ),
				[ [
					'ceo_event_id' => 2,
					'ceo_user_id' => 101,
					'ceo_role_id' => $rolesMap[Roles::ROLE_CREATOR],
				] ]
			) )
		];
		yield 'Adding two new roles' => [
			2,
			101,
			[ Roles::ROLE_CREATOR, Roles::ROLE_ORGANIZER ],
			$strVal( array_merge(
				$this->getDefaultRows( false ),
				[
					[
						'ceo_event_id' => 2,
						'ceo_user_id' => 101,
						'ceo_role_id' => $rolesMap[Roles::ROLE_CREATOR],
					],
					[
						'ceo_event_id' => 2,
						'ceo_user_id' => 101,
						'ceo_role_id' => $rolesMap[Roles::ROLE_ORGANIZER],
					]
				]
			) )
		];
		yield 'Single role, already there' => [
			1,
			101,
			[ Roles::ROLE_CREATOR ],
			$strVal( $this->getDefaultRows( false ) )
		];
		yield 'One role already there, one new' => [
			1,
			101,
			[ Roles::ROLE_CREATOR, Roles::ROLE_ORGANIZER ],
			$strVal( array_merge(
				$this->getDefaultRows( false ),
				[ [
					'ceo_event_id' => 1,
					'ceo_user_id' => 101,
					'ceo_role_id' => $rolesMap[Roles::ROLE_ORGANIZER],
				] ]
			) )
		];
	}

	public function testGetEventOrganizers__limit() {
		$store = new OrganizersStore(
			CampaignEventsServices::getDatabaseHelper()
		);
		$this->assertCount( 2, $store->getEventOrganizers( 1 ), 'precondition' );
		$limit = 1;
		$this->assertCount( $limit, $store->getEventOrganizers( 1, $limit ) );
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
			'Two organizers (third one deleted)' => [ 1, 2 ],
			'No organizers' => [ 1000, 0 ],
		];
	}
}
