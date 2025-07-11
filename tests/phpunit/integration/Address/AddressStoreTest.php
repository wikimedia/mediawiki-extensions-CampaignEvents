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

	private const EVENT_WITH_ADDRESS_WITHOUT_COUNTRY = 5002;
	private const STORED_ADDRESS_WITHOUT_COUNTRY = 'Address without country';
	private const STORED_ADDRESS_WITHOUT_COUNTRY_ID = 2;

	private const EVENT_WITH_ADDRESS_WITHOUT_ADDRESS = 5003;
	private const STORED_COUNTRY_WITHOUT_ADDRESS = 'Australia';
	private const STORED_COUNTRY_CODE_WITHOUT_ADDRESS = 'AU';
	private const STORED_COUNTRY_WITHOUT_ADDRESS_ID = 3;

	private const ADDRESS_ENTRY_COUNT = 3;
	private const NEXT_ADDRESS_ID = self::ADDRESS_ENTRY_COUNT + 1;

	private const MIGRATION_STAGES = [
		'MIGRATION_OLD' => MIGRATION_OLD,
		'MIGRATION_WRITE_BOTH' => MIGRATION_WRITE_BOTH,
		'MIGRATION_WRITE_NEW' => MIGRATION_WRITE_NEW,
		'MIGRATION_NEW' => MIGRATION_NEW,
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

	private static function getStoredRowWithoutCountryOldFormat(): array {
		return [
			'cea_id' => self::STORED_ADDRESS_WITHOUT_COUNTRY_ID,
			'cea_full_address' => self::STORED_ADDRESS_WITHOUT_COUNTRY . " \n ",
			'cea_country' => null,
			'cea_country_code' => null,
		];
	}

	private static function getStoredRowWithoutAddress( string $storedFormat ): array {
		return match ( $storedFormat ) {
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
	}

	/** @todo Switch back to `addDBData` once we no longer need the stage */
	public function addDBDataTemp( string $storedFormat ): void {
		if ( $storedFormat === self::STORED_FORMAT_OLD ) {
			$middleRow = self::getStoredRowWithoutCountryOldFormat();
		} else {
			// Insert a random row so that IDs remain the same
			$middleRow = [
				'cea_id' => self::STORED_ADDRESS_WITHOUT_COUNTRY_ID,
				'cea_full_address' => 'This address should never be used',
				'cea_country' => null,
				'cea_country_code' => 'XX',
			];
		}
		$addressRows = [
			self::getStoredAddressRow( $storedFormat ),
			$middleRow,
			self::getStoredRowWithoutAddress( $storedFormat ),
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
				'ceea_event' => self::EVENT_WITH_ADDRESS_WITHOUT_COUNTRY,
				'ceea_address' => self::STORED_ADDRESS_WITHOUT_COUNTRY_ID,
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
		?string $storedFormat = null,
		?string $expectedException = null
	) {
		$storedFormat ??= self::STORED_FORMAT_OLD;
		$this->addDBDataTemp( $storedFormat );
		$store = $this->getAddressStore( $migrationStage );

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

		foreach ( self::MIGRATION_STAGES as $stageName => $stageValue ) {
			$hasWriteOld = (bool)( $stageValue & SCHEMA_COMPAT_WRITE_OLD );
			$hasWriteNew = (bool)( $stageValue & SCHEMA_COMPAT_WRITE_NEW );

			yield "$stageName - No previous row, no address" => [
				$eventWithoutAddress,
				null,
				0,
				null,
				$stageValue,
			];
			yield "$stageName - No previous row, address, no country, no country code" => [
				$eventWithoutAddress,
				new Address( $newTestAddress, null, null ),
				1,
				(object)[
					'cea_id' => self::NEXT_ADDRESS_ID,
					'cea_full_address' => "$newTestAddress \n ",
					'cea_country' => null,
					'cea_country_code' => null,
				],
				$stageValue,
				null,
				$hasWriteNew ? 'Need the country code for WRITE_NEW' : null,
			];
			yield "$stageName - No previous row, country, no country code, no address" => [
				$eventWithoutAddress,
				new Address( null, $newTestCountry, null ),
				1,
				(object)[
					'cea_id' => self::NEXT_ADDRESS_ID,
					'cea_full_address' => " \n $newTestCountry",
					'cea_country' => $newTestCountry,
					'cea_country_code' => null,
				],
				$stageValue,
				null,
				$hasWriteNew ? 'Need the country code for WRITE_NEW' : null,
			];
			yield "$stageName - No previous row, country code, no country, no address" => [
				$eventWithoutAddress,
				new Address( null, null, $newTestCountryCode ),
				1,
				(object)[
					'cea_id' => self::NEXT_ADDRESS_ID,
					'cea_full_address' => $hasWriteNew ? '' : " \n $newTestCountry",
					// This works because we hardcode the English name.
					'cea_country' => $hasWriteOld ? $newTestCountry : null,
					'cea_country_code' => $newTestCountryCode,
				],
				$stageValue,
				null,
				$hasWriteNew ? null : 'Cannot handle country code without WRITE_NEW',
			];
			yield "$stageName - No previous row, address and country, no country code" => [
				$eventWithoutAddress,
				new Address( $newTestAddress, $newTestCountry, null ),
				1,
				(object)[
					'cea_id' => self::NEXT_ADDRESS_ID,
					'cea_full_address' => "$newTestAddress \n $newTestCountry",
					'cea_country' => $newTestCountry,
					'cea_country_code' => null,
				],
				$stageValue,
				null,
				$hasWriteNew ? 'Need the country code for WRITE_NEW' : null,
			];
			yield "$stageName - No previous row, address and country code, no country" => [
				$eventWithoutAddress,
				new Address( $newTestAddress, null, $newTestCountryCode ),
				1,
				(object)[
					'cea_id' => self::NEXT_ADDRESS_ID,
					'cea_full_address' => $hasWriteNew ? $newTestAddress : "$newTestAddress \n $newTestCountry",
					// This works because we hardcode the English name.
					'cea_country' => $hasWriteOld ? $newTestCountry : null,
					'cea_country_code' => $hasWriteNew ? $newTestCountryCode : null,
				],
				$stageValue,
				null,
				$hasWriteNew ? null : 'Cannot handle country code without WRITE_NEW',
			];
			yield "$stageName - No previous row, country and country code, no address" => [
				$eventWithoutAddress,
				new Address( null, $newTestCountry, $newTestCountryCode ),
				1,
				(object)[
					'cea_id' => self::NEXT_ADDRESS_ID,
					'cea_full_address' => $hasWriteNew ? '' : " \n $newTestCountry",
					// This is taken from the Address object, so it would work in any language
					'cea_country' => $hasWriteOld ? $newTestCountry : null,
					'cea_country_code' => $hasWriteNew ? $newTestCountryCode : null,
				],
				$stageValue,
				null,
				$hasWriteNew ? null : 'Cannot handle country code without WRITE_NEW',
			];
			yield "$stageName - No previous row, address, country, and country code" => [
				$eventWithoutAddress,
				new Address( $newTestAddress, $newTestCountry, $newTestCountryCode ),
				1,
				(object)[
					'cea_id' => self::NEXT_ADDRESS_ID,
					'cea_full_address' => $hasWriteNew ? $newTestAddress : "$newTestAddress \n $newTestCountry",
					// This is taken from the Address object, so it would work in any language
					'cea_country' => $hasWriteOld ? $newTestCountry : null,
					'cea_country_code' => $hasWriteNew ? $newTestCountryCode : null,
				],
				$stageValue,
				null,
				$hasWriteNew ? null : 'Cannot handle country code without WRITE_NEW',
			];
		}

		foreach ( self::provideMigrationStagesAndStoredFormats() as $desc => [ $stage, $storedFormat ] ) {
			$hasWriteOld = (bool)( $stage & SCHEMA_COMPAT_WRITE_OLD );
			$hasWriteNew = (bool)( $stage & SCHEMA_COMPAT_WRITE_NEW );

			yield "$desc - Replace previous row, no address" => [
				self::EVENT_WITH_ADDRESS,
				null,
				0,
				null,
				$stage,
				$storedFormat,
			];
			yield "$desc - Replace previous row, address, no country, no country code" => [
				self::EVENT_WITH_ADDRESS,
				new Address( $newTestAddress, null, null ),
				1,
				(object)[
					'cea_id' => self::NEXT_ADDRESS_ID,
					'cea_full_address' => "$newTestAddress \n ",
					'cea_country' => null,
					'cea_country_code' => null,
				],
				$stage,
				$storedFormat,
				$hasWriteNew ? 'Need the country code for WRITE_NEW' : null,
			];
			yield "$desc - Replace previous row, country, no country code, no address" => [
				self::EVENT_WITH_ADDRESS,
				new Address( null, $newTestCountry, null ),
				1,
				(object)[
					'cea_id' => self::NEXT_ADDRESS_ID,
					'cea_full_address' => " \n $newTestCountry",
					'cea_country' => $newTestCountry,
					'cea_country_code' => null,
				],
				$stage,
				$storedFormat,
				$hasWriteNew ? 'Need the country code for WRITE_NEW' : null,
			];
			yield "$desc - Replace previous row, country code, no country, no address" => [
				self::EVENT_WITH_ADDRESS,
				new Address( null, null, $newTestCountryCode ),
				1,
				(object)[
					'cea_id' => self::NEXT_ADDRESS_ID,
					'cea_full_address' => $hasWriteNew ? '' : " \n $newTestCountry",
					// This works because we hardcode the English name.
					'cea_country' => $hasWriteOld ? $newTestCountry : null,
					'cea_country_code' => $hasWriteNew ? $newTestCountryCode : null,
				],
				$stage,
				$storedFormat,
				$hasWriteNew ? null : 'Cannot handle country code without WRITE_NEW',
			];
			yield "$desc - Replace previous row, address and country, no country code" => [
				self::EVENT_WITH_ADDRESS,
				new Address( $newTestAddress, $newTestCountry, null ),
				1,
				(object)[
					'cea_id' => self::NEXT_ADDRESS_ID,
					'cea_full_address' => "$newTestAddress \n $newTestCountry",
					'cea_country' => $newTestCountry,
					'cea_country_code' => null,
				],
				$stage,
				$storedFormat,
				$hasWriteNew ? 'Need the country code for WRITE_NEW' : null,
			];
			yield "$desc - Replace previous row, address and country code, no country" => [
				self::EVENT_WITH_ADDRESS,
				new Address( $newTestAddress, null, $newTestCountryCode ),
				1,
				(object)[
					'cea_id' => self::NEXT_ADDRESS_ID,
					'cea_full_address' => $hasWriteNew ? $newTestAddress : "$newTestAddress \n $newTestCountry",
					// This works because we hardcode the English name.
					'cea_country' => $hasWriteOld ? $newTestCountry : null,
					'cea_country_code' => $hasWriteNew ? $newTestCountryCode : null,
				],
				$stage,
				$storedFormat,
				$hasWriteNew ? null : 'Cannot handle country code without WRITE_NEW',
			];
			yield "$desc - Replace previous row, country and country code, no address" => [
				self::EVENT_WITH_ADDRESS,
				new Address( null, $newTestCountry, $newTestCountryCode ),
				1,
				(object)[
					'cea_id' => self::NEXT_ADDRESS_ID,
					'cea_full_address' => $hasWriteNew ? '' : " \n $newTestCountry",
					// This is taken from the Address object, so it would work in any language
					'cea_country' => $hasWriteOld ? $newTestCountry : null,
					'cea_country_code' => $hasWriteNew ? $newTestCountryCode : null,
				],
				$stage,
				$storedFormat,
				$hasWriteNew ? null : 'Cannot handle country code without WRITE_NEW',
			];
			yield "$desc - Replace previous row, address, country and country code" => [
				self::EVENT_WITH_ADDRESS,
				new Address( $newTestAddress, $newTestCountry, $newTestCountryCode ),
				1,
				(object)[
					'cea_id' => self::NEXT_ADDRESS_ID,
					'cea_full_address' => $hasWriteNew ? $newTestAddress : "$newTestAddress \n $newTestCountry",
					// This is taken from the Address object, so it would work in any language
					'cea_country' => $hasWriteOld ? $newTestCountry : null,
					'cea_country_code' => $hasWriteNew ? $newTestCountryCode : null,
				],
				$stage,
				$storedFormat,
				$hasWriteNew ? null : 'Cannot handle country code without WRITE_NEW',
			];

			yield "$desc - Same as previous row with address and country, pass country but not code" => [
				self::EVENT_WITH_ADDRESS,
				new Address( self::STORED_ADDRESS, self::STORED_COUNTRY, null ),
				1,
				(object)self::getStoredAddressRow( $storedFormat ),
				$stage,
				$storedFormat,
				$hasWriteNew ? 'Need the country code for WRITE_NEW' : null,
			];
			yield "$desc - Same as previous row with address and country, pass country code but not country" => [
				self::EVENT_WITH_ADDRESS,
				new Address( self::STORED_ADDRESS, null, self::STORED_COUNTRY_CODE ),
				1,
				(object)self::getStoredAddressRow( $storedFormat ),
				$stage,
				$storedFormat,
				$hasWriteNew ? null : 'Cannot handle country code without WRITE_NEW',
			];
			yield "$desc - Same as previous row with address and country, pass country and country code" => [
				self::EVENT_WITH_ADDRESS,
				new Address( self::STORED_ADDRESS, self::STORED_COUNTRY, self::STORED_COUNTRY_CODE ),
				1,
				(object)self::getStoredAddressRow( $storedFormat ),
				$stage,
				$storedFormat,
				$hasWriteNew ? null : 'Cannot handle country code without WRITE_NEW',
			];

			if ( $storedFormat === self::STORED_FORMAT_OLD ) {
				yield "$desc - Same as previous row without country" => [
					self::EVENT_WITH_ADDRESS,
					new Address( self::STORED_ADDRESS_WITHOUT_COUNTRY, null, null ),
					1,
					(object)self::getStoredRowWithoutCountryOldFormat(),
					$stage,
					$storedFormat,
					$hasWriteNew ? 'Need the country code for WRITE_NEW' : null,
				];
			}

			yield "$desc - Same as previous row without address, pass country but not code" => [
				self::EVENT_WITH_ADDRESS,
				new Address( null, self::STORED_COUNTRY_WITHOUT_ADDRESS, null ),
				1,
				(object)self::getStoredRowWithoutAddress( $storedFormat ),
				$stage,
				$storedFormat,
				$hasWriteNew ? 'Need the country code for WRITE_NEW' : null,
			];
			yield "$desc - Same as previous row without address, pass country code but not country" => [
				self::EVENT_WITH_ADDRESS,
				new Address( null, null, self::STORED_COUNTRY_CODE_WITHOUT_ADDRESS ),
				1,
				// This works because we hardcode the English name.
				(object)self::getStoredRowWithoutAddress( $storedFormat ),
				$stage,
				$storedFormat,
				$hasWriteNew ? null : 'Cannot handle country code without WRITE_NEW',
			];
			yield "$desc - Same as previous row without address, pass country and country code" => [
				self::EVENT_WITH_ADDRESS,
				new Address(
					null,
					self::STORED_COUNTRY_WITHOUT_ADDRESS,
					self::STORED_COUNTRY_CODE_WITHOUT_ADDRESS
				),
				1,
				(object)self::getStoredRowWithoutAddress( $storedFormat ),
				$stage,
				$storedFormat,
				$hasWriteNew ? null : 'Cannot handle country code without WRITE_NEW',
			];
		}
	}

	/**
	 * @dataProvider provideAcquireAddressID
	 */
	public function testAcquireAddressID(
		Address $address,
		int $expected,
		int $migrationStage,
		string $storedFormat,
		?string $expectedException = null
	) {
		$this->addDBDataTemp( $storedFormat );
		$store = $this->getAddressStore( $migrationStage );

		if ( $expectedException !== null ) {
			$this->expectException( RuntimeException::class );
			$this->expectExceptionMessage( $expectedException );
		}
		$this->assertSame( $expected, $store->acquireAddressID( $address ) );
	}

	public static function provideAcquireAddressID(): Generator {
		foreach ( self::provideMigrationStagesAndStoredFormats() as $desc => [ $stage, $storedFormat ] ) {
			$hasWriteNew = (bool)( $stage & SCHEMA_COMPAT_WRITE_NEW );

			yield "$desc - Existing full address, pass country and country code" => [
				new Address( self::STORED_ADDRESS, self::STORED_COUNTRY, self::STORED_COUNTRY_CODE ),
				self::STORED_ADDRESS_ID,
				$stage,
				$storedFormat,
				$hasWriteNew ? null : 'Cannot handle country code without WRITE_NEW',
			];
			yield "$desc - Existing full address, pass country but no country code" => [
				new Address( self::STORED_ADDRESS, self::STORED_COUNTRY, null ),
				self::STORED_ADDRESS_ID,
				$stage,
				$storedFormat,
				$hasWriteNew ? 'Need the country code for WRITE_NEW' : null,
			];
			yield "$desc - Existing full address, pass country code but no country" => [
				new Address( self::STORED_ADDRESS, null, self::STORED_COUNTRY_CODE ),
				self::STORED_ADDRESS_ID,
				$stage,
				$storedFormat,
				$hasWriteNew ? null : 'Cannot handle country code without WRITE_NEW',
			];

			if ( $storedFormat === self::STORED_FORMAT_OLD ) {
				yield "$desc - Existing address without country" => [
					new Address( self::STORED_ADDRESS_WITHOUT_COUNTRY, null, null ),
					self::STORED_ADDRESS_WITHOUT_COUNTRY_ID,
					$stage,
					$storedFormat,
					$hasWriteNew ? 'Need the country code for WRITE_NEW' : null,
				];
			}

			yield "$desc - Existing address but with different country, pass country and country code" => [
				new Address( self::STORED_ADDRESS, 'Egypt', 'EG' ),
				self::NEXT_ADDRESS_ID,
				$stage,
				$storedFormat,
				$hasWriteNew ? null : 'Cannot handle country code without WRITE_NEW',
			];
			yield "$desc - Existing address but with different country, pass country but no country code" => [
				new Address( self::STORED_ADDRESS, 'Egypt', null ),
				self::NEXT_ADDRESS_ID,
				$stage,
				$storedFormat,
				$hasWriteNew ? 'Need the country code for WRITE_NEW' : null,
			];
			yield "$desc - Existing address but with different country, pass country code but no country" => [
				new Address( self::STORED_ADDRESS, null, 'EG' ),
				self::NEXT_ADDRESS_ID,
				$stage,
				$storedFormat,
				$hasWriteNew ? null : 'Cannot handle country code without WRITE_NEW',
			];

			yield "$desc - Existing country without address, pass country and country code" => [
				new Address(
					null,
					self::STORED_COUNTRY_WITHOUT_ADDRESS,
					self::STORED_COUNTRY_CODE_WITHOUT_ADDRESS
				),
				self::STORED_COUNTRY_WITHOUT_ADDRESS_ID,
				$stage,
				$storedFormat,
				$hasWriteNew ? null : 'Cannot handle country code without WRITE_NEW',
			];
			yield "$desc - Existing country without address, pass country but no country code" => [
				new Address( null, self::STORED_COUNTRY_WITHOUT_ADDRESS, null ),
				self::STORED_COUNTRY_WITHOUT_ADDRESS_ID,
				$stage,
				$storedFormat,
				$hasWriteNew ? 'Need the country code for WRITE_NEW' : null,
			];
			yield "$desc - Existing country without address, pass country code but no country" => [
				new Address( null, null, self::STORED_COUNTRY_CODE_WITHOUT_ADDRESS ),
				// This works because we hardcode the English name.
				self::STORED_COUNTRY_WITHOUT_ADDRESS_ID,
				$stage,
				$storedFormat,
				$hasWriteNew ? null : 'Cannot handle country code without WRITE_NEW',
			];

			yield "$desc - Existing country but with a different address, pass country and country code" => [
				new Address( 'A new address', self::STORED_COUNTRY, self::STORED_COUNTRY_CODE ),
				self::NEXT_ADDRESS_ID,
				$stage,
				$storedFormat,
				$hasWriteNew ? null : 'Cannot handle country code without WRITE_NEW',
			];
			yield "$desc - Existing country but with a different address, pass country but no country code" => [
				new Address( 'A new address', self::STORED_COUNTRY, null ),
				self::NEXT_ADDRESS_ID,
				$stage,
				$storedFormat,
				$hasWriteNew ? 'Need the country code for WRITE_NEW' : null,
			];
			yield "$desc - Existing country but with a different address, pass country code but no country" => [
				new Address( 'A new address', null, self::STORED_COUNTRY_CODE ),
				self::NEXT_ADDRESS_ID,
				$stage,
				$storedFormat,
				$hasWriteNew ? null : 'Cannot handle country code without WRITE_NEW',
			];

			yield "$desc - New address, pass country and country code" => [
				new Address( 'This is a new address!', 'Egypt', 'EG' ),
				self::NEXT_ADDRESS_ID,
				$stage,
				$storedFormat,
				$hasWriteNew ? null : 'Cannot handle country code without WRITE_NEW',
			];
			yield "$desc - New address, pass country but no country code" => [
				new Address( 'This is a new address!', 'Egypt', null ),
				self::NEXT_ADDRESS_ID,
				$stage,
				$storedFormat,
				$hasWriteNew ? 'Need the country code for WRITE_NEW' : null,
			];
			yield "$desc - New address, pass country code but no country" => [
				new Address( 'This is a new address!', null, 'EG' ),
				self::NEXT_ADDRESS_ID,
				$stage,
				$storedFormat,
				$hasWriteNew ? null : 'Cannot handle country code without WRITE_NEW',
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
			$hasReadNew = (bool)( $stage & SCHEMA_COMPAT_READ_NEW );

			if ( $hasReadNew && $storedFormat !== self::STORED_FORMAT_OLD ) {
				$expectedFullAddress = new Address(
					self::STORED_ADDRESS,
					$storedFormat === self::STORED_FORMAT_BOTH ? self::STORED_COUNTRY : null,
					self::STORED_COUNTRY_CODE
				);
			} else {
				$expectedFullAddress = new Address( self::STORED_ADDRESS, self::STORED_COUNTRY, null );
			}
			yield "$desc - Has full address" => [
				self::EVENT_WITH_ADDRESS,
				$expectedFullAddress,
				$stage,
				$storedFormat,
			];

			if ( $storedFormat === self::STORED_FORMAT_OLD ) {
				yield "$desc - Has address without country" => [
					self::EVENT_WITH_ADDRESS_WITHOUT_COUNTRY,
					new Address( self::STORED_ADDRESS_WITHOUT_COUNTRY, null, null ),
					$stage,
					$storedFormat,
				];
			}

			if ( $hasReadNew && $storedFormat !== self::STORED_FORMAT_OLD ) {
				$expectedAddressWithoutAddress = new Address(
					null,
					$storedFormat === self::STORED_FORMAT_BOTH ? self::STORED_COUNTRY_WITHOUT_ADDRESS : null,
					self::STORED_COUNTRY_CODE_WITHOUT_ADDRESS
				);
			} else {
				$expectedAddressWithoutAddress = new Address(
					null,
					self::STORED_COUNTRY_WITHOUT_ADDRESS,
					null
				);
			}
			yield "$desc - Has address without address" => [
				self::EVENT_WITH_ADDRESS_WITHOUT_ADDRESS,
				$expectedAddressWithoutAddress,
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
		if ( ( $migrationStage & SCHEMA_COMPAT_READ_NEW ) && $storedFormat !== self::STORED_FORMAT_OLD ) {
			$expectedAddress = new Address(
				self::STORED_ADDRESS,
				$storedFormat === self::STORED_FORMAT_BOTH ? self::STORED_COUNTRY : null,
				self::STORED_COUNTRY_CODE
			);
		} else {
			$expectedAddress = new Address(
				self::STORED_ADDRESS,
				self::STORED_COUNTRY,
				null
			);
		}
		$expected = [
			self::EVENT_WITH_ADDRESS => $expectedAddress,
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
			MIGRATION_WRITE_BOTH => [ self::STORED_FORMAT_OLD, self::STORED_FORMAT_BOTH ],
			MIGRATION_WRITE_NEW => [ self::STORED_FORMAT_OLD, self::STORED_FORMAT_BOTH, self::STORED_FORMAT_NEW ],
			MIGRATION_NEW => [ self::STORED_FORMAT_BOTH, self::STORED_FORMAT_NEW ],
		];
		foreach ( self::MIGRATION_STAGES as $stageName => $stageValue ) {
			foreach ( array_intersect( self::STORED_FORMATS, $allowedFormats[$stageValue] ) as $storedFormat ) {
				yield "Stage $stageName, stored format $storedFormat" => [ $stageValue, $storedFormat ];
			}
		}
	}

	/** @dataProvider provideAddressRowsArePurged */
	public function testAddressRowsArePurged(
		?Address $newAddress,
		int $eventID,
		array $initialAddressRows,
		array $initialJoinRows,
		array $expectedAddressRows,
		array $expectedJoinRows,
		int $migrationStage
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

		$this->getAddressStore( $migrationStage )->updateAddresses( $newAddress, $eventID );

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
		foreach ( self::provideMigrationStagesAndStoredFormats() as $desc => [ $stage, $storedFormat ] ) {
			$addressRows = [
				[
					'cea_id' => 1,
					'cea_full_address' => $storedFormat === self::STORED_FORMAT_OLD ? "Reused \n France" : 'Reused',
					'cea_country' => $storedFormat === self::STORED_FORMAT_NEW ? null : 'France',
					'cea_country_code' => $storedFormat === self::STORED_FORMAT_OLD ? null : 'FR',
				],
				[
					'cea_id' => 2,
					'cea_full_address' => $storedFormat === self::STORED_FORMAT_OLD ? "Unique \n Egypt" : 'Unique',
					'cea_country' => $storedFormat === self::STORED_FORMAT_NEW ? null : 'Egypt',
					'cea_country_code' => $storedFormat === self::STORED_FORMAT_OLD ? null : 'EG',
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
				( $stage & SCHEMA_COMPAT_WRITE_NEW ) ? 'FR' : null
			);
			$uniqueAddress = new Address(
				'Unique',
				'Egypt',
				( $stage & SCHEMA_COMPAT_WRITE_NEW ) ? 'EG' : null
			);
			$newAddress = new Address(
				'Address999',
				'Australia',
				( $stage & SCHEMA_COMPAT_WRITE_NEW ) ? 'AU' : null
			);

			$newAddressExpectedRow = (object)[
				'cea_id' => 3,
				'cea_full_address' => ( $stage & SCHEMA_COMPAT_WRITE_NEW ) ? 'Address999' : "Address999 \n Australia",
				'cea_country' => ( $stage & SCHEMA_COMPAT_WRITE_OLD ) ? 'Australia' : null,
				'cea_country_code' => ( $stage & SCHEMA_COMPAT_WRITE_NEW ) ? 'AU' : null,
			];
			$makeExpectedJoinRow = static fn ( int $event, int $address ) => (object)[
				'ceea_id' => 4,
				'ceea_event' => $event,
				'ceea_address' => $address,
			];

			yield "$desc - Has address (only use), set same" => [
				$uniqueAddress,
				50,
				$addressRows,
				$joinRows,
				$makeExpectedAddressRowsWithoutIDs(),
				$makeExpectedJoinRowsWithoutIDs(),
				$stage,
			];
			yield "$desc - Has address (used elsewhere), set same" => [
				$reusedAddress,
				10,
				$addressRows,
				$joinRows,
				$makeExpectedAddressRowsWithoutIDs(),
				$makeExpectedJoinRowsWithoutIDs(),
				$stage,
			];
			yield "$desc - Has address (only use), set different" => [
				$newAddress,
				50,
				$addressRows,
				$joinRows,
				[ ...$makeExpectedAddressRowsWithoutIDs( 2 ), $newAddressExpectedRow ],
				[ ...$makeExpectedJoinRowsWithoutIDs( 3 ), $makeExpectedJoinRow( 50, 3 ) ],
				$stage,
			];
			yield "$desc - Has address (used elsewhere), set different" => [
				$newAddress,
				10,
				$addressRows,
				$joinRows,
				[ ...$makeExpectedAddressRowsWithoutIDs(), $newAddressExpectedRow ],
				[ ...$makeExpectedJoinRowsWithoutIDs( 1 ), $makeExpectedJoinRow( 10, 3 ) ],
				$stage,
			];
			yield "$desc - Has address (only use), remove" => [
				null,
				50,
				$addressRows,
				$joinRows,
				$makeExpectedAddressRowsWithoutIDs( 2 ),
				$makeExpectedJoinRowsWithoutIDs( 3 ),
				$stage,
			];
			yield "$desc - Has address (used elsewhere), remove" => [
				null,
				10,
				$addressRows,
				$joinRows,
				$makeExpectedAddressRowsWithoutIDs(),
				$makeExpectedJoinRowsWithoutIDs( 1 ),
				$stage,
			];

			yield "$desc - Does not have address, set one" => [
				$newAddress,
				100,
				$addressRows,
				$joinRows,
				[ ...$makeExpectedAddressRowsWithoutIDs(), $newAddressExpectedRow ],
				[ ...$makeExpectedJoinRowsWithoutIDs(), $makeExpectedJoinRow( 100, 3 ) ],
				$stage,
			];
			yield "$desc - Does not have address, do not set one" => [
				null,
				100,
				$addressRows,
				$joinRows,
				$makeExpectedAddressRowsWithoutIDs(),
				$makeExpectedJoinRowsWithoutIDs(),
				$stage,
			];
		}
	}
}
