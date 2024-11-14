<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Event\Store;

use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;

/**
 * This class abstracts access to the ce_event_wikis DB table.
 * @note This class is intended to manage event and wiki relationships within the CampaignEvents extension.
 */
class EventWikisStore {
	public const SERVICE_NAME = 'CampaignEventsEventWikisStore';
	public const ALL_WIKIS = '*all*';

	private CampaignsDatabaseHelper $dbHelper;

	/**
	 * @param CampaignsDatabaseHelper $dbHelper
	 */
	public function __construct(
		CampaignsDatabaseHelper $dbHelper
	) {
		$this->dbHelper = $dbHelper;
	}

	/**
	 * Retrieves all wikis (ceew_wiki) associated with a specific event ID.
	 *
	 * @param int $eventID
	 * @return array
	 */
	public function getEventWikis( int $eventID ): array {
		$dbw = $this->dbHelper->getDBConnection( DB_REPLICA );
		$queryBuilder = $dbw->newSelectQueryBuilder();
		return $queryBuilder->select( 'ceew_wiki' )
			->from( 'ce_event_wikis' )
			->where( [ 'ceew_event_id' => $eventID ] )
			->caller( __METHOD__ )
			->fetchFieldValues();
	}

	/**
	 * Adds wikis for a specific event ID.
	 *
	 * @param array $eventWikis An array of wikis to add, the special value `*all*` to indicate all wikis
	 * @param int $eventID The event ID to associate these wikis with.
	 */
	public function addOrUpdateEventWikis( array $eventWikis, int $eventID ): void {
		$dbw = $this->dbHelper->getDBConnection( DB_PRIMARY );

		$queryBuilder = $dbw->newSelectQueryBuilder();
		$currentEventWikis = $queryBuilder->select( 'ceew_wiki' )
			->from( 'ce_event_wikis' )
			->where( [ 'ceew_event_id' => $eventID ] )
			->caller( __METHOD__ )
			->fetchFieldValues();
		// Calculate the wikis to remove and add using array_diff
		$wikisToRemove = array_diff( $currentEventWikis, $eventWikis );
		$wikisToAdd = array_diff( $eventWikis, $currentEventWikis );

		// only remove wikis if there are wikis to remove
		if ( count( $wikisToRemove ) > 0 ) {
			$deleteQueryBuilder = $dbw->newDeleteQueryBuilder();
			$deleteQueryBuilder->delete( 'ce_event_wikis' )
				->where( [ 'ceew_event_id' => $eventID ] )
				->caller( __METHOD__ );

			// If $eventWikis is empty or is ['*all*'] and there are wikis to remove
			// it means we need to remove all the current ones, so this where is not needed
			if ( count( $eventWikis ) > 0 && !in_array( self::ALL_WIKIS, $eventWikis, true ) ) {
				$deleteQueryBuilder->andWhere( [ 'ceew_wiki' => $wikisToRemove ] );
			}
			$deleteQueryBuilder->execute();
		}

		// add wikis if there are wikis to add
		if ( count( $wikisToAdd ) > 0 ) {
			$rows = [];
			foreach ( $eventWikis as $wiki ) {
				$rows[] = [
					'ceew_event_id' => $eventID,
					'ceew_wiki' => $wiki
				];
			}

			$insertQueryBuilder = $dbw->newInsertQueryBuilder();
			$insertQueryBuilder->insertInto( 'ce_event_wikis' )
				->ignore()
				->rows( $rows )
				->caller( __METHOD__ );

			$insertQueryBuilder->execute();
		}
	}
}
