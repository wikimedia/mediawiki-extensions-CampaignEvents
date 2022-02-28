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

	/**
	 * @param EventRegistration $event
	 * @covers ::getEvent
	 * @covers ::newEventFromDBRow
	 * @covers ::saveRegistration
	 * @dataProvider provideEvents
	 */
	public function testRoundtrip( EventRegistration $event ) {
		$eventPage = $event->getPage();
		$this->assertSame(
			WikiAwareEntity::LOCAL,
			$eventPage->getWikiId(),
			'Precondition: this test should use a local page'
		);
		$eventPageTitle = Title::makeTitle( $eventPage->getNamespace(), $eventPage->getDBkey() );
		$this->editPage( $eventPageTitle, 'Making sure that the event page exist' );

		$store = CampaignEventsServices::getEventStore();
		$id = $store->saveRegistration( $event );
		if ( $event->getID() !== null ) {
			$this->assertSame( $event->getID(), $id, 'ID should remain the same when updating' );
		}

		$storedEvent = CampaignEventsServices::getEventLookup()->getEvent( $id );
		$this->assertSame( $id, $storedEvent->getID(), 'ID' );
		$this->assertSame( $event->getName(), $storedEvent->getName(), 'name' );
		$this->assertSame( $event->getPage()->getNamespace(), $storedEvent->getPage()->getNamespace(), 'Page ns' );
		$this->assertSame( $event->getPage()->getDBkey(), $storedEvent->getPage()->getDBkey(), 'Page dbkey' );
		$this->assertSame( $event->getPage()->getWikiId(), $storedEvent->getPage()->getWikiId(), 'Page wiki ID' );
		$this->assertSame( $event->getTrackingToolName(), $storedEvent->getTrackingToolName(), 'tracking tool name' );
		$this->assertSame( $event->getTrackingToolURL(), $storedEvent->getTrackingToolURL(), 'tracking tool URL' );
		$this->assertSame( $event->getStatus(), $storedEvent->getStatus(), 'status' );
		$this->assertSame( $event->getStartTimestamp(), $storedEvent->getStartTimestamp(), 'start' );
		$this->assertSame( $event->getEndTimestamp(), $storedEvent->getEndTimestamp(), 'end' );
		$this->assertSame( $event->getType(), $storedEvent->getType(), 'type' );
		$this->assertSame( $event->getMeetingType(), $storedEvent->getMeetingType(), 'meeting type' );
		$this->assertSame( $event->getMeetingURL(), $storedEvent->getMeetingURL(), 'meeting URL' );
		$this->assertSame( $event->getMeetingCountry(), $storedEvent->getMeetingCountry(), 'country' );
		$this->assertSame( $event->getMeetingAddress(), $storedEvent->getMeetingAddress(), 'address' );
		$this->assertNotNull( $storedEvent->getCreationTimestamp(), 'Creation ts' );
		$this->assertNotNull( $storedEvent->getLastEditTimestamp(), 'Last edit ts' );
		$this->assertSame(
			$storedEvent->getCreationTimestamp(),
			$storedEvent->getLastEditTimestamp(),
			'Creation = last edit'
		);
		$this->assertNull( $storedEvent->getDeletionTimestamp() );
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
