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
	private const HOOK_HANDLERS_BY_EXTENSION_DEPENDENCY = [
		'EchoHooksHandler' => 'EchoHooksHandler',
		'CentralAuthContributionUserChangesHandler' => 'CentralAuth',
		'UserMergeContributionUserChangesHandler' => 'UserMerge',
	];

	/** @inheritDoc */
	protected static string $extensionJsonPath = __DIR__ . '/../../../extension.json';

	public static function provideHookHandlerNames(): iterable {
		foreach ( self::getExtensionJson()['HookHandlers'] ?? [] as $hookHandlerName => $_ ) {
			$extensionDep = self::HOOK_HANDLERS_BY_EXTENSION_DEPENDENCY[$hookHandlerName] ?? null;
			if ( $extensionDep && !ExtensionRegistry::getInstance()->isLoaded( $extensionDep ) ) {
				continue;
			}
			yield [ $hookHandlerName ];
		}
	}
}
