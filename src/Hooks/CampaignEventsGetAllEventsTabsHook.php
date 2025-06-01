<?php
declare( strict_types=1 );
namespace MediaWiki\Extension\CampaignEvents\Hooks;

use MediaWiki\SpecialPage\SpecialPage;

/**
 * @internal
 */
interface CampaignEventsGetAllEventsTabsHook {
	/**
	 * @param SpecialPage $specialPage
	 * @param array<string,array<string,mixed>> &$pageTabs
	 * @param string $activeTab
	 */
	public function onCampaignEventsGetAllEventsTabs(
		SpecialPage $specialPage,
		array &$pageTabs,
		string $activeTab
	): void;
}
