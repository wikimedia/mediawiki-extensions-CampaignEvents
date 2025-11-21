<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Event;

use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;

/**
 * @covers \MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration
 */
class ExistingEventRegistrationTest extends EventRegistrationUnitTestBase {
	protected static function getValidConstructorArgs(): array {
		$args = parent::getValidConstructorArgs();
		$args['id'] = 42;
		return $args;
	}

	protected static function makeEventFromArguments( ...$args ): ExistingEventRegistration {
		return new ExistingEventRegistration( ...$args );
	}
}
