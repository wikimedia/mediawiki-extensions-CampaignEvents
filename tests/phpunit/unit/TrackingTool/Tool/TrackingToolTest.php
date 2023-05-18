<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\TrackingTool\Tool;

use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\TrackingTool\Tool\WikiEduDashboard;
use MediaWiki\Http\HttpRequestFactory;
use MediaWikiUnitTestCase;

/**
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\TrackingTool\Tool\TrackingTool
 * @covers ::__construct
 */
class TrackingToolTest extends MediaWikiUnitTestCase {
	/**
	 * @covers ::getDBID
	 */
	public function testGetDBID() {
		$dbID = 12345;
		$tool = new WikiEduDashboard(
			$this->createMock( HttpRequestFactory::class ),
			$this->createMock( CampaignsCentralUserLookup::class ),
			$dbID,
			'url',
			[ 'secret' => 'foo', 'proxy' => null ]
		);
		$this->assertSame( $dbID, $tool->getDBID() );
	}
}
