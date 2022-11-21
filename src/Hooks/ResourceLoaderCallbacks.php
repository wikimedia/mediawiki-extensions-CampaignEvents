<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Hooks;

use Config;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use ResourceLoaderContext;

class ResourceLoaderCallbacks {
	/**
	 * @param ResourceLoaderContext $context
	 * @param Config $config
	 * @return array
	 */
	public static function getEventPageData( ResourceLoaderContext $context, Config $config ): array {
		$policyMessagesLookup = CampaignEventsServices::getPolicyMessagesLookup();
		$msgKey = $policyMessagesLookup->getPolicyMessageForRegistration();
		$msgHTML = $msgKey !== null ? $context->msg( $msgKey )->parseAsBlock() : null;
		return [ 'policyMsg' => $msgHTML ];
	}
}
