<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Hooks\Handlers;

use ExtensionRegistry;
use RuntimeException;

class ExtensionFunctionHandler {
	public static function checkCLDRIsInstalled(): void {
		$registry = ExtensionRegistry::getInstance();
		// T398224 - We can't declare it as a required extension in extension.json
		if ( !( $registry->isLoaded( 'cldr' ) || $registry->isLoaded( 'CLDR' ) ) ) {
			throw new RuntimeException( 'The cldr extension is NOT installed' );
		}
	}
}
