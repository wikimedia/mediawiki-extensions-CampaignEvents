<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\TrackingTool;

use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolAssociation;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolUpdater;
use MediaWikiUnitTestCase;
use ReflectionClass;
use Wikimedia\TestingAccessWrapper;

/**
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolUpdater
 */
class TrackingToolUpdaterTest extends MediaWikiUnitTestCase {
	/**
	 * Tests that all the sync status constants are mapped to a DB value.
	 * @coversNothing
	 */
	public function testStatusMapping() {
		$expected = [];
		$assocRefl = new ReflectionClass( TrackingToolAssociation::class );
		foreach ( $assocRefl->getConstants() as $name => $val ) {
			if ( str_starts_with( $name, 'SYNC_STATUS_' ) ) {
				$expected[] = $val;
			}
		}

		$actualMap = TestingAccessWrapper::constant( TrackingToolUpdater::class, 'SYNC_STATUS_TO_DB_MAP' );
		$this->assertArrayEquals( $expected, array_keys( $actualMap ) );
	}
}
