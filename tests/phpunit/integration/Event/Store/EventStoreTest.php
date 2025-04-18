<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Event\Store;

use DateTimeZone;
use Generator;
use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWPageProxy;
use MediaWiki\Extension\CampaignEvents\Organizers\Roles;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolAssociation;
use MediaWiki\Page\PageIdentityValue;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;
use RuntimeException;

/**
 * @group Test
 * @group Database
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Event\Store\EventStore
 * @covers ::__construct()
 */
class EventStoreTest extends MediaWikiIntegrationTestCase {
	/**
	 * @return EventRegistration
	 */
	private function getTestEvent(): EventRegistration {
		return new EventRegistration(
			null,
			'Some name',
			new MWPageProxy(
				new PageIdentityValue( 42, 0, 'Event_page', PageIdentityValue::LOCAL ),
				'Event page'
			),
			'Chat URL',
			[ 'awiki', 'bwiki' ],
			[ 'atopic', 'btopic' ],
			[
				new TrackingToolAssociation(
					1,
					'tracking-tool-event-id',
					TrackingToolAssociation::SYNC_STATUS_UNKNOWN,
					null
				)
			],
			EventRegistration::STATUS_OPEN,
			new DateTimeZone( 'UTC' ),
			'20220810000000',
			'20220810000001',
			EventRegistration::TYPE_GENERIC,
			EventRegistration::MEETING_TYPE_ONLINE_AND_IN_PERSON,
			'Meeting URL',
			'Country',
			'Address',
			[],
			null,
			null,
			null,
			false
		);
	}

	private function assertEventsEqual( EventRegistration $expected, EventRegistration $actual ): void {
		$this->assertSame( $expected->getName(), $actual->getName(), 'name' );
		$this->assertSame( $expected->getPage()->getNamespace(), $actual->getPage()->getNamespace(), 'Page ns' );
		$this->assertSame( $expected->getPage()->getDBkey(), $actual->getPage()->getDBkey(), 'Page dbkey' );
		$this->assertSame( $expected->getPage()->getWikiId(), $actual->getPage()->getWikiId(), 'Page wiki ID' );
		$this->assertSame( $expected->getWikis(), $actual->getWikis(), 'wikis' );
		$this->assertSame( $expected->getTopics(), $actual->getTopics(), 'topics' );
		$this->assertEquals( $expected->getTrackingTools(), $actual->getTrackingTools(), 'tracking tools' );
		$this->assertSame( $expected->getStatus(), $actual->getStatus(), 'status' );
		$this->assertSame( $expected->getTimezone()->getName(), $actual->getTimezone()->getName(), 'timezone' );
		$this->assertSame( $expected->getStartLocalTimestamp(), $actual->getStartLocalTimestamp(), 'local start' );
		$this->assertSame( $expected->getStartUTCTimestamp(), $actual->getStartUTCTimestamp(), 'UTC start' );
		$this->assertSame( $expected->getEndLocalTimestamp(), $actual->getEndLocalTimestamp(), 'local end' );
		$this->assertSame( $expected->getEndUTCTimestamp(), $actual->getEndUTCTimestamp(), 'UTC end' );
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
		$savedID = $store->saveRegistration( $event );
		if ( $event->getID() !== null ) {
			$this->assertSame( $event->getID(), $savedID, 'ID should remain the same when updating' );
		}
		return $savedID;
	}

	/**
	 * @covers ::getEventByID
	 * @covers ::getEventAddressRow
	 * @covers ::getEventTrackingToolRow
	 * @covers ::newEventFromDBRow
	 * @covers ::saveRegistration
	 * @covers ::updateStoredAddresses
	 * @dataProvider provideRoundtripByID
	 */
	public function testRoundtripByID( $event ) {
		$savedID = $this->storeEvent( $event );
		$storedEvent = CampaignEventsServices::getEventLookup()->getEventByID( $savedID );
		$this->assertEventsEqual( $event, $storedEvent );
		$this->assertStoredEvent( $savedID, $storedEvent );
	}

	public static function provideRoundtripByID(): Generator {
		$baseCtrArgs = self::getBaseCtrArgs();
		yield 'Event with address and country' => [
			new EventRegistration( ...array_values( $baseCtrArgs ) )
		];
		$eventWithOnlyAddress = array_replace( $baseCtrArgs, [ 'Country' => null ] );
		yield 'Event with only address' => [
			new EventRegistration( ...array_values( $eventWithOnlyAddress ) ),
		];
	}

	/**
	 * @covers ::getEventByPage
	 * @covers ::getEventAddressRow
	 * @covers ::getEventTrackingToolRow
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

	public static function provideEventsToDelete(): Generator {
		$baseCtrArgs = self::getBaseCtrArgs();
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

	/**
	 * @covers ::getEventByID
	 * @covers ::getEventAddressRow
	 */
	public function testEventWithMoreThanOneAddress() {
		$eventData = [
			'event_name' => 'test multiple address',
			'event_page_namespace' => NS_PROJECT,
			'event_page_title' => 'test',
			'event_page_prefixedtext' => 'test',
			'event_page_wiki' => 'local_wiki',
			'event_chat_url' => '',
			'event_status' => 1,
			'event_timezone' => 'UTC',
			'event_start_local' => '20220811142657',
			'event_start_utc' => '20220811142657',
			'event_end_local' => '20220811142657',
			'event_end_utc' => '20220811142657',
			'event_type' => 'generic',
			'event_meeting_type' => 3,
			'event_meeting_url' => '',
			'event_created_at' => '20220811142657',
			'event_last_edit' => '20220811142657',
			'event_deleted_at' => null,
			'event_is_test_event' => false,
		];
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'campaign_events' )
			->row( $eventData )
			->caller( __METHOD__ )
			->execute();

		$addresses = [
			[
				'cea_full_address' => 'Full address 1',
				'cea_country' => 'Country 1',
			],
			[
				'cea_full_address' => 'Full address 2',
				'cea_country' => 'Country 2',
			]
		];
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'ce_address' )
			->rows( $addresses )
			->caller( __METHOD__ )
			->execute();

		$eventAddresses = [
			[
				'ceea_event' => 1,
				'ceea_address' => 1,
			],
			[
				'ceea_event' => 1,
				'ceea_address' => 2,
			]
		];
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'ce_event_address' )
			->rows( $eventAddresses )
			->caller( __METHOD__ )
			->execute();
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Events should have only one address' );
		CampaignEventsServices::getEventLookup()->getEventByID( 1 );
	}

	private static function getBaseCtrArgs(): array {
		return [
			null,
			'Some name',
			new MWPageProxy(
				new PageIdentityValue( 42, 0, 'Event_page', PageIdentityValue::LOCAL ),
				'Event page'
			),
			'Chat URL',
			[ 'awiki', 'bwiki', 'cwiki' ],
			[ 'atopic', 'btopic' ],
			[ new TrackingToolAssociation( 42, 'some-event-id', TrackingToolAssociation::SYNC_STATUS_UNKNOWN, null ) ],
			EventRegistration::STATUS_OPEN,
			new DateTimeZone( 'UTC' ),
			'20220731080000',
			'20220731160000',
			EventRegistration::TYPE_GENERIC,
			EventRegistration::MEETING_TYPE_ONLINE_AND_IN_PERSON,
			'Meeting URL',
			'Country' => 'Country',
			'Address' => 'Address',
			[],
			null,
			null,
			'del' => null,
			false
		];
	}

	/**
	 * @covers ::getEventsByOrganizer
	 * @covers ::getAddressRowsForEvents
	 * @covers ::getTrackingToolsRowsForEvents
	 * @covers ::newEventsFromDBRows
	 */
	public function testGetEventsByOrganizer() {
		$event = $this->getTestEvent();
		$savedID = $this->storeEvent( $event );
		$orgStore = CampaignEventsServices::getOrganizersStore();
		$organizerID = 42;
		$orgStore->addOrganizerToEvent( $savedID, $organizerID, [ Roles::ROLE_CREATOR ] );
		$eventsByOrganizer = CampaignEventsServices::getEventLookup()->getEventsByOrganizer( $organizerID, 5 );
		$this->assertCount( 1, $eventsByOrganizer, 'Should be only one event' );
		$this->assertEventsEqual( $event, $eventsByOrganizer[0] );
	}

	/**
	 * @covers ::getEventsByParticipant
	 * @covers ::getAddressRowsForEvents
	 * @covers ::getTrackingToolsRowsForEvents
	 * @covers ::newEventsFromDBRows
	 */
	public function testGetEventsByParticipant() {
		$event = $this->getTestEvent();
		$savedID = $this->storeEvent( $event );
		$partStore = CampaignEventsServices::getParticipantsStore();
		$participantID = 42;
		$partStore->addParticipantToEvent( $savedID, new CentralUser( $participantID ), false, [] );
		$eventsByParticipant = CampaignEventsServices::getEventLookup()->getEventsByParticipant( $participantID, 5 );
		$this->assertCount( 1, $eventsByParticipant, 'Should be only one event' );
		$this->assertEventsEqual( $event, $eventsByParticipant[0] );
	}
}
