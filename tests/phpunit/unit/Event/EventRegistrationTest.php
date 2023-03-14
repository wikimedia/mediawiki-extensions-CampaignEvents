<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Event;

use DateTimeZone;
use Generator;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsPage;
use MediaWikiUnitTestCase;
use Wikimedia\Assert\ParameterAssertionException;

/**
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Event\EventRegistration
 */
class EventRegistrationTest extends MediaWikiUnitTestCase {
	private function getValidConstructorArgs(): array {
		return [
			'id' => null,
			'name' => 'Name',
			'page' => $this->createMock( ICampaignsPage::class ),
			'chat' => 'https://chat.example.org',
			'tracking_tools' => [ 1 => 'some-event-identifier' ],
			'status' => EventRegistration::STATUS_OPEN,
			'timezone' => new DateTimeZone( 'UTC' ),
			'start' => '20220815120000',
			'end' => '20220815120001',
			'type' => EventRegistration::TYPE_GENERIC,
			'meeting_type' => EventRegistration::MEETING_TYPE_ONLINE_AND_IN_PERSON,
			'meeting_url' => 'https://meet.example.org',
			'country' => 'Some country',
			'address' => 'Some address',
			'creation' => '1650000000',
			'last_edit' => '1651000000',
			'deletion' => null
		];
	}

	/**
	 * @covers ::__construct
	 * @covers ::getID
	 * @covers ::getName
	 * @covers ::getPage
	 * @covers ::getChatURL
	 * @covers ::getTrackingTools
	 * @covers ::getStatus
	 * @covers ::getTimezone
	 * @covers ::getStartLocalTimestamp
	 * @covers ::getEndLocalTimestamp
	 * @covers ::getType
	 * @covers ::getMeetingType
	 * @covers ::getMeetingURL
	 * @covers ::getMeetingCountry
	 * @covers ::getMeetingAddress
	 * @covers ::getCreationTimestamp
	 * @covers ::getLastEditTimestamp
	 * @covers ::getDeletionTimestamp
	 */
	public function testConstructAndGetters() {
		$data = $this->getValidConstructorArgs();
		$registration = new EventRegistration( ...array_values( $data ) );
		$this->assertSame( $data['id'], $registration->getID(), 'id' );
		$this->assertSame( $data['name'], $registration->getName(), 'name' );
		$this->assertSame( $data['page'], $registration->getPage(), 'page' );
		$this->assertSame( $data['chat'], $registration->getChatURL(), 'chat' );
		$this->assertSame( $data['tracking_tools'], $registration->getTrackingTools(), 'tracking_tools' );
		$this->assertSame( $data['status'], $registration->getStatus(), 'status' );
		$this->assertSame( $data['timezone']->getName(), $registration->getTimezone()->getName(), 'timezone' );
		$this->assertSame( $data['start'], $registration->getStartLocalTimestamp(), 'start' );
		$this->assertSame( $data['end'], $registration->getEndLocalTimestamp(), 'end' );
		$this->assertSame( $data['type'], $registration->getType(), 'type' );
		$this->assertSame( $data['meeting_type'], $registration->getMeetingType(), 'meeting_type' );
		$this->assertSame( $data['meeting_url'], $registration->getMeetingURL(), 'meeting_url' );
		$this->assertSame( $data['country'], $registration->getMeetingCountry(), 'country' );
		$this->assertSame( $data['address'], $registration->getMeetingAddress(), 'address' );
		$this->assertSame( $data['creation'], $registration->getCreationTimestamp(), 'creation' );
		$this->assertSame( $data['last_edit'], $registration->getLastEditTimestamp(), 'last_edit' );
		$this->assertSame( $data['deletion'], $registration->getDeletionTimestamp(), 'deletion' );
	}

	/**
	 * @param array $constructorArgs
	 * @param string $expectedWrongParam
	 * @dataProvider provideInvalidTimestampFormat
	 * @covers ::__construct
	 */
	public function testInvalidTimestampFormat( array $constructorArgs, string $expectedWrongParam ) {
		$this->expectException( ParameterAssertionException::class );
		$this->expectExceptionMessage( $expectedWrongParam );
		new EventRegistration( ...$constructorArgs );
	}

	public function provideInvalidTimestampFormat(): Generator {
		yield 'Start timestamp' => [
			array_values( array_replace( $this->getValidConstructorArgs(), [ 'start' => '1654000000' ] ) ),
			'$startLocalTimestamp'
		];
		yield 'End timestamp' => [
			array_values( array_replace( $this->getValidConstructorArgs(), [ 'end' => '1654000000' ] ) ),
			'$endLocalTimestamp'
		];
	}

	/**
	 * @covers ::getStartUTCTimestamp
	 * @covers ::getEndUTCTimestamp
	 * @dataProvider provideEventsForUTCConversion
	 */
	public function testUTCConversion( EventRegistration $event, string $expectedStartUTC, string $expectedEndUTC ) {
		$this->assertSame( $expectedStartUTC, $event->getStartUTCTimestamp(), 'start' );
		$this->assertSame( $expectedEndUTC, $event->getEndUTCTimestamp(), 'end' );
	}

	public function provideEventsForUTCConversion(): Generator {
		$baseCtrArgs = $this->getValidConstructorArgs();
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
	 * @covers ::getStartUTCTimestamp
	 * @covers ::getEndUTCTimestamp
	 * @dataProvider provideAmbiguousLocalTimes
	 */
	public function testAmbiguousLocalTimes( EventRegistration $event, string $expectedLocal, string $expectedUTC ) {
		$this->assertSame( $expectedLocal, $event->getStartLocalTimestamp(), 'local start' );
		$this->assertSame( $expectedUTC, $event->getStartUTCTimestamp(), 'UTC start' );
		$this->assertSame( $expectedLocal, $event->getEndLocalTimestamp(), 'local end' );
		$this->assertSame( $expectedUTC, $event->getEndUTCTimestamp(), 'UTC end' );
	}

	public function provideAmbiguousLocalTimes(): Generator {
		$baseCtrArgs = $this->getValidConstructorArgs();
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
}
