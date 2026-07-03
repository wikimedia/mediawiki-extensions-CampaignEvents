<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Worklist;

use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;

/**
 * Primary store for the event ↔ worklist association (`ce_worklist_events`).
 *
 * Unlike the sibling worklist stores, which are secondary storage synchronized from the worklist
 * content page, this table is the source of truth for which worklist belongs to which event.
 *
 * The table stores event ↔ worklist pairs; a unique index on (worklist, event) prevents duplicate
 * pairs, but does not enforce a single worklist per event. Only the operations the current
 * consumers need are exposed here.
 */
class WorklistEventsStore {
	public const SERVICE_NAME = 'CampaignEventsWorklistEventsStore';

	public function __construct(
		private readonly CampaignsDatabaseHelper $dbHelper,
	) {
	}

	/**
	 * Associates the given worklist with the given event.
	 *
	 * Idempotent: a repeated call for the same (worklist, event) pair is a no-op (INSERT IGNORE),
	 * so callers do not need to check for an existing association first.
	 */
	public function associateEventWithWorklist( int $eventID, int $worklistID ): void {
		$this->dbHelper->getPrimaryConnection()->newInsertQueryBuilder()
			->insertInto( 'ce_worklist_events' )
			->ignore()
			->row( [
				'cewe_cew_id' => $worklistID,
				'cewe_event_id' => $eventID,
			] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * Returns the ID of the worklist associated with the given event, or null if there is none.
	 */
	public function getWorklistIDForEvent( int $eventID ): ?int {
		$storedID = $this->dbHelper->getReplicaConnection()->newSelectQueryBuilder()
			->select( 'cewe_cew_id' )
			->from( 'ce_worklist_events' )
			->where( [
				'cewe_event_id' => $eventID,
			] )
			->caller( __METHOD__ )
			->fetchField();
		return $storedID !== false ? (int)$storedID : null;
	}

	/**
	 * Removes the association between the given worklist and event.
	 */
	public function removeWorklistAssociation( int $worklistID, int $eventID ): void {
		$this->dbHelper->getPrimaryConnection()->newDeleteQueryBuilder()
			->deleteFrom( 'ce_worklist_events' )
			->where( [
				'cewe_cew_id' => $worklistID,
				'cewe_event_id' => $eventID,
			] )
			->caller( __METHOD__ )
			->execute();
	}
}
