<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Time;

use DateTimeZone;
use Generator;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Time\EventTimeFormatter;
use MediaWiki\Extension\CampaignEvents\Time\FormattedTime;
use MediaWiki\Language\Language;
use MediaWiki\User\Options\UserOptionsLookup;
use MediaWiki\User\UserIdentity;
use MediaWikiUnitTestCase;

/**
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Time\EventTimeFormatter
 */
class EventTimeFormatterTest extends MediaWikiUnitTestCase {
	private function getFormatter( ?UserOptionsLookup $userOptionsLookup = null ): EventTimeFormatter {
		return new EventTimeFormatter(
			$userOptionsLookup ?? $this->createMock( UserOptionsLookup::class )
		);
	}

	private function assertFormattedTimeSame( FormattedTime $expected, FormattedTime $actual ): void {
		$this->assertSame( $expected->getTime(), $actual->getTime(), 'Time' );
		$this->assertSame( $expected->getDate(), $actual->getDate(), 'Date' );
		$this->assertSame( $expected->getTimeAndDate(), $actual->getTimeAndDate(), 'Time and date' );
	}

	private function doTestFormat(
		bool $isStart,
		int $meetingType,
		string $expectedTimeCorrection,
		DateTimeZone $eventTimezone,
		?string $userPrefTimeCorrection
	) {
		$utcTimestamp = '20221015120000';
		// Note that the expected value here doesn't really matter, as we force Language to return it.
		// The important bit are the assertions on the Language methods and the arguments they receive.
		$expected = new FormattedTime( '12:00', '2022-10-15', '2022-10-15 12:00' );

		$event = $this->createMock( EventRegistration::class );
		$event->method( 'getStartUTCTimestamp' )->willReturn( $utcTimestamp );
		$event->method( 'getEndUTCTimestamp' )->willReturn( $utcTimestamp );
		$event->method( 'getMeetingType' )->willReturn( $meetingType );
		$event->method( 'getTimezone' )->willReturn( $eventTimezone );

		$user = $this->createMock( UserIdentity::class );

		$language = $this->createMock( Language::class );
		$language->expects( $this->once() )
			->method( 'userTime' )
			->with( $utcTimestamp, $user, [ 'timecorrection' => $expectedTimeCorrection ] )
			->willReturn( $expected->getTime() );
		$language->expects( $this->once() )
			->method( 'userDate' )
			->with( $utcTimestamp, $user, [ 'timecorrection' => $expectedTimeCorrection ] )
			->willReturn( $expected->getDate() );
		$language->expects( $this->once() )
			->method( 'userTimeAndDate' )
			->with( $utcTimestamp, $user, [ 'timecorrection' => $expectedTimeCorrection ] )
			->willReturn( $expected->getTimeAndDate() );

		$userOptionsLookup = $this->createMock( UserOptionsLookup::class );
		if ( $userPrefTimeCorrection ) {
			$userOptionsLookup->expects( $this->atLeastOnce() )
				->method( 'getOption' )
				->with( $user, 'timecorrection' )
				->willReturn( $userPrefTimeCorrection );
		} else {
			$userOptionsLookup->expects( $this->never() )
				->method( 'getOption' );
		}

		$formatter = $this->getFormatter( $userOptionsLookup );
		$actual = $isStart
			? $formatter->formatStart( $event, $language, $user )
			: $formatter->formatEnd( $event, $language, $user );
		$this->assertFormattedTimeSame( $expected, $actual );
	}

	/**
	 * @dataProvider provideDates
	 * @covers ::formatStart
	 * @covers ::formatTimeInternal
	 * @covers ::getTimeCorrection
	 */
	public function testFormatStart(
		int $meetingType,
		string $expectedTimeCorrection,
		DateTimeZone $eventTimezone,
		?string $userPrefTimeCorrection
	) {
		$this->doTestFormat(
			true,
			$meetingType,
			$expectedTimeCorrection,
			$eventTimezone,
			$userPrefTimeCorrection
		);
	}

	/**
	 * @dataProvider provideDates
	 * @covers ::formatEnd
	 * @covers ::formatTimeInternal
	 * @covers ::getTimeCorrection
	 */
	public function testFormatEnd(
		int $meetingType,
		string $expectedTimeCorrection,
		DateTimeZone $eventTimezone,
		?string $userPrefTimeCorrection
	) {
		$this->doTestFormat(
			false,
			$meetingType,
			$expectedTimeCorrection,
			$eventTimezone,
			$userPrefTimeCorrection
		);
	}

	public static function provideDates(): Generator {
		$userTimeCorrectionPref = 'Offset|0';
		$eventTimezone = new DateTimeZone( '+02:00' );
		$eventTimeCorrection = 'Offset|120';

		yield 'Online event' => [
			EventRegistration::MEETING_TYPE_ONLINE,
			$userTimeCorrectionPref,
			$eventTimezone,
			$userTimeCorrectionPref,
		];

		yield 'In-person event' => [
			EventRegistration::MEETING_TYPE_IN_PERSON,
			$eventTimeCorrection,
			$eventTimezone,
			null,
		];

		yield 'Hybrid event' => [
			EventRegistration::MEETING_TYPE_ONLINE_AND_IN_PERSON,
			$userTimeCorrectionPref,
			$eventTimezone,
			$userTimeCorrectionPref,
		];
	}

	/**
	 * @dataProvider provideTimezones
	 * @covers ::formatTimezone
	 * @covers ::getTimeCorrection
	 */
	public function testFormatTimezone(
		int $meetingType,
		?string $timeZone,
		?string $expectedTimeCorrection,
		string $expected
	) {
		$event = $this->createMock( EventRegistration::class );
		$event->method( 'getMeetingType' )->willReturn( $meetingType );
		if ( $timeZone !== null ) {
			$event->method( 'getTimezone' )->willReturn( new DateTimeZone( $timeZone ) );
		} else {
			$event->expects( $this->never() )->method( 'getTimezone' );
		}

		$user = $this->createMock( UserIdentity::class );

		$userOptionsLookup = $this->createMock( UserOptionsLookup::class );
		if ( $expectedTimeCorrection !== null ) {
			$userOptionsLookup->expects( $this->once() )
				->method( 'getOption' )
				->with( $user, 'timecorrection' )
				->willReturn( $expectedTimeCorrection );
		} else {
			$userOptionsLookup->expects( $this->never() )
				->method( 'getOption' )
				->with( $user, 'timecorrection' );
		}

		$formatter = $this->getFormatter( $userOptionsLookup );
		$this->assertSame( $expected, $formatter->formatTimezone( $event, $user ) );
	}

	public static function provideTimezones(): Generator {
		$geographicalZone = 'Europe/Berlin';
		$geographicalTimeCorrection = "ZoneInfo|0|$geographicalZone";

		yield 'Online event, geographical' => [
			EventRegistration::MEETING_TYPE_ONLINE,
			null,
			$geographicalTimeCorrection,
			$geographicalZone
		];
		yield 'In-person event, geographical' => [
			EventRegistration::MEETING_TYPE_IN_PERSON,
			$geographicalZone,
			null,
			$geographicalZone
		];
		yield 'Hybrid event, geographical' => [
			EventRegistration::MEETING_TYPE_ONLINE_AND_IN_PERSON,
			null,
			$geographicalTimeCorrection,
			$geographicalZone
		];

		$offset = '+02:00';
		$offsetTimeCorrection = "Offset|120";

		yield 'Online event, offset' => [
			EventRegistration::MEETING_TYPE_ONLINE,
			null,
			$offsetTimeCorrection,
			$offset
		];
		yield 'In-person event, offset' => [
			EventRegistration::MEETING_TYPE_IN_PERSON,
			$offset,
			null,
			$offset
		];
		yield 'Hybrid event, offset' => [
			EventRegistration::MEETING_TYPE_ONLINE_AND_IN_PERSON,
			null,
			$offsetTimeCorrection,
			$offset
		];
	}
}
