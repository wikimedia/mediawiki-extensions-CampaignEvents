<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit;

use DateTime;
use DateTimeZone;
use Generator;
use MediaWiki\Extension\CampaignEvents\Utils;
use MediaWikiUnitTestCase;

/**
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Utils
 */
class UtilsTest extends MediaWikiUnitTestCase {
	/**
	 * @param string $str
	 * @param string $expected
	 * @dataProvider provideStringDirection
	 * @covers ::guessStringDirection
	 */
	public function testGuessStringDirection( string $str, string $expected ) {
		$this->assertSame( $expected, Utils::guessStringDirection( $str ) );
	}

	public static function provideStringDirection(): array {
		return [
			'Italian' => [ 'Perché permetterò più preludî.', 'ltr' ],
			'French' => [ 'Noël n\'est pas en aôut, garçon', 'ltr' ],
			'German' => [ 'Der Straßenkörper ist gefährlich', 'ltr' ],
			'Russian' => [ 'я не знаю этого языка', 'ltr' ],
			'Chinese' => [ '我不懂这种语言', 'ltr' ],
			'Hebrew' => [ 'אני לא מכיר את השפה הזו', 'rtl' ],
			'Arabic' => [ 'أنا آكل الفأر مع التوابل', 'rtl' ],
			'Aramaic' => [ 'ܟܠ ܒܪܢܫܐ ܒܪܝܠܗ ܚܐܪܐ ܘܒܪܒܪ', 'rtl' ],
			'Farsi' => [ 'تمام افراد بشر آزاد به دنيا مي‌آيند', 'rtl' ],
			'N\'Ko' => [ 'ߓߏ߬ߟߏ߲߬ߘߊ', 'rtl' ],
			'English + Hebrew' => [ 'Here is your Hebrew string: הנה אני', 'rtl' ],
			'Arabic + Hebrew' => [ 'اسمي עִמָּנוּאֵל', 'rtl' ],
			'Chinese + Arabic' => [ "-告诉我一些事情\n-ٱلسَّلَامُ عَلَيْكُمْ", 'rtl' ],
		];
	}

	/**
	 * @param DateTimeZone $timezone
	 * @param string $expected
	 * @covers ::timezoneToUserTimeCorrection
	 * @dataProvider provideValidTimezones
	 */
	public function testTimezoneToUserTimeCorrection( DateTimeZone $timezone, string $expected ) {
		$this->assertSame( $expected, Utils::timezoneToUserTimeCorrection( $timezone )->toString() );
	}

	public static function provideValidTimezones(): Generator {
		$romeTimezone = new DateTimeZone( 'Europe/Rome' );
		// UserTimeCorrection includes the offset, which changes over time.
		$curRomeOffset = $romeTimezone->getOffset( new DateTime() ) / 60;
		yield 'Geographical' => [ $romeTimezone, "ZoneInfo|$curRomeOffset|Europe/Rome" ];
		yield 'Positive offset' => [ new DateTimeZone( '+02:00' ), 'Offset|120' ];
		yield 'Large positive offset' => [ new DateTimeZone( '+99:00' ), 'Offset|840' ];
		yield 'Negative offset' => [ new DateTimeZone( '-05:30' ), 'Offset|-330' ];
		yield 'Large negative offset' => [ new DateTimeZone( '-99:00' ), 'Offset|-720' ];
		yield 'Abbreviation' => [ new DateTimeZone( 'CEST' ), 'Offset|120' ];
		yield 'Abbreviation 2' => [ new DateTimeZone( 'CET' ), 'Offset|60' ];
	}
}
