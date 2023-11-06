<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration;

use ExtensionRegistry;
use MediaWiki\Tests\ExtensionJsonTestBase;

/**
 * @group Test
 * @coversNothing
 */
class CampaignEventsExtensionJsonTest extends ExtensionJsonTestBase {
	/** @inheritDoc */
	protected string $extensionJsonPath = __DIR__ . '/../../../extension.json';

	public function provideHookHandlerNames(): iterable {
		foreach ( $this->getExtensionJson()['HookHandlers'] ?? [] as $hookHandlerName => $_ ) {
			if ( $hookHandlerName === 'EchoHooksHandler' && !ExtensionRegistry::getInstance()->isLoaded( 'Echo' ) ) {
				continue;
			}
			yield [ $hookHandlerName ];
		}
	}
}
