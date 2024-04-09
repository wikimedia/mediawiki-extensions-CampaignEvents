<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Hooks;

interface CampaignEventsRegistrationFormSubmitHook {
	/**
	 * This hook is fired when the event registration form is submitted on Special:EnableEventRegistration and
	 * on Special:EditEventRegistration
	 *
	 * @param array $formFields
	 * @param int $eventID
	 */
	public function onCampaignEventsRegistrationFormSubmit(
		array $formFields,
		int $eventID
	): void;
}
