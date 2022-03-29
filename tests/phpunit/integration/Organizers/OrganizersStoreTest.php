<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Organizers;

use Generator;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsUser;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWikiIntegrationTestCase;

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
	 * @inheritDoc
	 */
	public function addDBData(): void {
		$this->db->insert( 'ce_organizers', [ 'ceo_event_id' => 1, 'ceo_user_id' => 101, 'ceo_role_id' => 1 ] );
		$this->db->insert( 'ce_organizers', [ 'ceo_event_id' => 1, 'ceo_user_id' => 102, 'ceo_role_id' => 2 ] );
	}

	/**
	 * @param int $eventID
	 * @param array $expectedIDs
	 * @covers ::getEventOrganizers
	 * @dataProvider provideOrganizers
	 */
	public function testGetEventOrganizers( int $eventID, array $expectedIDs ) {
		$expectedUsers = [];
		foreach ( $expectedIDs as $id ) {
			$expectedUsers[$id] = $this->createMock( ICampaignsUser::class );
		}

		$userLookup = $this->createMock( CampaignsCentralUserLookup::class );
		$userLookup->method( 'getLocalUser' )->willReturnCallback( function ( int $centralID ) use ( $expectedUsers ) {
			return $expectedUsers[$centralID] ?? $this->createMock( ICampaignsUser::class );
		} );
		$store = new OrganizersStore(
			CampaignEventsServices::getDatabaseHelper(),
			$userLookup
		);
		$this->assertSame( array_values( $expectedUsers ), $store->getEventOrganizers( $eventID ) );
	}

	public function provideOrganizers(): Generator {
		yield 'Has organizers' => [ 1, [ 101, 102 ] ];
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
		$user = $this->createMock( ICampaignsUser::class );
		$userLookup = $this->createMock( CampaignsCentralUserLookup::class );
		$userLookup->method( 'getCentralID' )
			->with( $user )
			->willReturn( $userID );
		$store = new OrganizersStore(
			CampaignEventsServices::getDatabaseHelper(),
			$userLookup
		);
		$this->assertSame( $expected, $store->isEventOrganizer( $eventID, $user ) );
	}

	public function provideIsOrganizer(): Generator {
		yield 'Yes, first' => [ 1, 101, true ];
		yield 'Yes, second' => [ 1, 102, true ];
		yield 'Nope' => [ 2, 101, false ];
	}
}
