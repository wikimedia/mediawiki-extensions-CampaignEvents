<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Hooks;

interface CampaignEventsRegistrationFormSubmitHook {
	/**
	 * This hook is fired when the event registration form is submitted on Special:EnableEventRegistration and
	 * on Special:EditEventRegistration
	 *
	 * @param array $data
	 * @param int $eventID
	 * @return bool
	 */
	public function onCampaignEventsRegistrationFormSubmit(
		array $data,
		int $eventID
	): bool;
}
