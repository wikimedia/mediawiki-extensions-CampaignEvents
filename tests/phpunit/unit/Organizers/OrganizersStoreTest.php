<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Organizers;

use InvalidArgumentException;
use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsUser;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Organizers\Roles;
use MediaWikiUnitTestCase;
use ReflectionClass;
use Wikimedia\TestingAccessWrapper;

/**
 * @group Test
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore
 * @covers ::__construct()
 */
class OrganizersStoreTest extends MediaWikiUnitTestCase {
	/**
	 * @covers ::addOrganizerToEvent
	 */
	public function testAddOrganizerToEvent__invalidRole() {
		$store = new OrganizersStore(
			$this->createMock( CampaignsDatabaseHelper::class ),
			$this->createMock( CampaignsCentralUserLookup::class )
		);

		$this->expectException( InvalidArgumentException::class );
		$store->addOrganizerToEvent( 1, $this->createMock( ICampaignsUser::class ), [ 'SOME-INVALID-ROLE' ] );
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
