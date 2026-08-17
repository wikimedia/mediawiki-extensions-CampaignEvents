<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Hooks\Handlers;

class ExtensionRegistrationHandler {
	public static function registrationCallback(): void {
		define( 'CONTENT_MODEL_WORKLIST', 'worklist' );
	}
}
