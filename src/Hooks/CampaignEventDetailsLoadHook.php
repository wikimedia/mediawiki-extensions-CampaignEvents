<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Hooks;

interface CampaignEventDetailsLoadHook {
	/**
	 * This hook is fired when the user access the Special:EventDetails
	 *
	 * @param array &$items
	 * @param int $eventID
	 * @param string $languageCode
	 * @return bool
	 */
	public function onCampaignEventDetailsLoad( array &$items, int $eventID, string $languageCode ): bool;
}
