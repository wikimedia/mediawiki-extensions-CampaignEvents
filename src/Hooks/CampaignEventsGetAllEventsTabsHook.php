<?php
declare( strict_types=1 );
namespace MediaWiki\Extension\CampaignEvents\Hooks;

use MediaWiki\SpecialPage\SpecialPage;

/**
 * @internal
 */
interface CampaignEventsGetAllEventsTabsHook {
	public function onCampaignEventsGetAllEventsTabs(
		SpecialPage $specialPage,
		array &$pageTabs,
		string $activeTab
	): void;
}
