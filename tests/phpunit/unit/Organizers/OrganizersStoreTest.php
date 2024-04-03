<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Organizers;

use InvalidArgumentException;
use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Organizers\Roles;
use MediaWikiUnitTestCase;
use ReflectionClass;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\TestingAccessWrapper;

/**
 * @group Test
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore
 * @covers ::__construct()
 */
class OrganizersStoreTest extends MediaWikiUnitTestCase {
	private function getOrganizersStore(): OrganizersStore {
		$db = $this->createMock( IDatabase::class );
		$db->method( 'selectRow' )->willReturn( (object)[
			'ceo_id' => 1,
			'ceo_user_id' => 1,
			'ceo_roles' => 1,
			'ceo_agreement_timestamp' => null
		] );
		$dbHelper = $this->createMock( CampaignsDatabaseHelper::class );
		$dbHelper->method( 'getDBConnection' )->willReturn( $db );
		return new OrganizersStore( $dbHelper );
	}

	/**
	 * @covers ::addOrganizerToEvent
	 */
	public function testAddOrganizerToEvent__invalidRole() {
		$store = $this->getOrganizersStore();

		$this->expectException( InvalidArgumentException::class );
		$store->addOrganizerToEvent( 1, 1, [ 'SOME-INVALID-ROLE' ] );
	}

	/**
	 * @covers ::addOrganizersToEvent
	 */
	public function testAddOrganizersToEvent__invalidRole() {
		$store = $this->getOrganizersStore();

		$this->expectException( InvalidArgumentException::class );
		$store->addOrganizersToEvent( 1, [ 1 => [ 'SOME-INVALID-ROLE' ] ] );
	}

	/**
	 * Tests that all the role constants are mapped to a DB value.
	 * @coversNothing
	 */
	public function testMapping() {
		$expected = [];
		$rolesRefl = new ReflectionClass( Roles::class );
		foreach ( $rolesRefl->getConstants() as $name => $val ) {
			if ( str_starts_with( $name, 'ROLE_' ) ) {
				$expected[] = $val;
			}
		}
		$actualMap = TestingAccessWrapper::constant( OrganizersStore::class, 'ROLES_MAP' );

		$this->assertArrayEquals( $expected, array_keys( $actualMap ) );
	}
}
