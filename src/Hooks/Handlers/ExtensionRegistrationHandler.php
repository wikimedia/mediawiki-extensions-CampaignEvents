<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Hooks\Handlers;

use MediaWiki\Extension\CampaignEvents\Worklist\WorklistContentHandler;

class ExtensionRegistrationHandler {
	public static function registrationCallback(): void {
		global $wgContentHandlers, $wgCampaignEventsEnableWorklists;
		define( 'CONTENT_MODEL_WORKLIST', 'worklist' );

		// TODO: Move to extension.json attribute when dropping feature flag
		if ( $wgCampaignEventsEnableWorklists ) {
			$wgContentHandlers[CONTENT_MODEL_WORKLIST] = WorklistContentHandler::class;
		}
	}
}
