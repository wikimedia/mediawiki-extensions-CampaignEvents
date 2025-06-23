<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Address;

use Generator;
use LogicException;
use MediaWiki\Extension\CampaignEvents\Address\Address;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWikiIntegrationTestCase;
use RuntimeException;
use stdClass;

/**
 * @group Test
 * @group Database
 * @covers \MediaWiki\Extension\CampaignEvents\Address\AddressStore
 */
class AddressStoreTest extends MediaWikiIntegrationTestCase {
	private const EVENT_WITH_ADDRESS = 5001;
	private const STORED_ADDRESS_ID = 1;
	private const STORED_ADDRESS = 'Test address';
	private const STORED_COUNTRY = 'Test country';
	private const STORED_FULL_ADDRESS = self::STORED_ADDRESS . " \n " . self::STORED_COUNTRY;
	private const STORED_ADDRESS_ROW = [
		'cea_id' => self::STORED_ADDRESS_ID,
		'cea_full_address' => self::STORED_FULL_ADDRESS,
		'cea_country' => self::STORED_COUNTRY,
		'cea_country_code' => null,
	];
	private const ADDRESS_ENTRY_COUNT = 2;

	/**
	 * @inheritDoc
	 */
	public function addDBData(): void {
		$addressRows = [
			self::STORED_ADDRESS_ROW,
			[
				'cea_id' => 2,
				'cea_full_address' => "Address without country \n ",
				'cea_country' => '',
				'cea_country_code' => null,
			],
		];
		if ( count( $addressRows ) !== self::ADDRESS_ENTRY_COUNT ) {
			throw new LogicException( 'Should update number of stored address entries' );
		}
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'ce_address' )
			->rows( $addressRows )
			->caller( __METHOD__ )
			->execute();

		$joinRows = [
			[
				'ceea_event' => self::EVENT_WITH_ADDRESS,
				'ceea_address' => self::STORED_ADDRESS_ID,
			]
		];
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'ce_event_address' )
			->rows( $joinRows )
			->caller( __METHOD__ )
			->execute();
	}

	/** @dataProvider provideUpdateAddresses */
	public function testUpdateAddresses(
		int $eventID,
		?string $meetingAddress,
		?string $meetingCountry,
		int $expectsJoinRow,
		?stdClass $expectedAddressRow
	) {
		$store = CampaignEventsServices::getAddressStore();
		$store->updateAddresses( $meetingAddress, $meetingCountry, $eventID );

		$addressRowIDs = $this->getDb()->selectFieldValues(
			'ce_event_address',
			'ceea_address',
			[ 'ceea_event' => $eventID ]
		);
		$this->assertCount( $expectsJoinRow, $addressRowIDs, 'Number of rows in the `ce_event_address` table' );

		if ( !$addressRowIDs ) {
			$this->assertNull( $expectedAddressRow, 'Address row not found' );
			return;
		}

		if ( count( $addressRowIDs ) !== 1 ) {
			$this->fail( 'Should not happen (unimplemented)' );
		}

		$addressRow = $this->getDb()->selectRow( 'ce_address', '*', [ 'cea_id' => $addressRowIDs[0] ] );
		$this->assertEquals(
			$expectedAddressRow,
			$addressRow,
			'Row in the `ce_address` table'
		);
	}

	public static function provideUpdateAddresses() {
		$eventWithoutAddress = 42;
		$newTestAddress = 'Some NEW address';
		$newTestCountry = 'Some NEW country';
		$nextAddressID = self::ADDRESS_ENTRY_COUNT + 1;

		yield 'No previous row, no address, no country' => [
			$eventWithoutAddress,
			null,
			null,
			0,
			null
		];
		yield 'No previous row, address, no country' => [
			$eventWithoutAddress,
			$newTestAddress,
			null,
			1,
			(object)[
				'cea_id' => $nextAddressID,
				'cea_full_address' => "$newTestAddress \n ",
				'cea_country' => null,
				'cea_country_code' => null,
			]
		];
		yield 'No previous row, country, no address' => [
			$eventWithoutAddress,
			null,
			$newTestCountry,
			1,
			(object)[
				'cea_id' => $nextAddressID,
				'cea_full_address' => " \n $newTestCountry",
				'cea_country' => $newTestCountry,
				'cea_country_code' => null,
			]
		];
		yield 'No previous row, address and country' => [
			$eventWithoutAddress,
			$newTestAddress,
			$newTestCountry,
			1,
			(object)[
				'cea_id' => $nextAddressID,
				'cea_full_address' => "$newTestAddress \n $newTestCountry",
				'cea_country' => $newTestCountry,
				'cea_country_code' => null,
			]
		];

		yield 'Replace previous row, no address, no country' => [
			self::EVENT_WITH_ADDRESS,
			null,
			null,
			0,
			null
		];
		yield 'Replace previous row, address, no country' => [
			self::EVENT_WITH_ADDRESS,
			$newTestAddress,
			null,
			1,
			(object)[
				'cea_id' => $nextAddressID,
				'cea_full_address' => "$newTestAddress \n ",
				'cea_country' => null,
				'cea_country_code' => null,
			]
		];
		yield 'Replace previous row, country, no address' => [
			self::EVENT_WITH_ADDRESS,
			null,
			$newTestCountry,
			1,
			(object)[
				'cea_id' => $nextAddressID,
				'cea_full_address' => " \n $newTestCountry",
				'cea_country' => $newTestCountry,
				'cea_country_code' => null,
			]
		];
		yield 'Replace previous row, address and country' => [
			self::EVENT_WITH_ADDRESS,
			$newTestAddress,
			$newTestCountry,
			1,
			(object)[
				'cea_id' => $nextAddressID,
				'cea_full_address' => "$newTestAddress \n $newTestCountry",
				'cea_country' => $newTestCountry,
				'cea_country_code' => null,
			]
		];

		yield 'Same as previous row' => [
			self::EVENT_WITH_ADDRESS,
			self::STORED_ADDRESS,
			self::STORED_COUNTRY,
			1,
			(object)self::STORED_ADDRESS_ROW
		];
	}

	/**
	 * @param string $fullAddress
	 * @param string|null $country
	 * @param int $expected
	 * @dataProvider provideAcquireAddressID
	 */
	public function testAcquireAddressID( string $fullAddress, ?string $country, int $expected ) {
		$store = CampaignEventsServices::getAddressStore();
		$this->assertSame( $expected, $store->acquireAddressID( $fullAddress, $country ) );
	}

	public static function provideAcquireAddressID(): Generator {
		yield 'Existing address' => [ self::STORED_FULL_ADDRESS, 'Country', 1 ];
		yield 'Existing address without country' => [ "Address without country \n ", null, 2 ];
		yield 'New address' => [ "This is a new address! \n Country", 'Country', 3 ];
	}

	/** @dataProvider provideGetEventAddress */
	public function testGetEventAddress( int $eventID, ?Address $expected ) {
		$this->assertEquals(
			$expected,
			CampaignEventsServices::getAddressStore()->getEventAddress( $this->getDb(), $eventID )
		);
	}

	public static function provideGetEventAddress() {
		yield 'Has address' => [
			self::EVENT_WITH_ADDRESS,
			new Address(
				self::STORED_ADDRESS,
				self::STORED_COUNTRY,
			),
		];
		yield 'Does not have an address' => [ 99999999, null ];
	}

	private function insertMultipleAddressesForEvent( int $eventID ): void {
		$db = $this->getDb();

		$addresses = [
			[
				'cea_id' => 101,
				'cea_full_address' => 'Full address 1',
				'cea_country' => 'Country 1',
			],
			[
				'cea_id' => 102,
				'cea_full_address' => 'Full address 2',
				'cea_country' => 'Country 2',
			]
		];
		$db->newInsertQueryBuilder()
			->insertInto( 'ce_address' )
			->rows( $addresses )
			->caller( __METHOD__ )
			->execute();

		$eventAddresses = [
			[
				'ceea_event' => $eventID,
				'ceea_address' => 101,
			],
			[
				'ceea_event' => $eventID,
				'ceea_address' => 102,
			]
		];
		$db->newInsertQueryBuilder()
			->insertInto( 'ce_event_address' )
			->rows( $eventAddresses )
			->caller( __METHOD__ )
			->execute();
	}

	public function testGetEventAddress__eventWithMoreThanOneAddress() {
		$eventID = 6001;
		$this->insertMultipleAddressesForEvent( $eventID );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Events should have only one address' );
		CampaignEventsServices::getAddressStore()->getEventAddress( $this->getDb(), $eventID );
	}

	public function testGetAddressesForEvents() {
		$expected = [
			self::EVENT_WITH_ADDRESS => new Address(
				self::STORED_ADDRESS,
				self::STORED_COUNTRY
			),
		];
		$actual = CampaignEventsServices::getAddressStore()
			->getAddressesForEvents( $this->getDb(), [ self::EVENT_WITH_ADDRESS, 99999999 ] );
		$this->assertEquals( $expected, $actual );
	}

	public function testGetAddressesForEvents__eventWithMoreThanOneAddress() {
		$eventID = 6001;
		$this->insertMultipleAddressesForEvent( $eventID );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( "Event $eventID should have only one address" );
		CampaignEventsServices::getAddressStore()
			->getAddressesForEvents( $this->getDb(), [ self::EVENT_WITH_ADDRESS, $eventID ] );
	}
}
