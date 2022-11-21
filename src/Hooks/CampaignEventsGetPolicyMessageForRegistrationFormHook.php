<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Hooks;

interface CampaignEventsGetPolicyMessageForRegistrationFormHook {
	/**
	 * This hook is used to determine which policy message should be shown in the footer of the form
	 * for enabling/editing registration.
	 *
	 * @param string|null &$message Message key. The message may contain wikitext.
	 * @return bool|void True or no return value to continue or false to abort
	 */
	public function onCampaignEventsGetPolicyMessageForRegistrationForm( ?string &$message );
}
