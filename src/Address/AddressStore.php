<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Address;

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use RuntimeException;
use stdClass;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IReadableDatabase;

/**
 * This class abstracts access to the ce_address and ce_event_address DB tables. In the future, this might be expanded
 * with geocoding support (T316126), and also allowing multiple addresses for each event.
 */
class AddressStore {
	public const SERVICE_NAME = 'CampaignEventsAddressStore';

	public function __construct(
		private readonly CampaignsDatabaseHelper $dbHelper,
	) {
	}

	public function updateAddresses(
		?Address $address,
		int $eventID
	): void {
		$dbw = $this->dbHelper->getDBConnection( DB_PRIMARY );

		$where = [ 'ceea_event' => $eventID ];
		if ( $address ) {
			$addressWithoutCountry = $address->getAddressWithoutCountry();
			$where[] = $dbw->orExpr( [
				$dbw->expr( 'cea_full_address', '!=', $addressWithoutCountry ?? '' ),
				$dbw->expr( 'cea_country_code', '!=', $address->getCountryCode() ),
			] );
		}

		$oldRow = $dbw->newSelectQueryBuilder()
			->select( [ 'ceea_id', 'ceea_address' ] )
			->from( 'ce_event_address' )
			->join( 'ce_address', null, 'cea_id=ceea_address' )
			->where( $where )
			->caller( __METHOD__ )
			// Note: this relies on the fact that events can currently have a single address
			->fetchRow();

		if ( $oldRow ) {
			$fname = __METHOD__;
			// First, dissociate the event and address
			$dbw->newDeleteQueryBuilder()
				->deleteFrom( 'ce_event_address' )
				->where( [ 'ceea_id' => $oldRow->ceea_id ] )
				->caller( $fname )
				->execute();
			// Then delete the address itself, if this was the only usage.
			DeferredUpdates::addCallableUpdate( static function () use ( $dbw, $oldRow, $fname ): void {
				$usagesSubquery = $dbw->newSelectQueryBuilder()
					->select( '1' )
					->from( 'ce_event_address' )
					->where( [ 'ceea_address' => $oldRow->ceea_address ] );
				$dbw->newDeleteQueryBuilder()
					->deleteFrom( 'ce_address' )
					->where( [
						'cea_id' => $oldRow->ceea_address,
						'NOT EXISTS(' . $usagesSubquery->getSQL() . ')'
					] )
					->caller( $fname )
					->execute();
			} );
		}

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
		$dbw = $this->dbHelper->getDBConnection( DB_PRIMARY );

		// TODO This query is not indexed; for the future we will need to use some indexed field (like unique
		// address identifiers) instead of the full address. In the interim, it is important that whatever this
		// method uses is also a unique identifier.
		$addressID = $dbw->newSelectQueryBuilder()
			->select( 'cea_id' )
			->from( 'ce_address' )
			->where( [
				'cea_full_address' => $address->getAddressWithoutCountry() ?? '',
				'cea_country_code' => $address->getCountryCode(),
			] )
			->caller( __METHOD__ )
			->fetchField();

		if ( $addressID !== false ) {
			$addressID = (int)$addressID;
		} else {
			$newRow = [
				'cea_full_address' => $address->getAddressWithoutCountry() ?? '',
				'cea_country_code' => $address->getCountryCode(),
			];
			$dbw->newInsertQueryBuilder()
				->insertInto( 'ce_address' )
				->row( $newRow )
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
	 * @param IReadableDatabase $dbr
	 * @param int[] $eventIDs
	 * @return array<int,Address> Maps event IDs to the corresponding address
	 */
	public function getAddressesForEvents( IReadableDatabase $dbr, array $eventIDs ): array {
		$addressRows = $dbr->newSelectQueryBuilder()
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
		return new Address(
			$row->cea_full_address,
			$row->cea_country_code
		);
	}
}
