<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\EventPage;

use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MWTimestamp;
use OutputPage;

/**
 * This class is responsible of managing the cache of event pages, to avoid issues like T326593.
 */
class EventPageCacheUpdater {
	public const SERVICE_NAME = 'CampaignEventsEventPageCacheUpdater';

	/**
	 * @param OutputPage $out
	 * @param ExistingEventRegistration $registration
	 */
	public function adjustCacheForPageWithRegistration(
		OutputPage $out,
		ExistingEventRegistration $registration
	): void {
		$endTSUnix = (int)wfTimestamp( TS_UNIX, $registration->getEndUTCTimestamp() );
		$now = (int)MWTimestamp::now( TS_UNIX );
		if ( $endTSUnix < $now ) {
			// The event has ended, so it's presumably safe to allow normal caching.
			return;
		}

		// Do not let the cached version persist after the end date.
		$secondsToEventEnd = $endTSUnix - $now;
		$out->lowerCdnMaxage( $secondsToEventEnd );
	}
}
