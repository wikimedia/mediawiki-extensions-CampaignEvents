<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration;

use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Tests\ExtensionJsonTestBase;

/**
 * @group Test
 * @coversNothing
 */
class CampaignEventsExtensionJsonTest extends ExtensionJsonTestBase {
	/** @inheritDoc */
	protected static string $extensionJsonPath = __DIR__ . '/../../../extension.json';

	public static function provideHookHandlerNames(): iterable {
		foreach ( self::getExtensionJson()['HookHandlers'] ?? [] as $hookHandlerName => $_ ) {
			if ( $hookHandlerName === 'EchoHooksHandler' && !ExtensionRegistry::getInstance()->isLoaded( 'Echo' ) ) {
				continue;
			}
			yield [ $hookHandlerName ];
		}
	}
}
