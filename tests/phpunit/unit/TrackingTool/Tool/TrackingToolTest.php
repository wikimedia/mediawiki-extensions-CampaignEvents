<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\TrackingTool\Tool;

use MediaWiki\Extension\CampaignEvents\TrackingTool\Tool\WikiEduDashboard;
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
		$tool = new WikiEduDashboard( $dbID, 'url', [ 'secret' => 'foo' ] );
		$this->assertSame( $dbID, $tool->getDBID() );
	}
}
