<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Event;

use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;

/**
 * @covers \MediaWiki\Extension\CampaignEvents\Event\EventRegistration
 */
class EventRegistrationTest extends EventRegistrationUnitTestBase {
	protected static function makeEventFromArguments( ...$args ): EventRegistration {
		return new EventRegistration( ...$args );
	}
}
