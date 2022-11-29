<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Event\Store;

use IDBAccessObject;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsPage;

interface IEventLookup extends IDBAccessObject {
	public const LOOKUP_SERVICE_NAME = 'CampaignEventsEventLookup';

	/**
	 * @param int $eventID
	 * @return ExistingEventRegistration
	 * @throws EventNotFoundException
	 */
	public function getEventByID( int $eventID ): ExistingEventRegistration;

	/**
	 * @param ICampaignsPage $page
	 * @param int $readFlags One of the self::READ_* constants
	 * @return ExistingEventRegistration
	 * @throws EventNotFoundException
	 */
	public function getEventByPage(
		ICampaignsPage $page,
		int $readFlags = self::READ_NORMAL
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
