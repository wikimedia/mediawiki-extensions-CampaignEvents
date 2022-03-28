<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Store;

use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsPage;

interface IEventLookup {
	public const LOOKUP_SERVICE_NAME = 'CampaignEventsEventLookup';

	/**
	 * @param int $eventID
	 * @return ExistingEventRegistration
	 * @throws EventNotFoundException
	 */
	public function getEventByID( int $eventID ): ExistingEventRegistration;

	/**
	 * @param ICampaignsPage $page
	 * @return ExistingEventRegistration
	 * @throws EventNotFoundException
	 */
	public function getEventByPage( ICampaignsPage $page ): ExistingEventRegistration;
}
