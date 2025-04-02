<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Event\Store;

use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWPageProxy;
use Wikimedia\Rdbms\IDBAccessObject;

interface IEventLookup {
	public const LOOKUP_SERVICE_NAME = 'CampaignEventsEventLookup';

	/**
	 * @param int $eventID
	 * @return ExistingEventRegistration
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
	 * @return ExistingEventRegistration
	 * @throws EventNotFoundException
	 */
	public function getEventByPage(
		MWPageProxy $page,
		int $readFlags = IDBAccessObject::READ_NORMAL
	): ExistingEventRegistration;

	/**
	 * @param int $organizerID
	 * @param int $limit
	 * @return ExistingEventRegistration[]
	 */
	public function getEventsByOrganizer( int $organizerID, int $limit ): array;

	/**
	 * @param int $participantID
	 * @param int $limit
	 * @return ExistingEventRegistration[]
	 */
	public function getEventsByParticipant( int $participantID, int $limit ): array;
}
