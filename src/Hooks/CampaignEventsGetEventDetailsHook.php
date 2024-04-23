<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Hooks;

use MediaWiki\Output\OutputPage;
use OOUI\Tag;

interface CampaignEventsGetEventDetailsHook {
	/**
	 * This hook is can be used to add data to the "Event details" section in Special:EventDetails
	 *
	 * @param Tag $infoColumn Tag for the first column in the panel, which contains the most useful information
	 * about the event itself (like dates and location).
	 * @param Tag $organizersColumn Tag for the second column in the panel, which contains the list of organizers
	 * of the event, and potentially more information that is primarily more relevant to organizers.
	 * @param int $eventID
	 * @param bool $isOrganizer
	 * @param OutputPage $outputPage
	 * @param bool $isLocalWiki
	 */
	public function onCampaignEventsGetEventDetails(
		Tag $infoColumn,
		Tag $organizersColumn,
		int $eventID,
		bool $isOrganizer,
		OutputPage $outputPage,
		bool $isLocalWiki
	): void;
}
