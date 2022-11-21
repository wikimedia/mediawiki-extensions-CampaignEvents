<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents;

use MediaWiki\Extension\CampaignEvents\Hooks\CampaignEventsHookRunner;

class PolicyMessagesLookup {
	public const SERVICE_NAME = 'CampaignEventsPolicyMessagesLookup';

	/** @var CampaignEventsHookRunner */
	private $hookRunner;

	/**
	 * @param CampaignEventsHookRunner $hookRunner
	 */
	public function __construct( CampaignEventsHookRunner $hookRunner ) {
		$this->hookRunner = $hookRunner;
	}

	/**
	 * Looks for a policy message that should be shown to participants when registering for an event.
	 *
	 * @return string|null Message key, or null if there's no policy acknowledgement to display.
	 *  The message may contain wikitext.
	 */
	public function getPolicyMessageForRegistration(): ?string {
		$msg = null;
		$this->hookRunner->onCampaignEventsGetPolicyMessageForRegistration( $msg );
		return $msg;
	}
}
