<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration;

use Generator;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWikiIntegrationTestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * @group Test
 * @covers \MediaWiki\Extension\CampaignEvents\CampaignEventsServices
 */
class CampaignEventsServicesTest extends MediaWikiIntegrationTestCase {
	/**
	 * @param string $getter
	 * @dataProvider provideServiceGetters
	 */
	public function testServiceGetters( string $getter ): void {
		// Methods are typehinted, so no need to assert
		CampaignEventsServices::$getter();
		$this->addToAssertionCount( 1 );
	}

	public function provideServiceGetters(): Generator {
		$clazz = new ReflectionClass( CampaignEventsServices::class );
		foreach ( $clazz->getMethods( ReflectionMethod::IS_PUBLIC ) as $method ) {
			$name = $method->getName();
			if ( strpos( $name, 'get' ) === 0 ) {
				yield $name => [ $name ];
			}
		}
	}
}
