<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Address;

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

	public function __construct(
		CampaignsDatabaseHelper $dbHelper
	) {
		$this->dbHelper = $dbHelper;
	}

	public function updateAddresses(
		?Address $address,
		int $eventID
	): void {
		$dbw = $this->dbHelper->getDBConnection( DB_PRIMARY );

		$where = [ 'ceea_event' => $eventID ];
		$addressWithoutCountry = $address ? $address->getAddressWithoutCountry() : null;
		$country = $address ? $address->getCountry() : null;
		if ( $addressWithoutCountry || $country ) {
			$fullAddress = $addressWithoutCountry . " \n " . $country;
			$where[] = $dbw->expr( 'cea_full_address', '!=', $fullAddress );
		} else {
			$fullAddress = null;
		}

		$dbw->deleteJoin(
			'ce_event_address',
			'ce_address',
			'ceea_address',
			'cea_id',
			$where,
			__METHOD__
		);

		if ( $fullAddress ) {
			$addressID = $this->acquireAddressID( $fullAddress, $country );
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
	public function acquireAddressID( string $fullAddress, ?string $country ): int {
		$dbw = $this->dbHelper->getDBConnection( DB_PRIMARY );
		// TODO This query is not indexed; for the future we will need to use some indexed field (like unique
		// address identifiers) instead of the full address.
		$addressID = $dbw->newSelectQueryBuilder()
			->select( 'cea_id' )
			->from( 'ce_address' )
			->where( [ 'cea_full_address' => $fullAddress ] )
			->caller( __METHOD__ )
			->fetchField();
		if ( $addressID !== false ) {
			$addressID = (int)$addressID;
		} else {
			$dbw->newInsertQueryBuilder()
				->insertInto( 'ce_address' )
				->row( [
					'cea_full_address' => $fullAddress,
					'cea_country' => $country
				] )
				->caller( __METHOD__ )
				->execute();
			$addressID = $dbw->insertId();
		}
		return $addressID;
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
		// Remove the country from the address, making sure to preserve other newlines in the address.
		$addressParts = explode( " \n ", $row->cea_full_address );
		array_pop( $addressParts );
		$addressWithoutCountry = implode( " \n ", $addressParts );
		if ( $addressWithoutCountry === '' ) {
			$addressWithoutCountry = null;
		}

		return new Address(
			$addressWithoutCountry,
			$row->cea_country,
		);
	}
}
