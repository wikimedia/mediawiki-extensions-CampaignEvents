<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Event;

use DateTimeZone;
use Generator;
use MediaWiki\Extension\CampaignEvents\Address\Address;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\EventTypesRegistry;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWPageProxy;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolAssociation;
use MediaWiki\Page\PageIdentityValue;
use MediaWiki\Utils\MWTimestamp;
use MediaWikiUnitTestCase;
use Wikimedia\Assert\ParameterAssertionException;

/**
 * @covers \MediaWiki\Extension\CampaignEvents\Event\EventRegistration
 */
class EventRegistrationTest extends MediaWikiUnitTestCase {
	private static function getValidConstructorArgs(): array {
		return [
			'id' => null,
			'name' => 'Name',
			'page' => new MWPageProxy(
				new PageIdentityValue( 42, NS_PROJECT, 'Name', PageIdentityValue::LOCAL ),
				'Project:Name'
			),
			'status' => EventRegistration::STATUS_OPEN,
			'timezone' => new DateTimeZone( 'UTC' ),
			'start' => '20220815120000',
			'end' => '20220815120001',
			'types' => [ EventTypesRegistry::EVENT_TYPE_OTHER ],
			'wikis' => [ 'awiki', 'bwiki' ],
			'topics' => [ 'atopic', 'btopic' ],
			'participation_options' => EventRegistration::PARTICIPATION_OPTION_ONLINE_AND_IN_PERSON,
			'meeting_url' => 'https://meet.example.org',
			'address' => new Address( 'Some address', 'France', null ),
			'tracks_contributions' => true,
			'tracking_tools' => [
				new TrackingToolAssociation(
					1,
					'some-event-identifier',
					TrackingToolAssociation::SYNC_STATUS_UNKNOWN,
					null
				)
			],
			'chat' => 'https://chat.example.org',
			'is_test_event' => false,
			'questions' => [],
			'creation' => '1650000000',
			'last_edit' => '1651000000',
			'deletion' => null,
		];
	}

	public function testConstructAndGetters() {
		$data = self::getValidConstructorArgs();
		$registration = new EventRegistration( ...array_values( $data ) );
		$this->assertSame( $data['id'], $registration->getID(), 'id' );
		$this->assertSame( $data['name'], $registration->getName(), 'name' );
		$this->assertSame( $data['page'], $registration->getPage(), 'page' );
		$this->assertSame( $data['status'], $registration->getStatus(), 'status' );
		$this->assertSame( $data['timezone']->getName(), $registration->getTimezone()->getName(), 'timezone' );
		$this->assertSame( $data['start'], $registration->getStartLocalTimestamp(), 'start' );
		$this->assertSame( $data['end'], $registration->getEndLocalTimestamp(), 'end' );
		$this->assertSame( $data['types'], $registration->getTypes(), 'types' );
		$this->assertSame(
			$data['tracks_contributions'],
			$registration->hasContributionTracking(),
			'contribution tracking'
		);
		$this->assertSame( $data['wikis'], $registration->getWikis(), 'wikis' );
		$this->assertSame( $data['topics'], $registration->getTopics(), 'topics' );
		$this->assertSame( $data['tracking_tools'], $registration->getTrackingTools(), 'tracking_tools' );
		$this->assertSame(
			$data['participation_options'],
			$registration->getParticipationOptions(),
			'participation_options'
		);
		$this->assertSame( $data['meeting_url'], $registration->getMeetingURL(), 'meeting_url' );
		$this->assertSame( $data['address'], $registration->getAddress(), 'address' );
		$this->assertSame( $data['chat'], $registration->getChatURL(), 'chat' );
		$this->assertSame( $data['is_test_event'], $registration->getIsTestEvent(), 'is_test_event' );
		$this->assertSame( $data['questions'], $registration->getParticipantQuestions(), 'questions' );
		$this->assertSame( $data['creation'], $registration->getCreationTimestamp(), 'creation' );
		$this->assertSame( $data['last_edit'], $registration->getLastEditTimestamp(), 'last_edit' );
		$this->assertSame( $data['deletion'], $registration->getDeletionTimestamp(), 'deletion' );
	}

	/**
	 * @param array $constructorArgs
	 * @param string $expectedWrongParam
	 * @dataProvider provideInvalidTimestampFormat
	 */
	public function testInvalidTimestampFormat( array $constructorArgs, string $expectedWrongParam ) {
		$this->expectException( ParameterAssertionException::class );
		$this->expectExceptionMessage( $expectedWrongParam );
		new EventRegistration( ...$constructorArgs );
	}

	public static function provideInvalidTimestampFormat(): Generator {
		yield 'Start timestamp' => [
			array_values( array_replace( self::getValidConstructorArgs(), [ 'start' => '1654000000' ] ) ),
			'$startLocalTimestamp'
		];
		yield 'End timestamp' => [
			array_values( array_replace( self::getValidConstructorArgs(), [ 'end' => '1654000000' ] ) ),
			'$endLocalTimestamp'
		];
	}

	/**
	 * @dataProvider provideEventsForUTCConversion
	 */
	public function testUTCConversion( EventRegistration $event, string $expectedStartUTC, string $expectedEndUTC ) {
		$this->assertSame( $expectedStartUTC, $event->getStartUTCTimestamp(), 'start' );
		$this->assertSame( $expectedEndUTC, $event->getEndUTCTimestamp(), 'end' );
	}

	public static function provideEventsForUTCConversion(): Generator {
		$baseCtrArgs = self::getValidConstructorArgs();
		$replaceArgs = static function ( array $replacements ) use ( $baseCtrArgs ): array {
			return array_values( array_replace( $baseCtrArgs, $replacements ) );
		};

		// NOTE: This test uses dates in the past, so that the timezone conversion rules are known for sure.
		// The premise here is that Europe/Rome is UTC+1 in summer (DST) and UTC+2 in winter (no DST).
		$summerStart = '20220815143000';
		$summerStartMinus2Hours = '20220815123000';
		$summerEnd = '20220815153000';
		$summerEndMinus2Hours = '20220815133000';

		$winterStart = '20211215143000';
		$winterStartMinus1Hour = '20211215133000';
		$winterStartMinus2Hours = '20211215123000';
		$winterEnd = '20211215153000';
		$winterEndMinus1Hour = '20211215143000';
		$winterEndMinus2Hours = '20211215133000';

		yield 'Local in UTC' => [
			new EventRegistration( ...$replaceArgs( [
				'timezone' => new DateTimeZone( 'UTC' ),
				'start' => $summerStart,
				'end' => $summerEnd
			] ) ),
			$summerStart,
			$summerEnd
		];

		yield 'Europe/Rome with DST' => [
			new EventRegistration( ...$replaceArgs( [
				'timezone' => new DateTimeZone( 'Europe/Rome' ),
				'start' => $summerStart,
				'end' => $summerEnd
			] ) ),
			$summerStartMinus2Hours,
			$summerEndMinus2Hours
		];

		yield 'Europe/Rome without DST' => [
			new EventRegistration( ...$replaceArgs( [
				'timezone' => new DateTimeZone( 'Europe/Rome' ),
				'start' => $winterStart,
				'end' => $winterEnd
			] ) ),
			$winterStartMinus1Hour,
			$winterEndMinus1Hour
		];

		yield 'Fixed offset in summer' => [
			new EventRegistration( ...$replaceArgs( [
				'timezone' => new DateTimeZone( '+02:00' ),
				'start' => $summerStart,
				'end' => $summerEnd
			] ) ),
			$summerStartMinus2Hours,
			$summerEndMinus2Hours
		];

		yield 'Fixed offset in winter' => [
			new EventRegistration( ...$replaceArgs( [
				'timezone' => new DateTimeZone( '+02:00' ),
				'start' => $winterStart,
				'end' => $winterEnd
			] ) ),
			$winterStartMinus2Hours,
			$winterEndMinus2Hours
		];
	}

	/**
	 * Test what happens when the user provides a local time that happens to coincide with a DST change, meaning the
	 * provided time was skipped or happened twice.
	 *
	 * @dataProvider provideAmbiguousLocalTimes
	 */
	public function testAmbiguousLocalTimes( EventRegistration $event, string $expectedLocal, string $expectedUTC ) {
		$this->assertSame( $expectedLocal, $event->getStartLocalTimestamp(), 'local start' );
		$this->assertSame( $expectedUTC, $event->getStartUTCTimestamp(), 'UTC start' );
		$this->assertSame( $expectedLocal, $event->getEndLocalTimestamp(), 'local end' );
		$this->assertSame( $expectedUTC, $event->getEndUTCTimestamp(), 'UTC end' );
	}

	public static function provideAmbiguousLocalTimes(): Generator {
		$baseCtrArgs = self::getValidConstructorArgs();
		$replaceArgs = static function ( array $replacements ) use ( $baseCtrArgs ): array {
			return array_values( array_replace( $baseCtrArgs, $replacements ) );
		};

		// Assumption: Europe/Rome switched from UTC+1 to UTC+2 on 2022-03-27 02:00 and back to UTC+1
		// on 2022-10-30 03:00. Thus, on March 27 the time went from 01:59:59 to 03:00:00; on October 30,
		// it went from 02:59:50 to 02:00:00.

		yield 'Skipped time' => [
			new EventRegistration( ...$replaceArgs( [
				'timezone' => new DateTimeZone( 'Europe/Rome' ),
				'start' => '20220327023000',
				'end' => '20220327023000'
			] ) ),
			// PHP would automatically add 1 hour to the skipped time (so 03:30) but we preserve the value
			// entered by the organizer.
			'20220327023000',
			// Input time is considered to be after the switch, so UTC+2
			'20220327013000'
		];

		yield 'Repeated time' => [
			new EventRegistration( ...$replaceArgs( [
				'timezone' => new DateTimeZone( 'Europe/Rome' ),
				'start' => '20221030023000',
				'end' => '20221030023000'
			] ) ),
			// Local time is unchanged because it did happen
			'20221030023000',
			// PHP assumes the last occurrence of that time, so UTC+1
			'20221030013000'
		];
	}

	private static function getPastOngoingAndFutureEvents(): array {
		$now = (int)MWTimestamp::now( TS_UNIX );
		$farPastTS = wfTimestamp( TS_MW, $now - 200000 );
		$pastTS = wfTimestamp( TS_MW, $now - 100000 );
		$futureTS = wfTimestamp( TS_MW, $now + 100000 );
		$farFutureTS = wfTimestamp( TS_MW, $now + 200000 );

		$pastEvent = new EventRegistration(
			...array_values( array_replace(
				self::getValidConstructorArgs(),
				[ 'start' => $farPastTS, 'end' => $pastTS ]
			) )
		);

		$ongoingEvent = new EventRegistration(
			...array_values( array_replace(
				self::getValidConstructorArgs(),
				[ 'start' => $pastTS, 'end' => $futureTS ]
			) )
		);

		$futureEvent = new EventRegistration(
			...array_values( array_replace(
				self::getValidConstructorArgs(),
				[ 'start' => $futureTS, 'end' => $farFutureTS ]
			) )
		);

		return [ 'past' => $pastEvent, 'ongoing' => $ongoingEvent, 'future' => $futureEvent ];
	}

	/**
	 * @dataProvider provideIsPast
	 */
	public function testIsPast( EventRegistration $event, bool $expected ) {
		$this->assertSame( $expected, $event->isPast() );
	}

	public static function provideIsPast(): Generator {
		$testEvents = self::getPastOngoingAndFutureEvents();

		yield 'past' => [ $testEvents['past'], true ];
		yield 'ongoing' => [ $testEvents['ongoing'], false ];
		yield 'future' => [ $testEvents['future'], false ];
	}

	/**
	 * @dataProvider provideIsFuture
	 */
	public function testIsFuture( EventRegistration $event, bool $expected ) {
		$this->assertSame( $expected, $event->isFuture() );
	}

	public static function provideIsFuture(): Generator {
		$testEvents = self::getPastOngoingAndFutureEvents();

		yield 'past' => [ $testEvents['past'], false ];
		yield 'ongoing' => [ $testEvents['ongoing'], false ];
		yield 'future' => [ $testEvents['future'], true ];
	}

	/**
	 * @dataProvider provideIsOngoing
	 */
	public function testIsOngoing( EventRegistration $event, bool $expected ) {
		$this->assertSame( $expected, $event->isOngoing() );
	}

	public static function provideIsOngoing(): Generator {
		$testEvents = self::getPastOngoingAndFutureEvents();

		yield 'past' => [ $testEvents['past'], false ];
		yield 'ongoing' => [ $testEvents['ongoing'], true ];
		yield 'future' => [ $testEvents['future'], false ];
	}
}
