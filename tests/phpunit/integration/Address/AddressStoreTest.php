<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Address;

use Generator;
use InvalidArgumentException;
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

	private const STORED_ADDRESS_WITHOUT_COUNTRY = 'Address without country';
	private const STORED_ADDRESS_WITHOUT_COUNTRY_ID = 2;

	private const STORED_COUNTRY_WITHOUT_ADDRESS = 'Australia';
	private const STORED_COUNTRY_CODE_WITHOUT_ADDRESS = 'AU';
	private const STORED_COUNTRY_WITHOUT_ADDRESS_ID = 3;

	private const ADDRESS_ENTRY_COUNT = 3;
	private const NEXT_ADDRESS_ID = self::ADDRESS_ENTRY_COUNT + 1;

	private const MIGRATION_STAGES = [
		'MIGRATION_OLD' => MIGRATION_OLD,
		// TODO: Test all stages once the logic for them exists.
		// 'MIGRATION_WRITE_BOTH' => MIGRATION_WRITE_BOTH,
		// 'MIGRATION_WRITE_NEW' => MIGRATION_WRITE_NEW,
		// 'MIGRATION_NEW' => MIGRATION_NEW,
	];

	/**
	 * Format in which the preset DB rows should be. This can be used to test various combination of DB data
	 * and feature flag.
	 */
	private const STORED_FORMAT_OLD = 'old';
	private const STORED_FORMAT_BOTH = 'both';
	private const STORED_FORMAT_NEW = 'new';
	private const STORED_FORMATS = [ self::STORED_FORMAT_OLD, self::STORED_FORMAT_BOTH, self::STORED_FORMAT_NEW ];

	private static function getStoredAddressRow( string $storedFormat ): array {
		return match ( $storedFormat ) {
			self::STORED_FORMAT_OLD => [
				'cea_id' => self::STORED_ADDRESS_ID,
				'cea_full_address' => self::STORED_ADDRESS . " \n " . self::STORED_COUNTRY,
				'cea_country' => self::STORED_COUNTRY,
				'cea_country_code' => null,
			],
			self::STORED_FORMAT_BOTH => [
				'cea_id' => self::STORED_ADDRESS_ID,
				'cea_full_address' => self::STORED_ADDRESS,
				'cea_country' => self::STORED_COUNTRY,
				'cea_country_code' => self::STORED_COUNTRY_CODE,
			],
			self::STORED_FORMAT_NEW => [
				'cea_id' => self::STORED_ADDRESS_ID,
				'cea_full_address' => self::STORED_ADDRESS,
				'cea_country' => null,
				'cea_country_code' => self::STORED_COUNTRY_CODE,
			],
			default => throw new InvalidArgumentException( "Invalid format $storedFormat" )
		};
	}

	/** @todo Switch back to `addDBData` once we no longer need the stage */
	public function addDBDataTemp( string $storedFormat ): void {
		$rowWithoutCountry = match ( $storedFormat ) {
			self::STORED_FORMAT_OLD => [
				'cea_id' => self::STORED_ADDRESS_WITHOUT_COUNTRY_ID,
				'cea_full_address' => self::STORED_ADDRESS_WITHOUT_COUNTRY . " \n ",
				'cea_country' => null,
				'cea_country_code' => null,
			],
			self::STORED_FORMAT_BOTH => [
				'cea_id' => self::STORED_ADDRESS_WITHOUT_COUNTRY_ID,
				'cea_full_address' => self::STORED_ADDRESS_WITHOUT_COUNTRY,
				'cea_country' => null,
				'cea_country_code' => null,
			],
			self::STORED_FORMAT_NEW => [
				'cea_id' => self::STORED_ADDRESS_WITHOUT_COUNTRY_ID,
				'cea_full_address' => self::STORED_ADDRESS_WITHOUT_COUNTRY,
				'cea_country' => null,
				'cea_country_code' => null,
			],
			default => throw new InvalidArgumentException( "Invalid format $storedFormat" )
		};
		$rowWithoutAddress = match ( $storedFormat ) {
			self::STORED_FORMAT_OLD => [
				'cea_id' => self::STORED_COUNTRY_WITHOUT_ADDRESS_ID,
				'cea_full_address' => " \n " . self::STORED_COUNTRY_WITHOUT_ADDRESS,
				'cea_country' => self::STORED_COUNTRY_WITHOUT_ADDRESS,
				'cea_country_code' => null,
			],
			self::STORED_FORMAT_BOTH => [
				'cea_id' => self::STORED_COUNTRY_WITHOUT_ADDRESS_ID,
				'cea_full_address' => '',
				'cea_country' => self::STORED_COUNTRY_WITHOUT_ADDRESS,
				'cea_country_code' => self::STORED_COUNTRY_CODE_WITHOUT_ADDRESS,
			],
			self::STORED_FORMAT_NEW => [
				'cea_id' => self::STORED_COUNTRY_WITHOUT_ADDRESS_ID,
				'cea_full_address' => '',
				'cea_country' => null,
				'cea_country_code' => self::STORED_COUNTRY_CODE_WITHOUT_ADDRESS,
			],
			default => throw new InvalidArgumentException( "Invalid format $storedFormat" )
		};

		$addressRows = [
			self::getStoredAddressRow( $storedFormat ),
			$rowWithoutCountry,
			$rowWithoutAddress,
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

	private function getAddressStore( int $migrationStage ): AddressStore {
		return new AddressStore(
			CampaignEventsServices::getDatabaseHelper(),
			$migrationStage
		);
	}

	/** @dataProvider provideUpdateAddresses */
	public function testUpdateAddresses(
		int $eventID,
		?Address $address,
		int $expectsJoinRow,
		?stdClass $expectedAddressRow,
		int $migrationStage,
		string $storedFormat = self::STORED_FORMAT_OLD
	) {
		$this->addDBDataTemp( $storedFormat );
		$store = $this->getAddressStore( $migrationStage );
		$store->updateAddresses( $address, $eventID );

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

		foreach ( self::MIGRATION_STAGES as $stageName => $stageValue ) {
			yield "$stageName - No previous row, no address" => [
				$eventWithoutAddress,
				null,
				0,
				null,
				$stageValue,
			];
			yield "$stageName - No previous row, address, no country" => [
				$eventWithoutAddress,
				new Address( $newTestAddress, null ),
				1,
				(object)[
					'cea_id' => self::NEXT_ADDRESS_ID,
					'cea_full_address' => "$newTestAddress \n ",
					'cea_country' => null,
					'cea_country_code' => null,
				],
				$stageValue,
			];
			yield "$stageName - No previous row, country, no address" => [
				$eventWithoutAddress,
				new Address( null, $newTestCountry ),
				1,
				(object)[
					'cea_id' => self::NEXT_ADDRESS_ID,
					'cea_full_address' => " \n $newTestCountry",
					'cea_country' => $newTestCountry,
					'cea_country_code' => null,
				],
				$stageValue,
			];
			yield "$stageName - No previous row, address and country" => [
				$eventWithoutAddress,
				new Address( $newTestAddress, $newTestCountry ),
				1,
				(object)[
					'cea_id' => self::NEXT_ADDRESS_ID,
					'cea_full_address' => "$newTestAddress \n $newTestCountry",
					'cea_country' => $newTestCountry,
					'cea_country_code' => null,
				],
				$stageValue,
			];
		}

		foreach ( self::provideMigrationStagesAndStoredFormats() as $desc => [ $stage, $storedFormat ] ) {
			yield "$desc - Replace previous row, no address" => [
				self::EVENT_WITH_ADDRESS,
				null,
				0,
				null,
				$stage,
				$storedFormat,
			];
			yield "$desc - Replace previous row, address, no country" => [
				self::EVENT_WITH_ADDRESS,
				new Address( $newTestAddress, null ),
				1,
				(object)[
					'cea_id' => self::NEXT_ADDRESS_ID,
					'cea_full_address' => "$newTestAddress \n ",
					'cea_country' => null,
					'cea_country_code' => null,
				],
				$stage,
				$storedFormat,
			];
			yield "$desc - Replace previous row, country, no address" => [
				self::EVENT_WITH_ADDRESS,
				new Address( null, $newTestCountry ),
				1,
				(object)[
					'cea_id' => self::NEXT_ADDRESS_ID,
					'cea_full_address' => " \n $newTestCountry",
					'cea_country' => $newTestCountry,
					'cea_country_code' => null,
				],
				$stage,
				$storedFormat,
			];
			yield "$desc - Replace previous row, address and country" => [
				self::EVENT_WITH_ADDRESS,
				new Address( $newTestAddress, $newTestCountry ),
				1,
				(object)[
					'cea_id' => self::NEXT_ADDRESS_ID,
					'cea_full_address' => "$newTestAddress \n $newTestCountry",
					'cea_country' => $newTestCountry,
					'cea_country_code' => null,
				],
				$stage,
				$storedFormat,
			];

			yield "$desc - Same as previous row" => [
				self::EVENT_WITH_ADDRESS,
				new Address( self::STORED_ADDRESS, self::STORED_COUNTRY ),
				1,
				(object)self::getStoredAddressRow( $storedFormat ),
				$stage,
				$storedFormat,
			];
		}
	}

	/**
	 * @dataProvider provideAcquireAddressID
	 */
	public function testAcquireAddressID( Address $address, int $expected, int $migrationStage, string $storedFormat ) {
		$this->addDBDataTemp( $storedFormat );
		$store = $this->getAddressStore( $migrationStage );
		$this->assertSame( $expected, $store->acquireAddressID( $address ) );
	}

	public static function provideAcquireAddressID(): Generator {
		foreach ( self::provideMigrationStagesAndStoredFormats() as $desc => [ $stage, $storedFormat ] ) {
			yield "$desc - Existing address" => [
				new Address( self::STORED_ADDRESS, self::STORED_COUNTRY ),
				self::STORED_ADDRESS_ID,
				$stage,
				$storedFormat,
			];
			yield "$desc - Existing address without country" => [
				new Address( self::STORED_ADDRESS_WITHOUT_COUNTRY, null ),
				self::STORED_ADDRESS_WITHOUT_COUNTRY_ID,
				$stage,
				$storedFormat,
			];
			yield "$desc - Existing address but with different country" => [
				new Address( self::STORED_ADDRESS, 'Egypt' ),
				self::NEXT_ADDRESS_ID,
				$stage,
				$storedFormat,
			];
			yield "$desc - Existing country without address" => [
				new Address( null, self::STORED_COUNTRY_WITHOUT_ADDRESS ),
				self::STORED_COUNTRY_WITHOUT_ADDRESS_ID,
				$stage,
				$storedFormat,
			];
			yield "$desc - Existing country but with a different address" => [
				new Address( 'A new address', self::STORED_COUNTRY ),
				self::NEXT_ADDRESS_ID,
				$stage,
				$storedFormat,
			];
			yield "$desc - New address" => [
				new Address( 'This is a new address!', 'Egypt' ),
				self::NEXT_ADDRESS_ID,
				$stage,
				$storedFormat,
			];
		}
	}

	/** @dataProvider provideGetEventAddress */
	public function testGetEventAddress( int $eventID, ?Address $expected, int $migrationStage, string $storedFormat ) {
		$this->addDBDataTemp( $storedFormat );
		$this->assertEquals(
			$expected,
			$this->getAddressStore( $migrationStage )->getEventAddress( $this->getDb(), $eventID )
		);
	}

	public static function provideGetEventAddress() {
		foreach ( self::provideMigrationStagesAndStoredFormats() as $desc => [ $stage, $storedFormat ] ) {
			yield "$desc - Has address" => [
				self::EVENT_WITH_ADDRESS,
				new Address(
					self::STORED_ADDRESS,
					self::STORED_COUNTRY,
				),
				$stage,
				$storedFormat,
			];
			yield "$desc - Does not have an address" => [
				99999999,
				null,
				$stage,
				$storedFormat,
			];
		}
	}

	private function insertMultipleAddressesForEvent( int $eventID, string $storedFormat ): void {
		$db = $this->getDb();

		$addresses = match ( $storedFormat ) {
			self::STORED_FORMAT_OLD => [
				[
					'cea_id' => 101,
					'cea_full_address' => "Full address 1 \n France",
					'cea_country' => 'France',
				],
				[
					'cea_id' => 102,
					'cea_full_address' => "Full address 2 \n Egypt",
					'cea_country' => 'Egypt',
				]
			],
			self::STORED_FORMAT_BOTH => [
				[
					'cea_id' => 101,
					'cea_full_address' => 'Full address 1',
					'cea_country' => 'France',
					'cea_country_code' => 'FR',
				],
				[
					'cea_id' => 102,
					'cea_full_address' => 'Full address 2',
					'cea_country' => 'Egypt',
					'cea_country_code' => 'EG',
				]
			],
			self::STORED_FORMAT_NEW => [
				[
					'cea_id' => 101,
					'cea_full_address' => 'Full address 1',
					'cea_country' => null,
					'cea_country_code' => 'FR',
				],
				[
					'cea_id' => 102,
					'cea_full_address' => 'Full address 2',
					'cea_country' => null,
					'cea_country_code' => 'FR',
				]
			],
			default => throw new InvalidArgumentException( "Invalid format $storedFormat" )
		};

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

	/** @dataProvider provideMigrationStagesAndStoredFormats */
	public function testGetEventAddress__eventWithMoreThanOneAddress( int $migrationStage, string $storedFormat ) {
		$eventID = 6001;
		$this->insertMultipleAddressesForEvent( $eventID, $storedFormat );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Events should have only one address' );
		$this->getAddressStore( $migrationStage )->getEventAddress( $this->getDb(), $eventID );
	}

	/** @dataProvider provideMigrationStagesAndStoredFormats */
	public function testGetAddressesForEvents( int $migrationStage, string $storedFormat ) {
		$this->addDBDataTemp( $storedFormat );
		$expected = [
			self::EVENT_WITH_ADDRESS => new Address(
				self::STORED_ADDRESS,
				self::STORED_COUNTRY
			),
		];
		$actual = $this->getAddressStore( $migrationStage )
			->getAddressesForEvents( $this->getDb(), [ self::EVENT_WITH_ADDRESS, 99999999 ] );
		$this->assertEquals( $expected, $actual );
	}

	/** @dataProvider provideMigrationStagesAndStoredFormats */
	public function testGetAddressesForEvents__eventWithMoreThanOneAddress(
		int $migrationStage,
		string $storedFormat
	) {
		$eventID = 6001;
		$this->insertMultipleAddressesForEvent( $eventID, $storedFormat );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( "Event $eventID should have only one address" );
		$this->getAddressStore( $migrationStage )
			->getAddressesForEvents( $this->getDb(), [ self::EVENT_WITH_ADDRESS, $eventID ] );
	}

	public static function provideMigrationStagesAndStoredFormats(): Generator {
		$allowedFormats = [
			MIGRATION_OLD => [ self::STORED_FORMAT_OLD ],
			MIGRATION_WRITE_BOTH => [ self::STORED_FORMAT_OLD, self::STORED_FORMAT_BOTH, self::STORED_FORMAT_NEW ],
			MIGRATION_WRITE_NEW => [ self::STORED_FORMAT_OLD, self::STORED_FORMAT_BOTH, self::STORED_FORMAT_NEW ],
			MIGRATION_NEW => [ self::STORED_FORMAT_OLD, self::STORED_FORMAT_BOTH, self::STORED_FORMAT_NEW ],
		];
		foreach ( self::MIGRATION_STAGES as $stageName => $stageValue ) {
			foreach ( array_intersect( self::STORED_FORMATS, $allowedFormats[$stageValue] ) as $storedFormat ) {
				yield "Stage $stageName, stored format $storedFormat" => [ $stageValue, $storedFormat ];
			}
		}
	}
}
