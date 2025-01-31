<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\EventPage;

use MediaWiki\Cache\HTMLCacheUpdater;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWPageProxy;
use MediaWiki\Output\OutputPage;
use MediaWiki\Utils\MWTimestamp;
use RuntimeException;

/**
 * This class is responsible of managing the cache of event pages, to avoid issues like T326593.
 */
class EventPageCacheUpdater {
	public const SERVICE_NAME = 'CampaignEventsEventPageCacheUpdater';

	private HTMLCacheUpdater $htmlCacheUpdater;

	public function __construct( HTMLCacheUpdater $htmlCacheUpdater ) {
		$this->htmlCacheUpdater = $htmlCacheUpdater;
	}

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

	public function purgeEventPageCache( EventRegistration $registration ): void {
		$eventPage = $registration->getPage();
		if ( !$eventPage instanceof MWPageProxy ) {
			throw new RuntimeException( 'Unexpected ICampaignsPage implementation.' );
		}
		$this->htmlCacheUpdater->purgeTitleUrls(
			[ $eventPage->getPageIdentity() ],
			HTMLCacheUpdater::PURGE_INTENT_TXROUND_REFLECTED
		);
	}
}
