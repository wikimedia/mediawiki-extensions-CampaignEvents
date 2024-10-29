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
		$userTimeCorrectionPref = 'Offset|0';
		$eventTimezone = new DateTimeZone( '+02:00' );
		$eventTimeCorrection = 'Offset|120';

		$getEventMock = function ( int $meetingType ) use ( $utcTimestamp, $eventTimezone ): EventRegistration {
			$event = $this->createMock( EventRegistration::class );
			$event->method( 'getStartUTCTimestamp' )->willReturn( $utcTimestamp );
			$event->method( 'getEndUTCTimestamp' )->willReturn( $utcTimestamp );
			$event->method( 'getMeetingType' )->willReturn( $meetingType );
			$event->method( 'getTimezone' )->willReturn( $eventTimezone );
			return $event;
		};

		// Note that the expected value here doesn't really matter, as we force Language to return it.
		// The important bit are the assertions on the Language methods and the arguments they receive.
		$expected = new FormattedTime( '12:00', '2022-10-15', '2022-10-15 12:00' );

		$userOptionsLookup = $this->createMock( UserOptionsLookup::class );
		$userOptionsLookup->expects( $this->atLeastOnce() )
			->method( 'getOption' )
			->with( $this->anything(), 'timecorrection' )
			->willReturn( $userTimeCorrectionPref );

		$mockLangWithExpectedTimeCorrection = function ( string $timeCorrection ) use (
			$utcTimestamp,
			$expected
		): Language {
			$lang = $this->createMock( Language::class );
			$lang->expects( $this->once() )
				->method( 'userTime' )
				->with( $utcTimestamp, $this->anything(), [ 'timecorrection' => $timeCorrection ] )
				->willReturn( $expected->getTime() );
			$lang->expects( $this->once() )
				->method( 'userDate' )
				->with( $utcTimestamp, $this->anything(), [ 'timecorrection' => $timeCorrection ] )
				->willReturn( $expected->getDate() );
			$lang->expects( $this->once() )
				->method( 'userTimeAndDate' )
				->with( $utcTimestamp, $this->anything(), [ 'timecorrection' => $timeCorrection ] )
				->willReturn( $expected->getTimeAndDate() );
			return $lang;
		};

		$onlineEvent = $getEventMock( EventRegistration::MEETING_TYPE_ONLINE );
		yield 'Online event' => [
			$onlineEvent,
			$mockLangWithExpectedTimeCorrection( $userTimeCorrectionPref ),
			$userOptionsLookup,
			$expected
		];

		$inPersonEvent = $getEventMock( EventRegistration::MEETING_TYPE_IN_PERSON );
		yield 'In-person event' => [
			$inPersonEvent,
			$mockLangWithExpectedTimeCorrection( $eventTimeCorrection ),
			$userOptionsLookup,
			$expected
		];

		$hybridEvent = $getEventMock( EventRegistration::MEETING_TYPE_ONLINE_AND_IN_PERSON );
		yield 'Hybrid event' => [
			$hybridEvent,
			$mockLangWithExpectedTimeCorrection( $userTimeCorrectionPref ),
			$userOptionsLookup,
			$expected
		];
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
		$mockEvent = function ( int $meetingType, ?string $timeZone = null ): EventRegistration {
			$event = $this->createMock( EventRegistration::class );
			$event->method( 'getMeetingType' )->willReturn( $meetingType );
			if ( $timeZone !== null ) {
				$event->method( 'getTimezone' )->willReturn( new DateTimeZone( $timeZone ) );
			} else {
				$event->expects( $this->never() )->method( 'getTimezone' );
			}
			return $event;
		};

		$mockOptionsLookup = function ( ?string $expectedTimeCorrection ): UserOptionsLookup {
			$lookup = $this->createMock( UserOptionsLookup::class );
			if ( $expectedTimeCorrection !== null ) {
				$lookup->expects( $this->once() )
					->method( 'getOption' )
					->with( $this->anything(), 'timecorrection' )
					->willReturn( $expectedTimeCorrection );
			} else {
				$lookup->expects( $this->never() )
					->method( 'getOption' )
					->with( $this->anything(), 'timecorrection' );
			}
			return $lookup;
		};

		$geographicalZone = 'Europe/Berlin';
		$geographicalTimeCorrection = "ZoneInfo|0|$geographicalZone";

		yield 'Online event, geographical' => [
			$mockEvent( EventRegistration::MEETING_TYPE_ONLINE ),
			$mockOptionsLookup( $geographicalTimeCorrection ),
			$geographicalZone
		];
		yield 'In-person event, geographical' => [
			$mockEvent( EventRegistration::MEETING_TYPE_IN_PERSON, $geographicalZone ),
			$mockOptionsLookup( null ),
			$geographicalZone
		];
		yield 'Hybrid event, geographical' => [
			$mockEvent( EventRegistration::MEETING_TYPE_ONLINE_AND_IN_PERSON ),
			$mockOptionsLookup( $geographicalTimeCorrection ),
			$geographicalZone
		];

		$offset = '+02:00';
		$offsetTimeCorrection = "Offset|120";

		yield 'Online event, offset' => [
			$mockEvent( EventRegistration::MEETING_TYPE_ONLINE ),
			$mockOptionsLookup( $offsetTimeCorrection ),
			$offset
		];
		yield 'In-person event, offset' => [
			$mockEvent( EventRegistration::MEETING_TYPE_IN_PERSON, $offset ),
			$mockOptionsLookup( null ),
			$offset
		];
		yield 'Hybrid event, offset' => [
			$mockEvent( EventRegistration::MEETING_TYPE_ONLINE_AND_IN_PERSON ),
			$mockOptionsLookup( $offsetTimeCorrection ),
			$offset
		];
	}
}
