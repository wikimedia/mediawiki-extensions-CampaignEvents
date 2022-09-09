<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Event;

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
	 * @covers ::getStartTimestamp
	 * @covers ::getEndTimestamp
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
		$this->assertSame( $data['start'], $registration->getStartTimestamp(), 'start' );
		$this->assertSame( $data['end'], $registration->getEndTimestamp(), 'end' );
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
			'$startTimestamp'
		];
		yield 'End timestamp' => [
			array_values( array_replace( $this->getValidConstructorArgs(), [ 'end' => '1654000000' ] ) ),
			'$endTimestamp'
		];
	}
}
