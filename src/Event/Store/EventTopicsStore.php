<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Event\Store;

use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;

/**
 * This class abstracts access to the ce_event_topics DB table.
 * @note This class is intended to manage event and topic relationships within the CampaignEvents extension.
 */
class EventTopicsStore {
	public const SERVICE_NAME = 'CampaignEventsEventTopicsStore';

	private CampaignsDatabaseHelper $dbHelper;
	private bool $featureEnabled;

	public function __construct(
		CampaignsDatabaseHelper $dbHelper,
		bool $featureEnabled
	) {
		$this->dbHelper = $dbHelper;
		$this->featureEnabled = $featureEnabled;
	}

	/**
	 * Retrieves all topics (ceet_topics) associated with a specific event ID.
	 *
	 * @param int $eventID
	 * @return string[] List of topics IDs
	 */
	public function getEventTopics( int $eventID ) {
		return $this->getEventTopicsMulti( [ $eventID ] )[$eventID];
	}

	/**
	 * Retrieves all topics associated with the given events.
	 *
	 * @param int[] $eventIDs
	 * @return array<int,string[]> Maps event ID to a list of topic IDs
	 */
	public function getEventTopicsMulti( array $eventIDs ): array {
		$topicsByEvent = array_fill_keys( $eventIDs, [] );
		if ( !$this->featureEnabled ) {
			return $topicsByEvent;
		}

		$dbr = $this->dbHelper->getDBConnection( DB_REPLICA );
		$queryBuilder = $dbr->newSelectQueryBuilder();
		$res = $queryBuilder->select( [ 'ceet_event_id', 'ceet_topic' ] )
			->from( 'ce_event_topics' )
			->where( [ 'ceet_event_id' => $eventIDs ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		foreach ( $res as $row ) {
			$topicsByEvent[$row->ceet_event_id][] = $row->ceet_topic;
		}
		return $topicsByEvent;
	}

	/**
	 * Adds topics for a specific event ID.
	 *
	 * @param int $eventID The event ID to associate these topics with.
	 * @param string[] $eventTopics An array of topic IDs to add
	 */
	public function addOrUpdateEventTopics( int $eventID, $eventTopics ): void {
		if ( !$this->featureEnabled ) {
			return;
		}
		$dbw = $this->dbHelper->getDBConnection( DB_PRIMARY );

		$queryBuilder = $dbw->newSelectQueryBuilder();
		$currentEventTopics = $queryBuilder->select( 'ceet_topic' )
			->from( 'ce_event_topics' )
			->where( [ 'ceet_event_id' => $eventID ] )
			->caller( __METHOD__ )
			->fetchFieldValues();

		$topicsToRemove = array_diff( $currentEventTopics, $eventTopics );
		$topicsToAdd = array_diff( $eventTopics, $currentEventTopics );

		if ( count( $topicsToRemove ) > 0 ) {
			$deleteQueryBuilder = $dbw->newDeleteQueryBuilder();
			$deleteQueryBuilder->delete( 'ce_event_topics' )
				->where( [
					'ceet_event_id' => $eventID,
					'ceet_topic' => $topicsToRemove
				] )
				->caller( __METHOD__ )
				->execute();
		}

		if ( count( $topicsToAdd ) > 0 ) {
			$rows = [];
			foreach ( $topicsToAdd as $topic ) {
				$rows[] = [
					'ceet_event_id' => $eventID,
					'ceet_topic' => $topic
				];
			}

			$insertQueryBuilder = $dbw->newInsertQueryBuilder();
			$insertQueryBuilder->insertInto( 'ce_event_topics' )
				->ignore()
				->rows( $rows )
				->caller( __METHOD__ )
				->execute();
		}
	}
}