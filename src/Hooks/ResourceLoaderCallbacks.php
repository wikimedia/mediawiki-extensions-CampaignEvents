<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Hooks;

use MediaWiki\Config\Config;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\ResourceLoader\Context;

class ResourceLoaderCallbacks {
	/**
	 * @param Context $context
	 * @param Config $config
	 * @return array
	 */
	public static function getEventPageData( Context $context, Config $config ): array {
		$policyMessagesLookup = CampaignEventsServices::getPolicyMessagesLookup();
		$msgKey = $policyMessagesLookup->getPolicyMessageForRegistration();
		$msgHTML = $msgKey !== null ? $context->msg( $msgKey )->parseAsBlock() : null;
		return [ 'policyMsg' => $msgHTML ];
	}
}
