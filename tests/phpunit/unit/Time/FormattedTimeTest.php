<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Time;

use MediaWiki\Extension\CampaignEvents\Time\FormattedTime;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\CampaignEvents\Time\FormattedTime
 */
class FormattedTimeTest extends MediaWikiUnitTestCase {
	public function testGetters() {
		$time = '12:00';
		$date = '2022-11-03';
		$timeAndDate = '2022-11-03 12:00';

		$formattedTime = new FormattedTime( $time, $date, $timeAndDate );
		$this->assertSame( $time, $formattedTime->getTime() );
		$this->assertSame( $date, $formattedTime->getDate() );
		$this->assertSame( $timeAndDate, $formattedTime->getTimeAndDate() );
	}
}
