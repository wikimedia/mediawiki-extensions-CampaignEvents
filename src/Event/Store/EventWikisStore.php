<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Event\Store;

use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;

/**
 * This class abstracts access to the ce_event_wikis DB table.
 * @note This class is intended to manage event and wiki relationships within the CampaignEvents extension.
 */
class EventWikisStore {
	public const SERVICE_NAME = 'CampaignEventsEventWikisStore';
	public const ALL_WIKIS_DB_VALUE = '*all*';

	private CampaignsDatabaseHelper $dbHelper;

	public function __construct(
		CampaignsDatabaseHelper $dbHelper
	) {
		$this->dbHelper = $dbHelper;
	}

	/**
	 * Retrieves all wikis (ceew_wiki) associated with a specific event ID.
	 *
	 * @param int $eventID
	 * @return string[]|true List of wiki IDs or {@see EventRegistration::ALL_WIKIS}
	 */
	public function getEventWikis( int $eventID ) {
		return $this->getEventWikisMulti( [ $eventID ] )[$eventID];
	}

	/**
	 * Retrieves all wikis associated with the given events.
	 *
	 * @param int[] $eventIDs
	 * @return array<int,string[]|true> Maps event ID to a list of wiki IDs or {@see EventRegistration::ALL_WIKIS}
	 */
	public function getEventWikisMulti( array $eventIDs ): array {
		$wikisByEvent = array_fill_keys( $eventIDs, [] );

		$dbr = $this->dbHelper->getDBConnection( DB_REPLICA );
		$queryBuilder = $dbr->newSelectQueryBuilder();
		$res = $queryBuilder->select( [ 'ceew_event_id', 'ceew_wiki' ] )
			->from( 'ce_event_wikis' )
			->where( [ 'ceew_event_id' => $eventIDs ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		foreach ( $res as $row ) {
			$curEvent = $row->ceew_event_id;
			$storedWiki = $row->ceew_wiki;
			if ( $storedWiki === self::ALL_WIKIS_DB_VALUE ) {
				$wikisByEvent[$curEvent] = EventRegistration::ALL_WIKIS;
			} else {
				$wikisByEvent[$curEvent][] = $storedWiki;
			}
		}
		return $wikisByEvent;
	}

	/**
	 * Adds wikis for a specific event ID.
	 *
	 * @param int $eventID The event ID to associate these wikis with.
	 * @param string[]|true $eventWikis An array of wiki IDs to add, or {@see EventRegistration::ALL_WIKIS}
	 */
	public function addOrUpdateEventWikis( int $eventID, $eventWikis ): void {
		$dbw = $this->dbHelper->getDBConnection( DB_PRIMARY );

		$queryBuilder = $dbw->newSelectQueryBuilder();
		$currentWikisRes = $queryBuilder->select( [ 'ceew_id', 'ceew_wiki' ] )
			->from( 'ce_event_wikis' )
			->where( [ 'ceew_event_id' => $eventID ] )
			->caller( __METHOD__ )
			->fetchResultSet();
		$currentWikisByID = [];
		foreach ( $currentWikisRes as $row ) {
			$currentWikisByID[$row->ceew_id] = $row->ceew_wiki;
		}

		if ( $eventWikis === EventRegistration::ALL_WIKIS ) {
			if ( array_values( $currentWikisByID ) === [ self::ALL_WIKIS_DB_VALUE ] ) {
				// Already in the desired state, no need to make changes.
				$rowIDsToRemove = $wikisToAdd = [];
			} else {
				$rowIDsToRemove = array_keys( $currentWikisByID );
				$wikisToAdd = [ self::ALL_WIKIS_DB_VALUE ];
			}
		} else {
			$rowIDsToRemove = array_keys( array_diff( $currentWikisByID, $eventWikis ) );
			$wikisToAdd = array_diff( $eventWikis, $currentWikisByID );
		}

		if ( count( $rowIDsToRemove ) > 0 ) {
			$deleteQueryBuilder = $dbw->newDeleteQueryBuilder();
			$deleteQueryBuilder->delete( 'ce_event_wikis' )
				->where( [ 'ceew_id' => $rowIDsToRemove ] )
				->caller( __METHOD__ )
				->execute();
		}

		if ( count( $wikisToAdd ) > 0 ) {
			$rows = [];
			foreach ( $wikisToAdd as $wiki ) {
				$rows[] = [
					'ceew_event_id' => $eventID,
					'ceew_wiki' => $wiki
				];
			}

			$insertQueryBuilder = $dbw->newInsertQueryBuilder();
			$insertQueryBuilder->insertInto( 'ce_event_wikis' )
				->ignore()
				->rows( $rows )
				->caller( __METHOD__ )
				->execute();
		}
	}
}
