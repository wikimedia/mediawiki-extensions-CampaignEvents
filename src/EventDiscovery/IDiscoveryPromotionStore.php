<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\EventDiscovery;

use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;

interface IDiscoveryPromotionStore {
	public const STORE_SERVICE_NAME = 'CampaignEventsDiscoveryPromotionStore';

	/**
	 * Attempt to record that the event discovery dialog was shown for this user+event combination.
	 * Returns true if this is the first time (the promotion was newly recorded), false if already seen.
	 * The record expires when the event ends (via TTL).
	 *
	 * @param int $eventID
	 * @param CentralUser $user
	 * @param string $eventEndTimestamp Event end time in TS_MW format, used to compute TTL.
	 * @return bool True if newly recorded, false if already seen or event has ended.
	 */
	public function tryRecordPromotion( int $eventID, CentralUser $user, string $eventEndTimestamp ): bool;
}
