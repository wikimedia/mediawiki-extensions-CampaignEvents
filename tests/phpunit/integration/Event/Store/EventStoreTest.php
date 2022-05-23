<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Event\Store;

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
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Event\Store\EventStore
 * @covers ::__construct()
 */
class EventStoreTest extends MediaWikiIntegrationTestCase {
	/** @inheritDoc */
	protected $tablesUsed = [ 'campaign_events' ];

	private function getTestEvent(): EventRegistration {
		return new EventRegistration(
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
		);
	}

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
		$this->assertStatusGood( $status, 'Should be successful' );
		$savedID = $status->getValue();
		$this->assertIsInt( $savedID, 'Status value should be the insertion ID' );
		if ( $event->getID() !== null ) {
			$this->assertSame( $event->getID(), $savedID, 'ID should remain the same when updating' );
		}
		return $savedID;
	}

	/**
	 * @covers ::getEventByID
	 * @covers ::newEventFromDBRow
	 * @covers ::saveRegistration
	 */
	public function testRoundtripByID() {
		$event = $this->getTestEvent();
		$savedID = $this->storeEvent( $event );
		$storedEvent = CampaignEventsServices::getEventLookup()->getEventByID( $savedID );
		$this->assertEventsEqual( $event, $storedEvent );
		$this->assertStoredEvent( $savedID, $storedEvent );
	}

	/**
	 * @covers ::getEventByPage
	 * @covers ::newEventFromDBRow
	 * @covers ::saveRegistration
	 */
	public function testRoundtripByPage() {
		$event = $this->getTestEvent();
		$savedID = $this->storeEvent( $event );
		$storedEvent = CampaignEventsServices::getEventLookup()->getEventByPage( $event->getPage() );
		$this->assertEventsEqual( $event, $storedEvent );
		$this->assertStoredEvent( $savedID, $storedEvent );
	}

	/**
	 * @covers ::getEventByID
	 * @covers ::newEventFromDBRow
	 * @covers ::deleteRegistration
	 * @dataProvider provideEventsToDelete
	 */
	public function testDeletion( EventRegistration $registration, bool $expected ) {
		$id = $this->storeEvent( $registration );
		$eventLookup = CampaignEventsServices::getEventLookup();
		$storedEventBeforeDeletion = $eventLookup->getEventByID( $id );
		$store = CampaignEventsServices::getEventStore();
		$this->assertSame( $expected, $store->deleteRegistration( $storedEventBeforeDeletion ), 'Deletion result' );
		$storedEventAfterDeletion = CampaignEventsServices::getEventLookup()->getEventByID( $id );
		$this->assertNotNull( $storedEventAfterDeletion->getDeletionTimestamp() );
	}

	public function provideEventsToDelete(): Generator {
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
			'1646500000',
			'1646500000',
			'del' => null
		];

		yield 'Not deleted' => [
			new EventRegistration( ...array_values( $baseCtrArgs ) ),
			true
		];
		$deletedEventCtrArgs = array_replace( $baseCtrArgs, [ 'del' => '1234500000' ] );
		yield 'Already deleted' => [
			new EventRegistration( ...array_values( $deletedEventCtrArgs ) ),
			false
		];
	}
}
