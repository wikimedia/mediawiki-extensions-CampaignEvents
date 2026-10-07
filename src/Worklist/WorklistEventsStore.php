<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Worklist;

use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Page\PageIdentityValue;
use MediaWiki\Page\PageStoreFactory;

/**
 * Primary store for the event ↔ worklist association (`ce_worklist_events`).
 *
 * Unlike the sibling worklist stores, which are secondary storage synchronized from the worklist
 * content page, this table is the source of truth for which worklist belongs to which event.
 *
 * The table stores event ↔ worklist pairs; a unique index on (worklist, event) prevents duplicate
 * pairs, but does not enforce a single worklist per event. Only the operations the current
 * consumers need are exposed here.
 *
 * For now, events are associated by default with a worklist in the /Worklist subpage of the event page,
 * so the database lookup is sometimes skipped in favour of direct title resolution.
 */
class WorklistEventsStore {
	public const SERVICE_NAME = 'CampaignEventsWorklistEventsStore';

	/** Leaf name of the subpage that holds an event's worklist (e.g. "Event:Foo/Worklist"). */
	public const WORKLIST_SUBPAGE = 'Worklist';

	public function __construct(
		private readonly CampaignsDatabaseHelper $dbHelper,
		private readonly PageStoreFactory $pageStoreFactory,
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
	 * Given a list of event IDs and a page, returns the subset of those event IDs whose worklist
	 * contains that page.
	 *
	 * @param int[] $eventIDs
	 * @return int[]
	 */
	public function filterEventsByPageInWorklist(
		array $eventIDs,
		string $wiki,
		string $pageTitle
	): array {
		if ( !$eventIDs ) {
			return [];
		}
		$eventIDs = $this->dbHelper->getReplicaConnection()->newSelectQueryBuilder()
			->select( 'cewe_event_id' )
			->from( 'ce_worklist_events' )
			->join( 'ce_worklist_pages', null, 'cewe_cew_id = cewp_cew_id' )
			->where( [
				'cewe_event_id' => $eventIDs,
				'cewp_wiki' => $wiki,
				'cewp_page_prefixedtext' => $pageTitle,
			] )
			->caller( __METHOD__ )
			->fetchFieldValues();
		return array_map( 'intval', $eventIDs );
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

	/**
	 * Returns the page where the worklist for the given event is stored. The page may not exist.
	 * This method bypasses associations registered in the ce_worklist_events table.
	 */
	public function getWorklistPageForEvent( ExistingEventRegistration $event ): PageIdentity {
		$eventPage = $event->getPage();
		$worklistPageNamespace = $eventPage->getNamespace();
		$worklistPageDBKey = $eventPage->getDBkey() . '/' . self::WORKLIST_SUBPAGE;
		$worklistPageWiki = $eventPage->getWikiId();

		$worklistPage = $this->pageStoreFactory->getPageStore( $worklistPageWiki )
			->getPageByName( $worklistPageNamespace, $worklistPageDBKey );
		if ( !$worklistPage ) {
			$worklistPage = new PageIdentityValue( 0, $worklistPageNamespace, $worklistPageDBKey, $worklistPageWiki );
		}

		return $worklistPage;
	}
}
