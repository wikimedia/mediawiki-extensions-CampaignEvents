<?php
declare( strict_types=1 );
namespace MediaWiki\Extension\CampaignEvents\Hooks;

use MediaWiki\Output\OutputPage;

/**
 * @internal
 */
interface CampaignEventsGetAllEventsTabsHook {
	public function onCampaignEventsGetAllEventsTabs(
		OutputPage $outputPage,
		array &$pageTabs,
		string $activeTab
	): void;
}
