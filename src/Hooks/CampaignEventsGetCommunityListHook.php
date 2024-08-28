<?php
declare( strict_types=1 );
namespace MediaWiki\Extension\CampaignEvents\Hooks;

use MediaWiki\Output\OutputPage;

interface CampaignEventsGetCommunityListHook {
	/**
	 * @param OutputPage $outputPage
	 * @param string &$eventsContent
	 * @return void
	 */
	public function onCampaignEventsGetCommunityList(
		OutputPage $outputPage,
		string &$eventsContent
	): void;
}
