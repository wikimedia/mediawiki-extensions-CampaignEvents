<?php
declare( strict_types=1 );
namespace MediaWiki\Extension\CampaignEvents\Hooks;

use MediaWiki\Output\OutputPage;

interface CampaignEventsGetAllEventsContentHook {
	/**
	 * @param OutputPage $outputPage
	 * @param string &$eventsContent
	 * @return void
	 */
	public function onCampaignEventsGetAllEventsContent(
		OutputPage $outputPage,
		string &$eventsContent
	): void;
}
