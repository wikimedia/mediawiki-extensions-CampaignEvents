<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Store;

use Generator;
use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWPageProxy;
use MediaWiki\Page\PageIdentityValue;
use MediaWikiIntegrationTestCase;
use Title;

/**
 * @group Test
 * @group Database
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Store\EventStore
 * @covers ::__construct()
 */
class EventStoreTest extends MediaWikiIntegrationTestCase {
	/** @inheritDoc */
	protected $tablesUsed = [ 'campaign_events' ];

	private function assertEventsEqual( EventRegistration $expected, EventRegistration $actual ): void {
		$this->assertSame( $expected->getName(), $actual->getName(), 'name' );
		$this->assertSame( $expected->getPage()->getNamespace(), $actual->getPage()->getNamespace(), 'Page ns' );
		$this->assertSame( $expected->getPage()->getDBkey(), $actual->getPage()->getDBkey(), 'Page dbkey' );
		$this->assertSame( $expected->getPage()->getWikiId(), $actual->getPage()->getWikiId(), 'Page wiki ID' );
		$this->assertSame( $expected->getTrackingToolName(), $actual->getTrackingToolName(), 'tracking tool name' );
		$this->assertSame( $expected->getTrackingToolURL(), $actual->getTrackingToolURL(), 'tracking tool URL' );
		$this->assertSame( $expected->getStatus(), $actual->getStatus(), 'status' );
		$this->assertSame( $expected->getStartTimestamp(), $actual->getStartTimestamp(), 'start' );
		$this->assertSame( $expected->getEndTimestamp(), $actual->getEndTimestamp(), 'end' );
		$this->assertSame( $expected->getType(), $actual->getType(), 'type' );
		$this->assertSame( $expected->getMeetingType(), $actual->getMeetingType(), 'meeting type' );
		$this->assertSame( $expected->getMeetingURL(), $actual->getMeetingURL(), 'meeting URL' );
		$this->assertSame( $expected->getMeetingCountry(), $actual->getMeetingCountry(), 'country' );
		$this->assertSame( $expected->getMeetingAddress(), $actual->getMeetingAddress(), 'address' );
	}

	private function assertStoredEvent( int $insertID, EventRegistration $storedEvent ) {
		$this->assertSame( $insertID, $storedEvent->getID(), 'ID' );
		$this->assertNotNull( $storedEvent->getCreationTimestamp(), 'Creation ts' );
		$this->assertNotNull( $storedEvent->getLastEditTimestamp(), 'Last edit ts' );
		$this->assertSame(
			$storedEvent->getCreationTimestamp(),
			$storedEvent->getLastEditTimestamp(),
			'Creation = last edit'
		);
		$this->assertNull( $storedEvent->getDeletionTimestamp() );
	}

	private function storeEvent( EventRegistration $event ): int {
		$eventPage = $event->getPage();
		$this->assertSame(
			WikiAwareEntity::LOCAL,
			$eventPage->getWikiId(),
			'Precondition: this test should use a local page'
		);
		$eventPageTitle = Title::makeTitle( $eventPage->getNamespace(), $eventPage->getDBkey() );
		$this->editPage( $eventPageTitle, 'Making sure that the event page exist' );

		$store = CampaignEventsServices::getEventStore();
		$status = $store->saveRegistration( $event );
		$this->assertTrue( $status->isGood(), 'Should be successful' );
		$savedID = $status->getValue();
		$this->assertIsInt( $savedID, 'Status value should be the insertion ID' );
		if ( $event->getID() !== null ) {
			$this->assertSame( $event->getID(), $savedID, 'ID should remain the same when updating' );
		}
		return $savedID;
	}

	/**
	 * @param EventRegistration $event
	 * @covers ::getEventByID
	 * @covers ::newEventFromDBRow
	 * @covers ::saveRegistration
	 * @dataProvider provideEvents
	 */
	public function testRoundtripByID( EventRegistration $event ) {
		$savedID = $this->storeEvent( $event );
		$storedEvent = CampaignEventsServices::getEventLookup()->getEventByID( $savedID );
		$this->assertEventsEqual( $event, $storedEvent );
		$this->assertStoredEvent( $savedID, $storedEvent );
	}

	/**
	 * @param EventRegistration $event
	 * @covers ::getEventByPage
	 * @covers ::newEventFromDBRow
	 * @covers ::saveRegistration
	 * @dataProvider provideEvents
	 */
	public function testRoundtripByPage( EventRegistration $event ) {
		$savedID = $this->storeEvent( $event );
		$storedEvent = CampaignEventsServices::getEventLookup()->getEventByPage( $event->getPage() );
		$this->assertEventsEqual( $event, $storedEvent );
		$this->assertStoredEvent( $savedID, $storedEvent );
	}

	public function provideEvents(): Generator {
		$baseCtrArgs = [
			null,
			'Some name',
			new MWPageProxy( new PageIdentityValue( 42, 0, 'Event_page', PageIdentityValue::LOCAL ) ),
			'Chat URL',
			'Tracking tool name',
			'Tracking tool URL',
			EventRegistration::STATUS_OPEN,
			'1646700000',
			'1646800000',
			EventRegistration::TYPE_GENERIC,
			EventRegistration::MEETING_TYPE_ONLINE_AND_PHYSICAL,
			'Meeting URL',
			'Country',
			'Address',
			null,
			null,
			null
		];

		yield 'New event' => [ new EventRegistration( ...$baseCtrArgs ) ];
		yield 'Existing event' => [ new EventRegistration( ...array_replace( $baseCtrArgs, [ 0 => 42 ] ) ) ];
	}
}
