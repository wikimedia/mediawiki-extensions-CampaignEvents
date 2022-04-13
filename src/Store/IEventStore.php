<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Store;

use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use StatusValue;

interface IEventStore {
	public const STORE_SERVICE_NAME = 'CampaignEventsEventStore';

	/**
	 * @param EventRegistration $event
	 * @return StatusValue If good, the value shall be the ID of the event.
	 */
	public function saveRegistration( EventRegistration $event ): StatusValue;

	/**
	 * @param ExistingEventRegistration $registration
	 * @return bool True if it was deleted, false if it was already deleted
	 */
	public function deleteRegistration( ExistingEventRegistration $registration ): bool;
}
