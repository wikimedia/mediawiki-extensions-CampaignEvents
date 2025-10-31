<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Address;

use Generator;
use LogicException;
use MediaWiki\Extension\CampaignEvents\Address\Address;
use MediaWiki\Extension\CampaignEvents\Address\AddressStore;
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
	private const STORED_COUNTRY = 'France';
	private const STORED_COUNTRY_CODE = 'FR';

	private const EVENT_WITH_ADDRESS_WITHOUT_ADDRESS = 5003;
	private const STORED_COUNTRY_WITHOUT_ADDRESS = 'Australia';
	private const STORED_COUNTRY_CODE_WITHOUT_ADDRESS = 'AU';
	private const STORED_COUNTRY_WITHOUT_ADDRESS_ID = 2;

	private const ADDRESS_ENTRY_COUNT = 2;
	private const NEXT_ADDRESS_ID = self::ADDRESS_ENTRY_COUNT + 1;

	private static function getStoredAddressRow(): array {
		return [
			'cea_id' => self::STORED_ADDRESS_ID,
			'cea_full_address' => self::STORED_ADDRESS,
			'cea_country_code' => self::STORED_COUNTRY_CODE,
		];
	}

	private static function getStoredRowWithoutAddress(): array {
		return [
			'cea_id' => self::STORED_COUNTRY_WITHOUT_ADDRESS_ID,
			'cea_full_address' => '',
			'cea_country_code' => self::STORED_COUNTRY_CODE_WITHOUT_ADDRESS,
		];
	}

	/** Using this instead of addDBData so tests can override the database content. */
	public function insertDefaultDBData(): void {
		$addressRows = [
			self::getStoredAddressRow(),
			self::getStoredRowWithoutAddress(),
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
			],
			[
				'ceea_event' => self::EVENT_WITH_ADDRESS_WITHOUT_ADDRESS,
				'ceea_address' => self::STORED_COUNTRY_WITHOUT_ADDRESS_ID,
			],
		];
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'ce_event_address' )
			->rows( $joinRows )
			->caller( __METHOD__ )
			->execute();
	}

	private function getAddressStore(): AddressStore {
		return new AddressStore(
			CampaignEventsServices::getDatabaseHelper(),
			MIGRATION_NEW
		);
	}

	/** @dataProvider provideUpdateAddresses */
	public function testUpdateAddresses(
		int $eventID,
		?Address $address,
		int $expectsJoinRow,
		?stdClass $expectedAddressRow,
		?string $expectedException = null
	) {
		$this->insertDefaultDBData();
		$store = $this->getAddressStore();

		if ( $expectedException !== null ) {
			$this->expectException( RuntimeException::class );
			$this->expectExceptionMessage( $expectedException );
		}
		$store->updateAddresses( $address, $eventID );
		if ( $expectedException !== null ) {
			// Let PHPUnit fail if no exception was thrown
			return;
		}

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
		$newTestCountry = 'Egypt';
		$newTestCountryCode = 'EG';

		yield "No previous row, no address" => [
			$eventWithoutAddress,
			null,
			0,
			null,
		];
		yield "No previous row, address, no country, no country code" => [
			$eventWithoutAddress,
			new Address( $newTestAddress, null, null ),
			1,
			(object)[
				'cea_id' => self::NEXT_ADDRESS_ID,
				'cea_full_address' => "$newTestAddress \n ",
				'cea_country_code' => null,
			],
			'Need the country code for WRITE_NEW',
		];
		yield "No previous row, country, no country code, no address" => [
			$eventWithoutAddress,
			new Address( null, $newTestCountry, null ),
			1,
			(object)[
				'cea_id' => self::NEXT_ADDRESS_ID,
				'cea_full_address' => " \n $newTestCountry",
				'cea_country_code' => null,
			],
			'Need the country code for WRITE_NEW',
		];
		yield "No previous row, country code, no country, no address" => [
			$eventWithoutAddress,
			new Address( null, null, $newTestCountryCode ),
			1,
			(object)[
				'cea_id' => self::NEXT_ADDRESS_ID,
				'cea_full_address' => '',
				'cea_country_code' => $newTestCountryCode,
			],
			null,
		];
		yield "No previous row, address and country, no country code" => [
			$eventWithoutAddress,
			new Address( $newTestAddress, $newTestCountry, null ),
			1,
			(object)[
				'cea_id' => self::NEXT_ADDRESS_ID,
				'cea_full_address' => "$newTestAddress \n $newTestCountry",
				'cea_country_code' => null,
			],
			'Need the country code for WRITE_NEW',
		];
		yield "No previous row, address and country code, no country" => [
			$eventWithoutAddress,
			new Address( $newTestAddress, null, $newTestCountryCode ),
			1,
			(object)[
				'cea_id' => self::NEXT_ADDRESS_ID,
				'cea_full_address' => $newTestAddress,
				'cea_country_code' => $newTestCountryCode,
			],
			null,
		];
		yield "No previous row, country and country code, no address" => [
			$eventWithoutAddress,
			new Address( null, $newTestCountry, $newTestCountryCode ),
			1,
			(object)[
				'cea_id' => self::NEXT_ADDRESS_ID,
				'cea_full_address' => '',
				'cea_country_code' => $newTestCountryCode,
			],
			null,
		];
		yield "No previous row, address, country, and country code" => [
			$eventWithoutAddress,
			new Address( $newTestAddress, $newTestCountry, $newTestCountryCode ),
			1,
			(object)[
				'cea_id' => self::NEXT_ADDRESS_ID,
				'cea_full_address' => $newTestAddress,
				'cea_country_code' => $newTestCountryCode,
			],
			null,
		];

		yield "Replace previous row, no address" => [
			self::EVENT_WITH_ADDRESS,
			null,
			0,
			null,
		];
		yield "Replace previous row, address, no country, no country code" => [
			self::EVENT_WITH_ADDRESS,
			new Address( $newTestAddress, null, null ),
			1,
			(object)[
				'cea_id' => self::NEXT_ADDRESS_ID,
				'cea_full_address' => "$newTestAddress \n ",
				'cea_country_code' => null,
			],
			'Need the country code for WRITE_NEW',
		];
		yield "Replace previous row, country, no country code, no address" => [
			self::EVENT_WITH_ADDRESS,
			new Address( null, $newTestCountry, null ),
			1,
			(object)[
				'cea_id' => self::NEXT_ADDRESS_ID,
				'cea_full_address' => " \n $newTestCountry",
				'cea_country_code' => null,
			],
			'Need the country code for WRITE_NEW',
		];
		yield "Replace previous row, country code, no country, no address" => [
			self::EVENT_WITH_ADDRESS,
			new Address( null, null, $newTestCountryCode ),
			1,
			(object)[
				'cea_id' => self::NEXT_ADDRESS_ID,
				'cea_full_address' => '',
				'cea_country_code' => $newTestCountryCode,
			],
			null,
		];
		yield "Replace previous row, address and country, no country code" => [
			self::EVENT_WITH_ADDRESS,
			new Address( $newTestAddress, $newTestCountry, null ),
			1,
			(object)[
				'cea_id' => self::NEXT_ADDRESS_ID,
				'cea_full_address' => "$newTestAddress \n $newTestCountry",
				'cea_country_code' => null,
			],
			'Need the country code for WRITE_NEW',
		];
		yield "Replace previous row, address and country code, no country" => [
			self::EVENT_WITH_ADDRESS,
			new Address( $newTestAddress, null, $newTestCountryCode ),
			1,
			(object)[
				'cea_id' => self::NEXT_ADDRESS_ID,
				'cea_full_address' => $newTestAddress,
				'cea_country_code' => $newTestCountryCode,
			],
			null,
		];
		yield "Replace previous row, country and country code, no address" => [
			self::EVENT_WITH_ADDRESS,
			new Address( null, $newTestCountry, $newTestCountryCode ),
			1,
			(object)[
				'cea_id' => self::NEXT_ADDRESS_ID,
				'cea_full_address' => '',
				'cea_country_code' => $newTestCountryCode,
			],
			null,
		];
		yield "Replace previous row, address, country and country code" => [
			self::EVENT_WITH_ADDRESS,
			new Address( $newTestAddress, $newTestCountry, $newTestCountryCode ),
			1,
			(object)[
				'cea_id' => self::NEXT_ADDRESS_ID,
				'cea_full_address' => $newTestAddress,
				'cea_country_code' => $newTestCountryCode,
			],
			null,
		];

		yield "Same as previous row with address and country, pass country but not code" => [
			self::EVENT_WITH_ADDRESS,
			new Address( self::STORED_ADDRESS, self::STORED_COUNTRY, null ),
			1,
			(object)self::getStoredAddressRow(),
			'Need the country code for WRITE_NEW',
		];
		yield "Same as previous row with address and country, pass country code but not country" => [
			self::EVENT_WITH_ADDRESS,
			new Address( self::STORED_ADDRESS, null, self::STORED_COUNTRY_CODE ),
			1,
			(object)self::getStoredAddressRow(),
			null,
		];
		yield "Same as previous row with address and country, pass country and country code" => [
			self::EVENT_WITH_ADDRESS,
			new Address( self::STORED_ADDRESS, self::STORED_COUNTRY, self::STORED_COUNTRY_CODE ),
			1,
			(object)self::getStoredAddressRow(),
			null,
		];

		yield "Same as previous row without address, pass country but not code" => [
			self::EVENT_WITH_ADDRESS,
			new Address( null, self::STORED_COUNTRY_WITHOUT_ADDRESS, null ),
			1,
			(object)self::getStoredRowWithoutAddress(),
			'Need the country code for WRITE_NEW',
		];
		yield "Same as previous row without address, pass country code but not country" => [
			self::EVENT_WITH_ADDRESS,
			new Address( null, null, self::STORED_COUNTRY_CODE_WITHOUT_ADDRESS ),
			1,
			// This works because we hardcode the English name.
			(object)self::getStoredRowWithoutAddress(),
			null,
		];
		yield "Same as previous row without address, pass country and country code" => [
			self::EVENT_WITH_ADDRESS,
			new Address(
				null,
				self::STORED_COUNTRY_WITHOUT_ADDRESS,
				self::STORED_COUNTRY_CODE_WITHOUT_ADDRESS
			),
			1,
			(object)self::getStoredRowWithoutAddress(),
			null,
		];
	}

	/**
	 * @dataProvider provideAcquireAddressID
	 */
	public function testAcquireAddressID(
		Address $address,
		int $expected,
		?string $expectedException = null
	) {
		$this->insertDefaultDBData();
		$store = $this->getAddressStore();

		if ( $expectedException !== null ) {
			$this->expectException( RuntimeException::class );
			$this->expectExceptionMessage( $expectedException );
		}
		$this->assertSame( $expected, $store->acquireAddressID( $address ) );
	}

	public static function provideAcquireAddressID(): Generator {
		yield "Existing full address, pass country and country code" => [
			new Address( self::STORED_ADDRESS, self::STORED_COUNTRY, self::STORED_COUNTRY_CODE ),
			self::STORED_ADDRESS_ID,
			null,
		];
		yield "Existing full address, pass country but no country code" => [
			new Address( self::STORED_ADDRESS, self::STORED_COUNTRY, null ),
			self::STORED_ADDRESS_ID,
			'Need the country code for WRITE_NEW',
		];
		yield "Existing full address, pass country code but no country" => [
			new Address( self::STORED_ADDRESS, null, self::STORED_COUNTRY_CODE ),
			self::STORED_ADDRESS_ID,
			null,
		];

		yield "Existing address but with different country, pass country and country code" => [
			new Address( self::STORED_ADDRESS, 'Egypt', 'EG' ),
			self::NEXT_ADDRESS_ID,
			null,
		];
		yield "Existing address but with different country, pass country but no country code" => [
			new Address( self::STORED_ADDRESS, 'Egypt', null ),
			self::NEXT_ADDRESS_ID,
			'Need the country code for WRITE_NEW',
		];
		yield "Existing address but with different country, pass country code but no country" => [
			new Address( self::STORED_ADDRESS, null, 'EG' ),
			self::NEXT_ADDRESS_ID,
			null,
		];

		yield "Existing country without address, pass country and country code" => [
			new Address(
				null,
				self::STORED_COUNTRY_WITHOUT_ADDRESS,
				self::STORED_COUNTRY_CODE_WITHOUT_ADDRESS
			),
			self::STORED_COUNTRY_WITHOUT_ADDRESS_ID,
			null,
		];
		yield "Existing country without address, pass country but no country code" => [
			new Address( null, self::STORED_COUNTRY_WITHOUT_ADDRESS, null ),
			self::STORED_COUNTRY_WITHOUT_ADDRESS_ID,
			'Need the country code for WRITE_NEW',
		];
		yield "Existing country without address, pass country code but no country" => [
			new Address( null, null, self::STORED_COUNTRY_CODE_WITHOUT_ADDRESS ),
			// This works because we hardcode the English name.
			self::STORED_COUNTRY_WITHOUT_ADDRESS_ID,
			null,
		];

		yield "Existing country but with a different address, pass country and country code" => [
			new Address( 'A new address', self::STORED_COUNTRY, self::STORED_COUNTRY_CODE ),
			self::NEXT_ADDRESS_ID,
			null,
		];
		yield "Existing country but with a different address, pass country but no country code" => [
			new Address( 'A new address', self::STORED_COUNTRY, null ),
			self::NEXT_ADDRESS_ID,
			'Need the country code for WRITE_NEW',
		];
		yield "Existing country but with a different address, pass country code but no country" => [
			new Address( 'A new address', null, self::STORED_COUNTRY_CODE ),
			self::NEXT_ADDRESS_ID,
			null,
		];

		yield "New address, pass country and country code" => [
			new Address( 'This is a new address!', 'Egypt', 'EG' ),
			self::NEXT_ADDRESS_ID,
			null,
		];
		yield "New address, pass country but no country code" => [
			new Address( 'This is a new address!', 'Egypt', null ),
			self::NEXT_ADDRESS_ID,
			'Need the country code for WRITE_NEW',
		];
		yield "New address, pass country code but no country" => [
			new Address( 'This is a new address!', null, 'EG' ),
			self::NEXT_ADDRESS_ID,
			null,
		];
	}

	/** @dataProvider provideGetEventAddress */
	public function testGetEventAddress( int $eventID, ?Address $expected ) {
		$this->insertDefaultDBData();
		$this->assertEquals(
			$expected,
			$this->getAddressStore()->getEventAddress( $this->getDb(), $eventID )
		);
	}

	public static function provideGetEventAddress() {
		$expectedFullAddress = new Address(
			self::STORED_ADDRESS,
			null,
			self::STORED_COUNTRY_CODE
		);

		yield "Has full address" => [
			self::EVENT_WITH_ADDRESS,
			$expectedFullAddress,
		];

		$expectedAddressWithoutAddress = new Address(
			null,
			null,
			self::STORED_COUNTRY_CODE_WITHOUT_ADDRESS
		);

		yield "Has address without address" => [
			self::EVENT_WITH_ADDRESS_WITHOUT_ADDRESS,
			$expectedAddressWithoutAddress,
		];

		yield "Does not have an address" => [
			99999999,
			null,
		];
	}

	private function insertMultipleAddressesForEvent( int $eventID ): void {
		$db = $this->getDb();

		$addresses = [
			[
				'cea_id' => 101,
				'cea_full_address' => 'Full address 1',
				'cea_country_code' => 'FR',
			],
			[
				'cea_id' => 102,
				'cea_full_address' => 'Full address 2',
				'cea_country_code' => 'FR',
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
		$this->getAddressStore()->getEventAddress( $this->getDb(), $eventID );
	}

	public function testGetAddressesForEvents() {
		$this->insertDefaultDBData();
		$expectedAddress = new Address(
			self::STORED_ADDRESS,
			null,
			self::STORED_COUNTRY_CODE
		);
		$expected = [
			self::EVENT_WITH_ADDRESS => $expectedAddress,
		];
		$actual = $this->getAddressStore()
			->getAddressesForEvents( $this->getDb(), [ self::EVENT_WITH_ADDRESS, 99999999 ] );
		$this->assertEquals( $expected, $actual );
	}

	public function testGetAddressesForEvents__eventWithMoreThanOneAddress() {
		$eventID = 6001;
		$this->insertMultipleAddressesForEvent( $eventID );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( "Event $eventID should have only one address" );
		$this->getAddressStore()
			->getAddressesForEvents( $this->getDb(), [ self::EVENT_WITH_ADDRESS, $eventID ] );
	}

	/** @dataProvider provideAddressRowsArePurged */
	public function testAddressRowsArePurged(
		?Address $newAddress,
		int $eventID,
		array $initialAddressRows,
		array $initialJoinRows,
		array $expectedAddressRows,
		array $expectedJoinRows,
	) {
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'ce_address' )
			->rows( $initialAddressRows )
			->caller( __METHOD__ )
			->execute();

		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'ce_event_address' )
			->rows( $initialJoinRows )
			->caller( __METHOD__ )
			->execute();

		$this->getAddressStore()->updateAddresses( $newAddress, $eventID );

		$joinRows = $this->getDb()->newSelectQueryBuilder()
			->select( '*' )
			->from( 'ce_event_address' )
			->orderBy( 'ceea_id' )
			->fetchResultSet();
		$this->assertEquals( $expectedJoinRows, iterator_to_array( $joinRows ) );

		$addressRows = $this->getDb()->newSelectQueryBuilder()
			->select( '*' )
			->from( 'ce_address' )
			->orderBy( 'cea_id' )
			->fetchResultSet();
		$this->assertEquals( $expectedAddressRows, iterator_to_array( $addressRows ) );
	}

	public static function provideAddressRowsArePurged(): Generator {
		$addressRows = [
			[
				'cea_id' => 1,
				'cea_full_address' => 'Reused',
				'cea_country_code' => 'FR',
			],
			[
				'cea_id' => 2,
				'cea_full_address' => 'Unique',
				'cea_country_code' => 'EG',
			],
		];
		$makeExpectedAddressRowsWithoutIDs = static function ( int ...$ids ) use ( $addressRows ) {
			$ret = [];
			foreach ( $addressRows as $row ) {
				if ( !in_array( $row['cea_id'], $ids, true ) ) {
					$ret[] = (object)$row;
				}
			}
			return $ret;
		};
		$joinRows = [
			[
				'ceea_id' => 1,
				'ceea_event' => 10,
				'ceea_address' => 1,
			],
			[
				'ceea_id' => 2,
				'ceea_event' => 20,
				'ceea_address' => 1,
			],
			[
				'ceea_id' => 3,
				'ceea_event' => 50,
				'ceea_address' => 2,
			],
		];
		$makeExpectedJoinRowsWithoutIDs = static function ( int ...$ids ) use ( $joinRows ) {
			$ret = [];
			foreach ( $joinRows as $row ) {
				if ( !in_array( $row['ceea_id'], $ids, true ) ) {
					$ret[] = (object)$row;
				}
			}
			return $ret;
		};

		$reusedAddress = new Address(
			'Reused',
			'France',
			'FR'
		);
		$uniqueAddress = new Address(
			'Unique',
			'Egypt',
			'EG'
		);
		$newAddress = new Address(
			'Address999',
			'Australia',
			'AU'
		);

		$newAddressExpectedRow = (object)[
			'cea_id' => 3,
			'cea_full_address' => 'Address999',
			'cea_country_code' => 'AU',
		];
		$makeExpectedJoinRow = static fn ( int $event, int $address ) => (object)[
			'ceea_id' => 4,
			'ceea_event' => $event,
			'ceea_address' => $address,
		];

		yield "Has address (only use), set same" => [
			$uniqueAddress,
			50,
			$addressRows,
			$joinRows,
			$makeExpectedAddressRowsWithoutIDs(),
			$makeExpectedJoinRowsWithoutIDs(),
		];
		yield "Has address (used elsewhere), set same" => [
			$reusedAddress,
			10,
			$addressRows,
			$joinRows,
			$makeExpectedAddressRowsWithoutIDs(),
			$makeExpectedJoinRowsWithoutIDs(),
		];
		yield "Has address (only use), set different" => [
			$newAddress,
			50,
			$addressRows,
			$joinRows,
			[ ...$makeExpectedAddressRowsWithoutIDs( 2 ), $newAddressExpectedRow ],
			[ ...$makeExpectedJoinRowsWithoutIDs( 3 ), $makeExpectedJoinRow( 50, 3 ) ],
		];
		yield "Has address (used elsewhere), set different" => [
			$newAddress,
			10,
			$addressRows,
			$joinRows,
			[ ...$makeExpectedAddressRowsWithoutIDs(), $newAddressExpectedRow ],
			[ ...$makeExpectedJoinRowsWithoutIDs( 1 ), $makeExpectedJoinRow( 10, 3 ) ],
		];
		yield "Has address (only use), remove" => [
			null,
			50,
			$addressRows,
			$joinRows,
			$makeExpectedAddressRowsWithoutIDs( 2 ),
			$makeExpectedJoinRowsWithoutIDs( 3 ),
		];
		yield "Has address (used elsewhere), remove" => [
			null,
			10,
			$addressRows,
			$joinRows,
			$makeExpectedAddressRowsWithoutIDs(),
			$makeExpectedJoinRowsWithoutIDs( 1 ),
		];

		yield "Does not have address, set one" => [
			$newAddress,
			100,
			$addressRows,
			$joinRows,
			[ ...$makeExpectedAddressRowsWithoutIDs(), $newAddressExpectedRow ],
			[ ...$makeExpectedJoinRowsWithoutIDs(), $makeExpectedJoinRow( 100, 3 ) ],
		];
		yield "Does not have address, do not set one" => [
			null,
			100,
			$addressRows,
			$joinRows,
			$makeExpectedAddressRowsWithoutIDs(),
			$makeExpectedJoinRowsWithoutIDs(),
		];
	}
}
