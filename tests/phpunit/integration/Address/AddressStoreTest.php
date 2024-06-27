<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Address;

use Generator;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWikiIntegrationTestCase;

/**
 * @group Test
 * @group Database
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Address\AddressStore
 * @covers ::__construct()
 */
class AddressStoreTest extends MediaWikiIntegrationTestCase {
	/**
	 * @inheritDoc
	 */
	public function addDBData(): void {
		$rows = [
			[
				'cea_full_address' => "Address \n Country",
				'cea_country' => 'Country',
			],
			[
				'cea_full_address' => "Address without country \n ",
				'cea_country' => '',
			],
		];
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'ce_address' )
			->rows( $rows )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * @param string $fullAddress
	 * @param string|null $country
	 * @param int $expected
	 * @covers ::acquireAddressID
	 * @dataProvider provideAcquireAddressID
	 */
	public function testAcquireAddressID( string $fullAddress, ?string $country, int $expected ) {
		$store = CampaignEventsServices::getAddressStore();
		$this->assertSame( $expected, $store->acquireAddressID( $fullAddress, $country ) );
	}

	public static function provideAcquireAddressID(): Generator {
		yield 'Existing address' => [ "Address \n Country", 'Country', 1 ];
		yield 'Existing address without country' => [ "Address without country \n ", null, 2 ];
		yield 'New address' => [ "This is a new address! \n Country", 'Country', 3 ];
	}
}
