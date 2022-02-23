<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Store;

use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;

interface IEventStore {
	public const STORE_SERVICE_NAME = 'CampaignEventsEventStore';

	/**
	 * @param EventRegistration $event
	 * @return int
	 */
	public function saveRegistration( EventRegistration $event ): int;
}
