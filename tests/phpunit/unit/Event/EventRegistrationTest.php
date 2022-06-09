<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Event;

use Generator;
use InvalidArgumentException;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsPage;
use MediaWikiUnitTestCase;

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
			'tracking_name' => 'Some name',
			'tracking_url' => 'https://tracking.example.org',
			'status' => EventRegistration::STATUS_OPEN,
			'start' => '1654000000',
			'end' => '1654000001',
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
	 * @covers ::getTrackingToolName
	 * @covers ::getTrackingToolURL
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
		$this->assertSame( $data['tracking_name'], $registration->getTrackingToolName(), 'tracking_name' );
		$this->assertSame( $data['tracking_url'], $registration->getTrackingToolURL(), 'tracking_url' );
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
	 * @dataProvider provideInvalidDataForInPersonMeetings
	 * @covers ::__construct
	 */
	public function testConstruct__noAddressOrCountryForInPersonMeeting( array $constructorArgs ) {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'address' );
		new EventRegistration( ...$constructorArgs );
	}

	public function provideInvalidDataForInPersonMeetings(): Generator {
		$types = [
			'In-person only' => EventRegistration::MEETING_TYPE_IN_PERSON,
			'Online and in-person' => EventRegistration::MEETING_TYPE_ONLINE_AND_IN_PERSON,
		];
		$getArgsWithData = function ( array $data ): array {
			return array_values( array_replace( $this->getValidConstructorArgs(), $data ) );
		};

		foreach ( $types as $typeDesc => $type ) {
			yield $typeDesc . ', without country and address' => [
				$getArgsWithData( [ 'meeting_type' => $type, 'country' => null, 'address' => null ] )
			];
			yield $typeDesc . ', without country, with address' => [
				$getArgsWithData( [ 'meeting_type' => $type, 'country' => null, 'address' => 'Foo bar' ] )
			];
			yield $typeDesc . ', with country, without address' => [
				$getArgsWithData( [ 'meeting_type' => $type, 'country' => 'Foo', 'address' => null ] )
			];
		}
	}
}
