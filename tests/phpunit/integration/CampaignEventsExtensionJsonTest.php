<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration;

use MediaWiki\Tests\ExtensionJsonTestBase;

/**
 * @group Test
 * @coversNothing
 */
class CampaignEventsExtensionJsonTest extends ExtensionJsonTestBase {
	/** @inheritDoc */
	protected string $extensionJsonPath = __DIR__ . '/../../../extension.json';
}
