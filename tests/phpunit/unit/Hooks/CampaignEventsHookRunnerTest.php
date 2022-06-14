<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Hooks;

use MediaWiki\Extension\CampaignEvents\Hooks\CampaignEventsHookRunner;
use MediaWiki\Tests\HookContainer\HookRunnerTestBase;

/**
 * @covers \MediaWiki\Extension\CampaignEvents\Hooks\CampaignEventsHookRunner
 */
class CampaignEventsHookRunnerTest extends HookRunnerTestBase {

	/**
	 * @inheritDoc
	 */
	public function provideHookRunners() {
		yield CampaignEventsHookRunner::class => [ CampaignEventsHookRunner::class ];
	}
}
