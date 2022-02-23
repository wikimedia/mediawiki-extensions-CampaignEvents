<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Store;

use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;

interface IEventLookup {
	public const LOOKUP_SERVICE_NAME = 'CampaignEventsEventLookup';

	/**
	 * @param int $eventID
	 * @return ExistingEventRegistration
	 */
	public function getEvent( int $eventID ): ExistingEventRegistration;
}
