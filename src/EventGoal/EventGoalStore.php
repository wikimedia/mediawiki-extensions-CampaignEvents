<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\EventGoal;

use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;

/**
 * Store for event goals. Reads and writes the ce_event_goals table.
 * All table access is gated by the event goal feature flag (injected as a boolean).
 */
class EventGoalStore {
	public const SERVICE_NAME = 'CampaignEventsEventGoalStore';

	public function __construct(
		private readonly CampaignsDatabaseHelper $dbHelper,
		private readonly bool $eventGoalFeatureEnabled
	) {
	}

	/**
	 * Whether the event goal feature is enabled (reads/writes to ce_event_goals allowed).
	 */
	public function isEventGoalFeatureEnabled(): bool {
		return $this->eventGoalFeatureEnabled;
	}

	/**
	 * Get goal for a single event. Returns null if feature is disabled or no row exists.
	 */
	public function getGoal( int $eventID ): ?EventGoal {
		return $this->getGoalsMulti( [ $eventID ] )[$eventID];
	}

	/**
	 * Get goals for multiple events. Returns a map eventID => ?EventGoal.
	 * If feature is disabled, returns null for every event.
	 *
	 * @param int[] $eventIDs
	 * @return array<int,EventGoal|null>
	 */
	public function getGoalsMulti( array $eventIDs ): array {
		$result = array_fill_keys( $eventIDs, null );
		if ( !$this->isEventGoalFeatureEnabled() || $eventIDs === [] ) {
			return $result;
		}

		$dbr = $this->dbHelper->getReplicaConnection();
		$rows = $dbr->newSelectQueryBuilder()
			->select( [ 'ceeg_event_id', 'ceeg_goals' ] )
			->from( 'ce_event_goals' )
			->where( [ 'ceeg_event_id' => $eventIDs ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		foreach ( $rows as $row ) {
			$eventID = (int)$row->ceeg_event_id;
			$data = json_decode( $row->ceeg_goals, true, 512, JSON_THROW_ON_ERROR );
			$result[$eventID] = EventGoal::newFromArray( $data );
		}

		return $result;
	}

	/**
	 * Replace the goal for an event with the given value (set or delete).
	 * No-op if feature is disabled. Call this from EventStore when saving a registration.
	 *
	 * When adding support for multiple goals per event, guard against excessive
	 * lock contention from gap locks on the DELETE; see
	 * https://wikitech.wikimedia.org/wiki/MediaWiki_Engineering/Guides/
	 * Backend_performance_practices#Transactions and other ancillary stores.
	 *
	 * @param int $eventID
	 * @param EventGoal|null $goal Goal to store, or null to remove any existing goal
	 */
	public function replaceEventGoal( int $eventID, ?EventGoal $goal ): void {
		if ( !$this->isEventGoalFeatureEnabled() ) {
			return;
		}

		$dbw = $this->dbHelper->getPrimaryConnection();

		// Always delete first to avoid duplicates on re-save.
		$dbw->newDeleteQueryBuilder()
			->deleteFrom( 'ce_event_goals' )
			->where( [ 'ceeg_event_id' => $eventID ] )
			->caller( __METHOD__ )
			->execute();

		if ( $goal === null ) {
			return;
		}

		$json = json_encode( $goal->toArray() );
		$dbw->newInsertQueryBuilder()
			->insertInto( 'ce_event_goals' )
			->row( [
				'ceeg_event_id' => $eventID,
				'ceeg_goals' => $json,
			] )
			->caller( __METHOD__ )
			->execute();
	}
}
