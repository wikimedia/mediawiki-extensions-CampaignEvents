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
			'tracking_id' => 1,
			'tracking_event_id' => 'some-event-identifier',
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
	 * @covers ::getTrackingToolID
	 * @covers ::getTrackingToolEventID
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
		$this->assertSame( $data['tracking_id'], $registration->getTrackingToolID(), 'tracking_id' );
		$this->assertSame( $data['tracking_event_id'], $registration->getTrackingToolEventID(), 'tracking_event_id' );
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
}
