<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Address;

use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use RuntimeException;
use stdClass;
use Wikimedia\Rdbms\IDatabase;

/**
 * This class abstracts access to the ce_address and ce_event_address DB tables. In the future, this might be expanded
 * with geocoding support (T316126), and also allowing multiple addresses for each event.
 */
class AddressStore {
	public const SERVICE_NAME = 'CampaignEventsAddressStore';

	private CampaignsDatabaseHelper $dbHelper;
	private int $countrySchemaMigrationStage;

	public function __construct(
		CampaignsDatabaseHelper $dbHelper,
		int $countrySchemaMigrationStage
	) {
		$this->dbHelper = $dbHelper;
		$this->countrySchemaMigrationStage = $countrySchemaMigrationStage;
	}

	public function updateAddresses(
		?Address $address,
		int $eventID
	): void {
		$address = $this->adjustAddressForWrite( $address );
		$dbw = $this->dbHelper->getDBConnection( DB_PRIMARY );

		$where = [ 'ceea_event' => $eventID ];
		if ( $address ) {
			$addressWithoutCountry = $address->getAddressWithoutCountry();
			$country = $address->getCountry();
			if ( $this->countrySchemaMigrationStage & SCHEMA_COMPAT_READ_OLD ) {
				$where[] = $dbw->expr( 'cea_full_address', '!=', $addressWithoutCountry . " \n " . $country );
			}
			if ( $this->countrySchemaMigrationStage & SCHEMA_COMPAT_READ_NEW ) {
				$where[] = $dbw->orExpr( [
					$dbw->expr( 'cea_full_address', '!=', $addressWithoutCountry ?? '' ),
					$dbw->expr( 'cea_country_code', '!=', $address->getCountryCode() ),
				] );
			}
		}

		$dbw->deleteJoin(
			'ce_event_address',
			'ce_address',
			'ceea_address',
			'cea_id',
			$where,
			__METHOD__
		);

		if ( $address ) {
			$addressID = $this->acquireAddressID( $address );
			$dbw->newInsertQueryBuilder()
				->insertInto( 'ce_event_address' )
				->ignore()
				->row( [
					'ceea_event' => $eventID,
					'ceea_address' => $addressID
				] )
				->caller( __METHOD__ )
				->execute();
		}
	}

	/**
	 * Returns the ID that identifies the given address in the database. This may return the ID of an existing entry,
	 * or insert a new entry.
	 */
	public function acquireAddressID( Address $address ): int {
		$address = $this->adjustAddressForWrite( $address );
		$fullAddressWithCountry = $address->getAddressWithoutCountry() . " \n " . $address->getCountry();
		$dbw = $this->dbHelper->getDBConnection( DB_PRIMARY );

		$where = [];
		// NOTE: The WHERE clause will fail to find an existing row if:
		// - The row is still in the old format (has a country but no country code)
		// - The original Address object (before adjustAddressForWrite) contains a country code, but no country
		// - The stored country is not in English (as that's what adjustAddressForWrite uses)
		// This is fine though, as it will eventually be cleaned up by the migration script.
		if ( $this->countrySchemaMigrationStage & SCHEMA_COMPAT_READ_OLD ) {
			$where[] = $dbw->andExpr( [
				'cea_full_address' => $fullAddressWithCountry,
				'cea_country_code' => null,
			] );
		}
		if ( $this->countrySchemaMigrationStage & SCHEMA_COMPAT_READ_NEW ) {
			$where[] = $dbw->andExpr( [
				'cea_full_address' => $address->getAddressWithoutCountry() ?? '',
				'cea_country_code' => $address->getCountryCode(),
			] );
		}

		// TODO This query is not indexed; for the future we will need to use some indexed field (like unique
		// address identifiers) instead of the full address.
		$addressID = $dbw->newSelectQueryBuilder()
			->select( 'cea_id' )
			->from( 'ce_address' )
			->where( $dbw->orExpr( $where ) )
			->caller( __METHOD__ )
			->fetchField();

		if ( $addressID !== false ) {
			$addressID = (int)$addressID;
		} else {
			$newRow = [];
			if ( $this->countrySchemaMigrationStage & SCHEMA_COMPAT_WRITE_OLD ) {
				$newRow['cea_full_address'] = $fullAddressWithCountry;
				$newRow['cea_country'] = $address->getCountry();
			} else {
				$newRow['cea_country'] = null;
			}
			if ( $this->countrySchemaMigrationStage & SCHEMA_COMPAT_WRITE_NEW ) {
				$newRow['cea_full_address'] = $address->getAddressWithoutCountry() ?? '';
				$newRow['cea_country_code'] = $address->getCountryCode();
			}
			$dbw->newInsertQueryBuilder()
				->insertInto( 'ce_address' )
				->row( $newRow )
				->caller( __METHOD__ )
				->execute();
			$addressID = $dbw->insertId();
		}
		return $addressID;
	}

	private function adjustAddressForWrite( ?Address $address ): ?Address {
		if ( !$address ) {
			return null;
		}

		$hasWriteNew = (bool)( $this->countrySchemaMigrationStage & SCHEMA_COMPAT_WRITE_NEW );
		$countryCode = $address->getCountryCode();

		if ( $hasWriteNew && $countryCode === null ) {
			// Note, this could happen in practice for page moves and setting organizers via the API. Both should be
			// rare occurrences, so much so if we can quickly run the migration script and advance the migration
			// stage. For APIs and special pages, this should be prevented by EventFactory. See T397476#10980178.
			throw new RuntimeException( 'Need the country code for WRITE_NEW' );
		}
		if ( !$hasWriteNew && $countryCode !== null ) {
			// We could hardcode a country here, but instead we throw an exception because this
			// is not supposed to happen in practice.
			throw new RuntimeException( 'Cannot handle country code without WRITE_NEW' );
		}
		if (
			( $this->countrySchemaMigrationStage & ( SCHEMA_COMPAT_READ_OLD | SCHEMA_COMPAT_WRITE_OLD ) ) &&
			$countryCode !== null &&
			$address->getCountry() === null
		) {
			// If we need to write to or read from the old schema, add a free-text country to maximize
			// cross-compatibility. This will also allow us to detect a pre-existing row, but only if the row itself
			// also uses English. These values, as well as duplicates, will be cleaned up by the migration script.
			$englishCountries = CampaignEventsServices::getCountryProvider()->getAvailableCountries( 'en' );
			return new Address(
				$address->getAddressWithoutCountry(),
				$englishCountries[$countryCode],
				$countryCode
			);
		}
		return $address;
	}

	public function getEventAddress( IDatabase $db, int $eventID ): ?Address {
		$addressRows = $db->newSelectQueryBuilder()
			->select( '*' )
			->from( 'ce_address' )
			->join( 'ce_event_address', null, [ 'ceea_address=cea_id', 'ceea_event' => $eventID ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		// TODO Add support for multiple addresses per event
		if ( count( $addressRows ) > 1 ) {
			throw new RuntimeException( 'Events should have only one address.' );
		}

		$address = null;
		foreach ( $addressRows as $row ) {
			$address = $this->addressFromRow( $row );
			break;
		}
		return $address;
	}

	/**
	 * @param IDatabase $db
	 * @param int[] $eventIDs
	 * @return array<int,Address> Maps event IDs to the corresponding address
	 */
	public function getAddressesForEvents( IDatabase $db, array $eventIDs ): array {
		$addressRows = $db->newSelectQueryBuilder()
			->select( '*' )
			->from( 'ce_address' )
			->join( 'ce_event_address', null, [ 'ceea_address=cea_id', 'ceea_event' => $eventIDs ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		$addressRowsByEvent = [];
		foreach ( $addressRows as $addressRow ) {
			$curEventID = (int)$addressRow->ceea_event;
			if ( isset( $addressRowsByEvent[$curEventID] ) ) {
				// TODO Add support for multiple addresses per event
				throw new RuntimeException( "Event $curEventID should have only one address." );
			}
			$addressRowsByEvent[$curEventID] = $this->addressFromRow( $addressRow );
		}
		return $addressRowsByEvent;
	}

	private function addressFromRow( stdClass $row ): Address {
		$addressWithoutCountry = $country = $countryCode = null;
		if ( $this->countrySchemaMigrationStage & SCHEMA_COMPAT_READ_NEW ) {
			$addressWithoutCountry = $row->cea_full_address;
			$country = $row->cea_country;
			$countryCode = $row->cea_country_code;
		}
		if ( $countryCode === null && ( $this->countrySchemaMigrationStage & SCHEMA_COMPAT_READ_OLD ) ) {
			// Remove the country from the address, making sure to preserve other newlines in the address.
			$addressParts = explode( " \n ", $row->cea_full_address );
			array_pop( $addressParts );
			$addressWithoutCountry = implode( " \n ", $addressParts );
			if ( $addressWithoutCountry === '' ) {
				$addressWithoutCountry = null;
			}
			$country = $row->cea_country;
		}

		return new Address(
			$addressWithoutCountry,
			$country,
			$countryCode
		);
	}
}
