<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit;

use DateTime;
use DateTimeZone;
use Generator;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Participants\Participant;
use MediaWiki\Extension\CampaignEvents\Questions\EventAggregatedAnswersStore;
use MediaWiki\Extension\CampaignEvents\Utils;
use MediaWiki\Utils\MWTimestamp;
use MediaWikiUnitTestCase;

/**
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Utils
 */
class UtilsTest extends MediaWikiUnitTestCase {
	private const FAKE_TIME_FOR_AGGREGATION = 123456789;

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

	/**
	 * @covers ::getAnswerAggregationTimestamp
	 * @dataProvider provideAnswerAggregationTimestamp
	 */
	public function testGetAnswerAggregationTimestamp(
		?string $firstAnswerTS,
		?string $aggregationTS,
		string $eventEndTS,
		?string $expected
	) {
		MWTimestamp::setFakeTime( self::FAKE_TIME_FOR_AGGREGATION );
		$event = $this->createMock( ExistingEventRegistration::class );
		$event->method( 'getEndUTCTimestamp' )->willReturn( $eventEndTS );
		$participant = $this->createMock( Participant::class );
		$participant->method( 'getFirstAnswerTimestamp' )->willReturn( $firstAnswerTS );
		$participant->method( 'getAggregationTimestamp' )->willReturn( $aggregationTS );
		$this->assertSame( $expected, Utils::getAnswerAggregationTimestamp( $participant, $event ) );
	}

	public static function provideAnswerAggregationTimestamp(): array {
		$ttl = EventAggregatedAnswersStore::ANSWERS_TTL_SEC;
		$endedEventTS = (string)( self::FAKE_TIME_FOR_AGGREGATION - 10 );
		$notEndedEventTS = (string)( self::FAKE_TIME_FOR_AGGREGATION + 10 );
		$recentAnswerTS = (string)( self::FAKE_TIME_FOR_AGGREGATION - ( $ttl - 1 ) );
		$recentAnswerCutoffTS = (string)( (int)$recentAnswerTS + $ttl );
		$oldAnswerTS = (string)( self::FAKE_TIME_FOR_AGGREGATION - ( $ttl + 1 ) );
		$oldAnswerCutoffTS = (string)( (int)$oldAnswerTS + $ttl );
		return [
			'Never answered, event ended' => [ null, null, $endedEventTS, null ],
			'Never answered, event has not ended' => [ null, null, $notEndedEventTS, null ],
			'Answered recently, event ended' => [ $recentAnswerTS, null, $endedEventTS, $endedEventTS ],
			'Answered recently, event has not ended' =>
				[ $recentAnswerTS, null, $notEndedEventTS, min( $notEndedEventTS, $recentAnswerCutoffTS ) ],
			'Answered long ago, event ended' =>
				[ $oldAnswerTS, null, $endedEventTS, min( $oldAnswerCutoffTS, $endedEventTS ) ],
			'Answered long ago, event has not ended' => [ $oldAnswerTS, null, $notEndedEventTS, $oldAnswerCutoffTS ],
			'Answers already aggregated, answered recently, event ended' =>
				[ $recentAnswerTS, '111111111', $endedEventTS, null ],
			'Answers already aggregated, answered long ago, event ended' =>
				[ $oldAnswerTS, '111111111', $endedEventTS, null ],
			'Answers already aggregated, answered recently, event has not ended' =>
				[ $recentAnswerTS, '111111111', $notEndedEventTS, null ],
			'Answers already aggregated, answered long ago, event has not ended' =>
				[ $oldAnswerTS, '111111111', $notEndedEventTS, null ],
		];
	}
}
