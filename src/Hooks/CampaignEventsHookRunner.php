<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Hooks;

use MediaWiki\HookContainer\HookContainer;

class CampaignEventsHookRunner implements CampaignEventsGetPolicyMessageHook {
	public const SERVICE_NAME = 'CampaignEventsHookRunner';

	/** @var HookContainer */
	private $hookContainer;

	/**
	 * @param HookContainer $hookContainer
	 */
	public function __construct( HookContainer $hookContainer ) {
		$this->hookContainer = $hookContainer;
	}

	/**
	 * @inheritDoc
	 */
	public function onCampaignEventsGetPolicyMessage( ?string &$message ) {
		return $this->hookContainer->run(
			'CampaignEventsGetPolicyMessage',
			[ &$message ]
		);
	}
}
