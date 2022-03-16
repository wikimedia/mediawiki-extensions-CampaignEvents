<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Store;

use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use StatusValue;

interface IEventStore {
	public const STORE_SERVICE_NAME = 'CampaignEventsEventStore';

	/**
	 * @param EventRegistration $event
	 * @return StatusValue If good, the value should be the ID of the event.
	 */
	public function saveRegistration( EventRegistration $event ): StatusValue;
}
