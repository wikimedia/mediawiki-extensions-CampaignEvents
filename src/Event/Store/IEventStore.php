<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Event\Store;

use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;

/**
 * Interface for the storage layer of event registrations. Methods in this interface are currently not expected to
 * return a failure result, so any failure would have to be handled by throwing exceptions.
 */
interface IEventStore {
	public const STORE_SERVICE_NAME = 'CampaignEventsEventStore';

	/**
	 * @return int ID of the saved event. If $event has a non-null ID, then that value is returned.
	 */
	public function saveRegistration( EventRegistration $event ): int;

	/**
	 * @return bool True if the event was just deleted, false if it was already deleted
	 */
	public function deleteRegistration( ExistingEventRegistration $registration ): bool;
}
