<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Special;

use DateTime;
use DateTimeZone;
use Generator;
use MediaWiki\Extension\CampaignEvents\Special\AbstractEventRegistrationSpecialPage;
use MediaWikiUnitTestCase;

/**
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Special\AbstractEventRegistrationSpecialPage
 */
class AbstractEventRegistrationSpecialPageTest extends MediaWikiUnitTestCase {

	/**
	 * @param DateTimeZone $timezone
	 * @param string $expected
	 * @covers ::convertTimezoneForForm
	 * @dataProvider provideValidTimezones
	 */
	public function testConvertTimezoneForForm( DateTimeZone $timezone, string $expected ) {
		$this->assertSame( $expected, AbstractEventRegistrationSpecialPage::convertTimezoneForForm( $timezone ) );
	}

	public static function provideValidTimezones(): Generator {
		$romeTimezone = new DateTimeZone( 'Europe/Rome' );
		// The form value includes the offset, which changes over time.
		$curRomeOffset = $romeTimezone->getOffset( new DateTime() ) / 60;
		yield 'Geographical' => [ $romeTimezone, "ZoneInfo|$curRomeOffset|Europe/Rome" ];
		yield 'Positive offset' => [ new DateTimeZone( '+02:00' ), '+02:00' ];
		yield 'Large positive offset' => [ new DateTimeZone( '+99:00' ), '+14:00' ];
		yield 'Negative offset' => [ new DateTimeZone( '-05:30' ), '-05:30' ];
		yield 'Large negative offset' => [ new DateTimeZone( '-99:00' ), '-12:00' ];
		yield 'Abbreviation' => [ new DateTimeZone( 'CEST' ), '+02:00' ];
		yield 'Abbreviation 2' => [ new DateTimeZone( 'CET' ), '+01:00' ];
	}

}
