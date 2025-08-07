<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Event\Store;

use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWPageProxy;
use stdClass;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IDBAccessObject;

interface IEventLookup {
	public const LOOKUP_SERVICE_NAME = 'CampaignEventsEventLookup';

	/**
	 * @throws EventNotFoundException
	 */
	public function getEventByID( int $eventID ): ExistingEventRegistration;

	/**
	 * Get the event associated with the given page.
	 *
	 * @note This does not perform any canonicalization on the given page. For that, see {@see PageEventLookup}.
	 * This method should not be used directly unless you want to avoid canonicalization (which you usually don't want
	 * to avoid outside the storage layer).
	 *
	 * @param MWPageProxy $page
	 * @param int $readFlags One of the IDBAccessObject::READ_* constants
	 * @throws EventNotFoundException
	 */
	public function getEventByPage(
		MWPageProxy $page,
		int $readFlags = IDBAccessObject::READ_NORMAL
	): ExistingEventRegistration;

	/**
	 * @return ExistingEventRegistration[]
	 */
	public function getEventsByOrganizer( int $organizerID, int $limit ): array;

	/**
	 * @return ExistingEventRegistration[]
	 */
	public function getEventsByParticipant( int $participantID, int $limit ): array;

	/**
	 * Returns a list of events that can be associated with an edit. These events must:
	 *  - Have track contributions enabled
	 *  - Target the current wiki
	 *  - Be ongoing
	 *  - Not be deleted
	 *  This includes events for which the user has registered privately
	 *
	 * @param int $participantID The user ID of the participant
	 * @param int $limit Maximum number of events to return
	 * @return ExistingEventRegistration[]
	 */
	public function getEventsForContributionAssociationByParticipant( int $participantID, int $limit ): array;

	/**
	 * Given a result set containing full rows from the campaign_events table, constructs EventRegistration objects
	 * for those rows, looking up the required additional information.
	 *
	 * @param IDatabase $db
	 * @param iterable<stdClass> $eventRows
	 * @return array<int,ExistingEventRegistration> Mapping event ID to the corresponding object.
	 */
	public function newEventsFromDBRows( IDatabase $db, iterable $eventRows ): array;
}
