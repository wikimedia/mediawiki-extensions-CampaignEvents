<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration;

use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Tests\ExtensionServicesTestBase;

/**
 * @group Test
 * @covers \MediaWiki\Extension\CampaignEvents\CampaignEventsServices
 */
class CampaignEventsServicesTest extends ExtensionServicesTestBase {
	/** @inheritDoc */
	protected static string $className = CampaignEventsServices::class;
	/** @inheritDoc */
	protected string $serviceNamePrefix = 'CampaignEvents';
}
