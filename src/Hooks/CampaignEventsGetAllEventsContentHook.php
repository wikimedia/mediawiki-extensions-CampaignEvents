<?php
declare( strict_types=1 );
namespace MediaWiki\Extension\CampaignEvents\Hooks;

use MediaWiki\Output\OutputPage;

interface CampaignEventsGetAllEventsContentHook {
	public function onCampaignEventsGetAllEventsContent(
		OutputPage $outputPage,
		string &$eventsContent
	): void;
}
