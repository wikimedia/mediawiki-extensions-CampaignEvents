<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents;

use MediaWiki\Extension\CampaignEvents\Hooks\CampaignEventsHookRunner;

class PolicyMessageLookup {
	public const SERVICE_NAME = 'CampaignEventsPolicyMessageLookup';

	/** @var CampaignEventsHookRunner */
	private $hookRunner;

	/**
	 * @param CampaignEventsHookRunner $hookRunner
	 */
	public function __construct( CampaignEventsHookRunner $hookRunner ) {
		$this->hookRunner = $hookRunner;
	}

	/**
	 * @return string|null Message key, or null if there's no policy acknowledgement to display.
	 *  The message may contain wikitext.
	 */
	public function getPolicyMessage(): ?string {
		$msg = null;
		$this->hookRunner->onCampaignEventsGetPolicyMessage( $msg );
		return $msg;
	}
}
