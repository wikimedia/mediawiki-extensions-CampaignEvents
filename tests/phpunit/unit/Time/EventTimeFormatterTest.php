<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Time;

use Generator;
use Language;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Time\EventTimeFormatter;
use MediaWiki\Extension\CampaignEvents\Time\FormattedTime;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserOptionsLookup;
use MediaWikiUnitTestCase;

/**
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Time\EventTimeFormatter
 */
class EventTimeFormatterTest extends MediaWikiUnitTestCase {
	private function getFormatter( UserOptionsLookup $userOptionsLookup = null ): EventTimeFormatter {
		return new EventTimeFormatter(
			$userOptionsLookup ?? $this->createMock( UserOptionsLookup::class )
		);
	}

	private function assertFormattedTimeSame( FormattedTime $expected, FormattedTime $actual ): void {
		$this->assertSame( $expected->getTime(), $actual->getTime(), 'Time' );
		$this->assertSame( $expected->getDate(), $actual->getDate(), 'Date' );
		$this->assertSame( $expected->getTimeAndDate(), $actual->getTimeAndDate(), 'Time and date' );
	}

	/**
	 * @param EventRegistration $event
	 * @param Language $language
	 * @param UserOptionsLookup $userOptionsLookup
	 * @param FormattedTime $expected
	 * @dataProvider provideDates
	 * @covers ::formatStart
	 * @covers ::formatTimeInternal
	 * @covers ::getTimeCorrection
	 */
	public function testFormatStart(
		EventRegistration $event,
		Language $language,
		UserOptionsLookup $userOptionsLookup,
		FormattedTime $expected
	) {
		$formatter = $this->getFormatter( $userOptionsLookup );
		$this->assertFormattedTimeSame(
			$expected,
			$formatter->formatStart( $event, $language, $this->createMock( UserIdentity::class ) )
		);
	}

	/**
	 * @param EventRegistration $event
	 * @param Language $language
	 * @param UserOptionsLookup $userOptionsLookup
	 * @param FormattedTime $expected
	 * @dataProvider provideDates
	 * @covers ::formatEnd
	 * @covers ::formatTimeInternal
	 * @covers ::getTimeCorrection
	 */
	public function testFormatEnd(
		EventRegistration $event,
		Language $language,
		UserOptionsLookup $userOptionsLookup,
		FormattedTime $expected
	) {
		$formatter = $this->getFormatter( $userOptionsLookup );
		$this->assertFormattedTimeSame(
			$expected,
			$formatter->formatEnd( $event, $language, $this->createMock( UserIdentity::class ) )
		);
	}

	public function provideDates(): Generator {
		$utcTimestamp = '20221015120000';
		$event = $this->createMock( EventRegistration::class );
		$event->method( 'getStartUTCTimestamp' )->willReturn( $utcTimestamp );
		$event->method( 'getEndUTCTimestamp' )->willReturn( $utcTimestamp );

		$expected = new FormattedTime(
			'12:00',
			'2022-10-15',
			'2022-10-15 12:00'
		);

		$userTimeCorrectionPref = 'Offset|0';
		$userOptionsLookup = $this->createMock( UserOptionsLookup::class );
		$userOptionsLookup->expects( $this->atLeastOnce() )
			->method( 'getOption' )
			->with( $this->anything(), 'timecorrection' )
			->willReturn( $userTimeCorrectionPref );

		$lang = $this->createMock( Language::class );
		$lang->expects( $this->once() )
			->method( 'userTime' )
			->with( $utcTimestamp, $this->anything(), [ 'timecorrection' => $userTimeCorrectionPref ] )
			->willReturn( $expected->getTime() );
		$lang->expects( $this->once() )
			->method( 'userDate' )
			->with( $utcTimestamp, $this->anything(), [ 'timecorrection' => $userTimeCorrectionPref ] )
			->willReturn( $expected->getDate() );
		$lang->expects( $this->once() )
			->method( 'userTimeAndDate' )
			->with( $utcTimestamp, $this->anything(), [ 'timecorrection' => $userTimeCorrectionPref ] )
			->willReturn( $expected->getTimeAndDate() );

		yield [ $event, $lang, $userOptionsLookup, $expected ];
	}

	/**
	 * @param EventRegistration $event
	 * @param UserOptionsLookup $userOptionsLookup
	 * @param string $expected
	 * @dataProvider provideTimezones
	 * @covers ::formatTimezone
	 * @covers ::getTimeCorrection
	 */
	public function testFormatTimezone(
		EventRegistration $event,
		UserOptionsLookup $userOptionsLookup,
		string $expected
	) {
		$formatter = $this->getFormatter( $userOptionsLookup );
		$this->assertSame( $expected, $formatter->formatTimezone( $event, $this->createMock( UserIdentity::class ) ) );
	}

	public function provideTimezones(): Generator {
		// The EventRegistration object is currently ignored by the method
		$event = $this->createMock( EventRegistration::class );

		$geographicalZone = 'Europe/Berlin';
		$geographicalPreference = "ZoneInfo|0|$geographicalZone";
		$geographicalOptionLookup = $this->createMock( UserOptionsLookup::class );
		$geographicalOptionLookup->expects( $this->once() )
			->method( 'getOption' )
			->with( $this->anything(), 'timecorrection' )
			->willReturn( $geographicalPreference );

		yield 'Geographical' => [ $event, $geographicalOptionLookup, $geographicalZone ];

		$offset = '+02:00';
		$offsetPreference = "Offset|120";
		$offsetOptionLookup = $this->createMock( UserOptionsLookup::class );
		$offsetOptionLookup->expects( $this->once() )
			->method( 'getOption' )
			->with( $this->anything(), 'timecorrection' )
			->willReturn( $offsetPreference );
		yield 'Offset' => [ $event, $offsetOptionLookup, $offset ];
	}
}
