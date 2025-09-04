<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Event\Store;

use DateTimeZone;
use Generator;
use InvalidArgumentException;
use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Extension\CampaignEvents\Address\Address;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\EventTypesRegistry;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventNotFoundException;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWPageProxy;
use MediaWiki\Extension\CampaignEvents\Organizers\Roles;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolAssociation;
use MediaWiki\Page\PageIdentityValue;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;
use Throwable;

/**
 * @group Test
 * @group Database
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Event\Store\EventStore
 * @covers ::__construct()
 */
class EventStoreTest extends MediaWikiIntegrationTestCase {
	protected function setUp(): void {
		parent::setUp();
		$this->overrideConfigValue( 'CampaignEventsCountrySchemaMigrationStage', MIGRATION_WRITE_NEW );
	}

	private function getTestEvent( ?MWPageProxy $page = null ): EventRegistration {
		return new EventRegistration(
			null,
			'Some name',
			$page ?? new MWPageProxy(
				new PageIdentityValue( 42, 0, 'Event_page', PageIdentityValue::LOCAL ),
				'Event page'
			),
			EventRegistration::STATUS_OPEN,
			new DateTimeZone( 'UTC' ),
			'20220810000000',
			'20220810000001',
			[ EventTypesRegistry::EVENT_TYPE_OTHER ],
			[ 'awiki', 'bwiki' ],
			[ 'atopic', 'btopic' ],
			EventRegistration::PARTICIPATION_OPTION_ONLINE_AND_IN_PERSON,
			'Meeting URL',
			new Address( 'Address', null, 'FR' ),
			[
				new TrackingToolAssociation(
					1,
					'tracking-tool-event-id',
					TrackingToolAssociation::SYNC_STATUS_UNKNOWN,
					null
				)
			],
			'Chat URL',
			false,
			[],
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
		$this->assertSame( $expected->getStatus(), $actual->getStatus(), 'status' );
		$this->assertSame( $expected->getTimezone()->getName(), $actual->getTimezone()->getName(), 'timezone' );
		$this->assertSame( $expected->getStartLocalTimestamp(), $actual->getStartLocalTimestamp(), 'local start' );
		$this->assertSame( $expected->getStartUTCTimestamp(), $actual->getStartUTCTimestamp(), 'UTC start' );
		$this->assertSame( $expected->getEndLocalTimestamp(), $actual->getEndLocalTimestamp(), 'local end' );
		$this->assertSame( $expected->getEndUTCTimestamp(), $actual->getEndUTCTimestamp(), 'UTC end' );
		$this->assertSame( $expected->getTypes(), $actual->getTypes(), 'Types' );
		$this->assertSame( $expected->getWikis(), $actual->getWikis(), 'wikis' );
		$this->assertSame( $expected->getTopics(), $actual->getTopics(), 'topics' );
		$this->assertEquals( $expected->getTrackingTools(), $actual->getTrackingTools(), 'tracking tools' );
		$this->assertSame(
			$expected->getParticipationOptions(),
			$actual->getParticipationOptions(),
			'participation options'
		);
		$this->assertSame( $expected->getMeetingURL(), $actual->getMeetingURL(), 'meeting URL' );
		$this->assertEquals( $expected->getAddress(), $actual->getAddress(), 'address' );
		$this->assertSame( $expected->getChatURL(), $actual->getChatURL(), 'chat' );
		$this->assertSame( $expected->getIsTestEvent(), $actual->getIsTestEvent(), 'is test' );
		$this->assertSame( $expected->getParticipantQuestions(), $actual->getParticipantQuestions(), 'questions' );
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
	 * @covers ::getEventTrackingToolRow
	 * @covers ::newEventFromDBRow
	 * @covers ::saveRegistration
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
		$eventWithOnlyAddress = array_replace(
			$baseCtrArgs,
			[ 'Address' => new Address( 'Some address', null, null ) ]
		);
		yield 'Event with only address' => [
			new EventRegistration( ...array_values( $eventWithOnlyAddress ) ),
		];
		$eventWithOnlyCountry = array_replace(
			$baseCtrArgs,
			[ 'Address' => new Address( null, 'France', null ) ]
		);
		yield 'Event with only country' => [
			new EventRegistration( ...array_values( $eventWithOnlyCountry ) ),
		];
	}

	/**
	 * @covers ::getEventByPage
	 * @covers ::getEventTrackingToolRow
	 * @covers ::loadEventFromDB
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
	 * @covers ::getEventByPage
	 * @covers ::loadEventFromDB
	 */
	public function testMissingEvent(): void {
		$page = new MWPageProxy(
			new PageIdentityValue( 42, 0, 'Event_page', PageIdentityValue::LOCAL ),
			'Event page'
		);

		$this->expectException( EventNotFoundException::class );
		$this->expectExceptionMessage( "No event found for the given page (ns={$page->getNamespace()}, " .
			"dbkey={$page->getDBkey()}, wiki={$page->getWikiId()})" );

		CampaignEventsServices::getEventLookup()->getEventByPage( $page );
	}

	/**
	 * @covers ::getEventByPage
	 * @covers ::saveRegistration
	 * @covers ::deleteRegistration
	 * @covers ::loadEventFromDB
	 */
	public function testEventCaching(): void {
		// Create two events for different pages, look them up, and delete one of them.
		$page = new MWPageProxy(
			new PageIdentityValue( 42, 0, 'Event_page', PageIdentityValue::LOCAL ),
			'Event page'
		);
		$otherPage = new MWPageProxy(
			new PageIdentityValue( 53, 0, 'Other_page', PageIdentityValue::LOCAL ),
			'Other page'
		);

		$storedEvent = $this->getTestEvent( $page );
		$otherStoredEvent = $this->getTestEvent( $otherPage );

		$cache = $this->getServiceContainer()->getMainWANObjectCache();

		// Ensure the tombstones set by the purges triggered by saveRegistration() are expired.
		// We do this by ensuring the purges occur in the simulated past, rather than making the purges
		// occur in the present and the fetches occur in the simulated future, because moving time forward
		// would confuse getWithSetCallback() into thinking there is high transaction lag,
		// rejecting any returned value.
		$mockTime = wfTimestamp() - 60;
		$cache->setMockTime( $mockTime );

		CampaignEventsServices::getEventStore()->saveRegistration( $storedEvent );
		CampaignEventsServices::getEventStore()->saveRegistration( $otherStoredEvent );

		$mockTime += 30;

		$event = CampaignEventsServices::getEventLookup()->getEventByPage( $page );
		$cachedEvent = CampaignEventsServices::getEventLookup()->getEventByPage( $page );
		$otherEvent = CampaignEventsServices::getEventLookup()->getEventByPage( $otherPage );

		CampaignEventsServices::getEventStore()->deleteRegistration( $event );

		// Move the simulated time forward once again to ensure tombstones set by deleteRegistration()
		// are expired, effectively arriving back into the present.
		$mockTime += 30;

		$postDeleteEvent = CampaignEventsServices::getEventLookup()->getEventByPage( $page );

		$this->assertSame( $event, $cachedEvent, 'Events should be cached per page' );
		$this->assertTrue(
			$event->getPage()->equals( $storedEvent->getPage() ),
			'Returned event should have correct page for the first page'
		);
		$this->assertNull(
			$event->getDeletionTimestamp(),
			'Event should not be marked as deleted'
		);

		$this->assertTrue(
			$otherEvent->getPage()->equals( $otherStoredEvent->getPage() ),
			'Returned event should have correct page for the other page'
		);
		$this->assertNotNull(
			$postDeleteEvent->getDeletionTimestamp(),
			'Event cache should be purged after marking an event as deleted'
		);
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

	private static function getBaseCtrArgs(): array {
		return [
			null,
			'Some name',
			new MWPageProxy(
				new PageIdentityValue( 42, 0, 'Event_page', PageIdentityValue::LOCAL ),
				'Event page'
			),
			EventRegistration::STATUS_OPEN,
			new DateTimeZone( 'UTC' ),
			'20220731080000',
			'20220731160000',
			[ EventTypesRegistry::EVENT_TYPE_OTHER ],
			[ 'awiki', 'bwiki', 'cwiki' ],
			[ 'atopic', 'btopic' ],
			EventRegistration::PARTICIPATION_OPTION_ONLINE_AND_IN_PERSON,
			'Meeting URL',
			'address' => new Address( 'Address', null, 'FR' ),
			[ new TrackingToolAssociation( 42, 'some-event-id', TrackingToolAssociation::SYNC_STATUS_UNKNOWN, null ) ],
			'Chat URL',
			false,
			[],
			null,
			null,
			'del' => null,
		];
	}

	/**
	 * @covers ::getEventsByOrganizer
	 * @covers ::getTrackingToolsRowsForEvents
	 * @covers ::newEventsFromDBRows
	 * @dataProvider provideEventsByOrganizer
	 */
	public function testGetEventsByOrganizer( bool $hasEvent ) {
		$organizerID = 42;
		if ( $hasEvent ) {
			$event = $this->getTestEvent();
			$savedID = $this->storeEvent( $event );
			$orgStore = CampaignEventsServices::getOrganizersStore();
			$orgStore->addOrganizerToEvent( $savedID, $organizerID, [ Roles::ROLE_CREATOR ] );
		}
		$eventsByOrganizer = CampaignEventsServices::getEventLookup()->getEventsByOrganizer( $organizerID, 5 );
		$this->assertCount( $hasEvent ? 1 : 0, $eventsByOrganizer, 'Number of events' );
		if ( $hasEvent ) {
			$this->assertEventsEqual( $event, reset( $eventsByOrganizer ) );
		}
	}

	public static function provideEventsByOrganizer() {
		yield 'Has one event' => [ true ];
		yield 'Has no events' => [ false ];
	}

	/**
	 * @covers ::getEventsByParticipant
	 * @covers ::getTrackingToolsRowsForEvents
	 * @covers ::newEventsFromDBRows
	 * @dataProvider provideEventsByParticipant
	 */
	public function testGetEventsByParticipant( bool $hasEvent ) {
		$participantID = 42;
		if ( $hasEvent ) {
			$event = $this->getTestEvent();
			$savedID = $this->storeEvent( $event );
			$partStore = CampaignEventsServices::getParticipantsStore();
			$partStore->addParticipantToEvent( $savedID, new CentralUser( $participantID ), false, [] );
		}
		$eventsByParticipant = CampaignEventsServices::getEventLookup()->getEventsByParticipant( $participantID, 5 );
		$this->assertCount( $hasEvent ? 1 : 0, $eventsByParticipant, 'Number of events' );
		if ( $hasEvent ) {
			$this->assertEventsEqual( $event, reset( $eventsByParticipant ) );
		}
	}

	public static function provideEventsByParticipant() {
		yield 'Has one event' => [ true ];
		yield 'Has no events' => [ false ];
	}

	public function testCacheCompatibility() {
		$serializedEvent = file_get_contents( __DIR__ . '/EventRegistration.ser' );
		try {
			$unserialized = unserialize( $serializedEvent );
		} catch ( Throwable $e ) {
			$this->fail(
				'Event serialization changed! This will break values cached in getEventByPage. Because of the error ' .
				'below, bumping the cache version is not sufficient. Please change the EventRegistration class ' .
				'definition to avoid the fatal error, then bump the cache version in getEventByPage and update the ' .
				"serialized object here.\nError:\n" . $e->getMessage()
			);
		}
		$event = $this->getTestEvent();
		$this->assertSame(
			serialize( $event ),
			$serializedEvent,
			'Event serialization changed! This will break values cached in getEventByPage. Please bump the ' .
				'cache version in getEventByPage, then update the serialized object here. (You can disregard this ' .
				'message if you changed values, but not format, for the test event above only)'
		);
		$this->assertEquals( $event, $unserialized, 'Unserialized events should be equal' );
	}

	public function testNewEventsFromDBRows__rowWithoutID() {
		$rowWithoutID = (object)[];
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Got row without event ID' );
		CampaignEventsServices::getEventLookup()->newEventsFromDBRows( $this->getDb(), [ $rowWithoutID ] );
	}

	public function testNewEventsFromDBRows__incompleteRow() {
		$incompleteRow = (object)[ 'event_id' => 42 ];
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Event row lacks required prop' );
		CampaignEventsServices::getEventLookup()->newEventsFromDBRows( $this->getDb(), [ $incompleteRow ] );
	}
}
