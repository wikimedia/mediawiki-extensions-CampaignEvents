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
		?string $meetingAddress,
		?string $meetingCountry,
		int $eventID
	): void {
		$dbw = $this->dbHelper->getDBConnection( DB_PRIMARY );

		$where = [ 'ceea_event' => $eventID ];
		if ( $meetingAddress || $meetingCountry ) {
			$meetingAddress .= " \n " . $meetingCountry;
			$where[] = $dbw->expr( 'cea_full_address', '!=', $meetingAddress );
		}

		$dbw->deleteJoin(
			'ce_event_address',
			'ce_address',
			'ceea_address',
			'cea_id',
			$where,
			__METHOD__
		);

		if ( $meetingAddress ) {
			$addressID = $this->acquireAddressID( $meetingAddress, $meetingCountry );
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

	public function getEventAddressRow( IDatabase $db, int $eventID ): ?stdClass {
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

		$addressRow = null;
		foreach ( $addressRows as $row ) {
			$addressRow = $row;
			break;
		}
		return $addressRow;
	}

	/**
	 * @param IDatabase $db
	 * @param int[] $eventIDs
	 * @return array<int,stdClass> Maps event IDs to the corresponding address row
	 */
	public function getAddressRowsForEvents( IDatabase $db, array $eventIDs ): array {
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
			$addressRowsByEvent[$curEventID] = $addressRow;
		}
		return $addressRowsByEvent;
	}
}
