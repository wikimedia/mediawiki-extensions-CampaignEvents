<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Hooks;

interface CampaignEventsGetPolicyMessageForRegistrationHook {
	/**
	 * This hook is used to determine which message should be used as policy acknowledgement when
	 * participants register for an event.
	 *
	 * @param string|null &$message Message key. The message may contain wikitext.
	 * @return bool|void True or no return value to continue or false to abort
	 */
	public function onCampaignEventsGetPolicyMessageForRegistration( ?string &$message );
}
