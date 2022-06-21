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
		$policyMessageLookup = CampaignEventsServices::getPolicyMessageLookup();
		$msgKey = $policyMessageLookup->getPolicyMessage();
		$msgHTML = $msgKey !== null ? $context->msg( $msgKey )->parse() : null;
		return [ 'policyMsg' => $msgHTML ];
	}
}
