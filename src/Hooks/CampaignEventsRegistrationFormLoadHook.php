<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Hooks;

interface CampaignEventsRegistrationFormLoadHook {
	/**
	 * This hook is fired when the event registration form is loaded on Special:EnableEventRegistration and
	 * on Special:EditEventRegistration
	 *
	 * @param array &$formFields
	 * @param int|null $eventID
	 */
	public function onCampaignEventsRegistrationFormLoad( array &$formFields, ?int $eventID );
}
