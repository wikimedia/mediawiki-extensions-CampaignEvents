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
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use Throwable;
use Wikimedia\JsonCodec\JsonCodec;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\LBFactory;
use Wikimedia\TestingAccessWrapper;

/**
 * @group Test
 * @group Database
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Event\Store\EventStore
 * @covers ::__construct()
 */
class EventStoreTest extends MediaWikiIntegrationTestCase {
	/**
	 * Placeholder for the current wiki ID that can be referenced from data providers, and will be replaced with the
	 * actual current wiki ID in makeEventWithArgs(). (See T312849)
	 */
	private const CURWIKIID_PLACEHOLDER = '*curwiki*';

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
			new Address( 'Address', 'FR' ),
			false,
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

	/**
	 * @param array $args Must have string keys as returned by `getBaseCtrArgs`. This method must NOT be called from
	 * data providers
	 */
	private function makeEventWithArgs( array $args ): EventRegistration {
		if ( is_array( $args['wikis'] ) ) {
			// Replace placeholder for current wiki ID (T312849)
			$curWikiPlaceholderKey = array_search( self::CURWIKIID_PLACEHOLDER, $args['wikis'], true );
			if ( $curWikiPlaceholderKey !== false ) {
				$args['wikis'][$curWikiPlaceholderKey] = WikiMap::getCurrentWikiId();
			}
		}
		// Replace placeholder for current wiki ID.
		return new EventRegistration( ...array_values( $args ) );
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
	public function testRoundtripByID( array $ctrArgs ) {
		$event = $this->makeEventWithArgs( $ctrArgs );
		$savedID = $this->storeEvent( $event );
		$storedEvent = CampaignEventsServices::getEventLookup()->getEventByID( $savedID );
		$this->assertEventsEqual( $event, $storedEvent );
		$this->assertStoredEvent( $savedID, $storedEvent );
	}

	public static function provideRoundtripByID(): Generator {
		$baseCtrArgs = self::getBaseCtrArgs();

		yield 'Event with address and country' => [
			$baseCtrArgs
		];

		yield 'Event with only country' => [
			array_replace(
				$baseCtrArgs,
				[ 'Address' => new Address( null, 'FR' ) ]
			),
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

		// Temporarily disable the database layer to verify that the next call from the same page is served from cache.
		// Note that some database methods will still be called, e.g. for cache options, so we can't just use a fully
		// no-op mock. Also, note that we can't call setService or that will also reset the cache.
		$mockDB = $this->createMock( IDatabase::class );
		$mockDB->expects( $this->never() )->method( 'newSelectQueryBuilder' );
		$mockDB->method( 'getSessionLagStatus' )->willReturn( [ 'lag' => 0, 'since' => 0 ] );
		$mockLBFactory = $this->createMock( LBFactory::class );
		$mockLBFactory->method( 'getReplicaDatabase' )->willReturn( $mockDB );
		$dbHelperWrapper = TestingAccessWrapper::newFromObject( CampaignEventsServices::getDatabaseHelper() );
		$dbHelperWrapper->lbFactory = $mockLBFactory;
		$cachedEvent = CampaignEventsServices::getEventLookup()->getEventByPage( $page );
		$this->assertEquals( $cachedEvent, $event );
		$dbHelperWrapper->lbFactory = $this->getServiceContainer()->getDBLoadBalancerFactory();

		$otherEvent = CampaignEventsServices::getEventLookup()->getEventByPage( $otherPage );

		CampaignEventsServices::getEventStore()->deleteRegistration( $event );

		// Move the simulated time forward once again to ensure tombstones set by deleteRegistration()
		// are expired, effectively arriving back into the present.
		$mockTime += 30;

		$postDeleteEvent = CampaignEventsServices::getEventLookup()->getEventByPage( $page );

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
	public function testDeletion( array $ctrArgs, bool $expected ) {
		$event = $this->makeEventWithArgs( $ctrArgs );
		$id = $this->storeEvent( $event );
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
			$baseCtrArgs,
			true
		];

		yield 'Already deleted' => [
			array_replace( $baseCtrArgs, [ 'del' => '1234500000' ] ),
			false
		];
	}

	private static function getBaseCtrArgs(): array {
		return [
			null,
			'name' => 'Some name',
			'page' => new MWPageProxy(
				new PageIdentityValue( 42, 0, 'Event_page', PageIdentityValue::LOCAL ),
				'Event page'
			),
			EventRegistration::STATUS_OPEN,
			new DateTimeZone( 'UTC' ),
			'start' => '20220731080000',
			'end' => '20220731160000',
			[ EventTypesRegistry::EVENT_TYPE_OTHER ],
			'wikis' => [ 'awiki', 'bwiki', 'cwiki' ],
			[ 'atopic', 'btopic' ],
			EventRegistration::PARTICIPATION_OPTION_ONLINE_AND_IN_PERSON,
			'Meeting URL',
			'address' => new Address( 'Address', 'FR' ),
			'hasContributionTracking' => false,
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
			$partStore->addParticipantToEvent( $savedID, new CentralUser( $participantID ), false, [], false );
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
		$codec = new JsonCodec();
		$event = $this->getTestEvent();
		// To update test expectation (use carefully, after having fixed the underlying issue):
		// file_put_contents( __DIR__ . '/EventRegistration.enc', $codec->toJsonString( $event ) );

		$encodedEvent = file_get_contents( __DIR__ . '/EventRegistration.enc' );
		try {
			$res = $codec->newFromJsonString( $encodedEvent );
		} catch ( Throwable $e ) {
			$this->fail(
				'Event JSON representation changed! This will break values cached in getEventByPage. Please bump ' .
				"the cache version in getEventByPage, then update the encoded object here.\nError: $e"
			);
		}
		$this->assertEquals( $event, $res );
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

	/**
	 * @covers ::getEventsForContributionAssociationByParticipant
	 * @dataProvider provideEventsForContributionAssociationByParticipant
	 */
	public function testGetEventsForContributionAssociationByParticipant(
		int $participantID,
		int $limit,
		array $eventConfigs,
		array $expectedEventNames
	) {
		// Create events based on configuration
		$participantsStore = CampaignEventsServices::getParticipantsStore();
		foreach ( $eventConfigs as $config ) {
			// Create event directly with all parameters from EventRegistration
			$event = $this->makeEventWithArgs( $config[ 'event' ] );

			$eventID = $this->storeEvent( $event );
			// Add participant if specified
			if ( $config[ 'addParticipant' ] ) {
				$participantsStore->addParticipantToEvent(
					$eventID,
					new CentralUser( $participantID ),
					$config[ 'privateRegistration' ] ?? false,
					[],
					false
				);
			}
		}

		$events = CampaignEventsServices::getEventLookup()->getEventsForContributionAssociationByParticipant(
			$participantID,
			$limit
		);

		$actualEventNames = array_values( array_map( static fn ( $event ) => $event->getName(), $events ) );
		$this->assertEquals( $expectedEventNames, $actualEventNames );

		// Verify all returned events meet the criteria
		$currentTime = wfTimestamp( TS_MW );
		foreach ( $events as $event ) {
			$this->assertNull( $event->getDeletionTimestamp(), 'Event should not be deleted' );
			$this->assertLessThanOrEqual( $currentTime, $event->getStartUTCTimestamp(), 'Event should have started' );
			$this->assertGreaterThanOrEqual(
				$currentTime,
				$event->getEndUTCTimestamp(),
				'Event should not have ended'
			);
		}
	}

	public static function provideEventsForContributionAssociationByParticipant(): Generator {
		$currentTime = wfTimestamp( TS_MW );
		$currentUnix = wfTimestamp( TS_UNIX, $currentTime );
		// 1 day ago
		$pastTime = wfTimestamp( TS_MW, $currentUnix - 86400 );
		// 1 day from now
		$futureTime = wfTimestamp( TS_MW, $currentUnix + 86400 );
		// 2 days from now
		$farFutureTime = wfTimestamp( TS_MW, $currentUnix + 172800 );

		$baseCtrArgs = self::getBaseCtrArgs();

		$buildCtrArgs = static function ( array $overrides = [] ) use ( $baseCtrArgs ): array {
			$ctrArgs = array_replace(
				$baseCtrArgs,
				$overrides
			);
			static $pageId = 12345;
			$ctrArgs['page'] = new MWPageProxy(
				new PageIdentityValue( $pageId++, 0, $ctrArgs['name'], PageIdentityValue::LOCAL ),
				$ctrArgs['name']
			);
			return $ctrArgs;
		};

		// Scenarios that return 0 events (expectedCount = 0)
		yield 'Participant not registered to ongoing events' => [
			42,
			10,
			[
				[
					'event' => $buildCtrArgs( [
						'name' => 'Ongoing Event',
						'start' => $pastTime,
						'end' => $futureTime,
						'hasContributionTracking' => true,
						'wikis' => [ self::CURWIKIID_PLACEHOLDER ]
					] ),
					'addParticipant' => false
				]
			],
			[]
		];

		yield 'Ongoing event with track contributions disabled' => [
			42,
			10,
			[
				[
					'event' => $buildCtrArgs( [
						'name' => 'Ongoing Event No Track',
						'start' => $pastTime,
						'end' => $futureTime,
						'hasContributionTracking' => false,
						'wikis' => [ self::CURWIKIID_PLACEHOLDER ]
					] ),
					'addParticipant' => true
				]
			],
			[]
		];

		yield 'Ongoing event targeting different wiki' => [
			42,
			10,
			[
				[
					'event' => $buildCtrArgs( [
						'name' => 'Ongoing Event Different Wiki',
						'start' => $pastTime,
						'end' => $futureTime,
						'hasContributionTracking' => true,
						'wikis' => [ 'differentwiki' ]
					] ),
					'addParticipant' => true
				]
			],
			[]
		];

		yield 'Ongoing event targeting all wikis but track contributions disabled' => [
			42,
			10,
			[
				[
					'event' => $buildCtrArgs( [
						'name' => 'Ongoing Event All Wikis No Track',
						'start' => $pastTime,
						'end' => $futureTime,
						'hasContributionTracking' => false,
						'wikis' => EventRegistration::ALL_WIKIS
					] ),
					'addParticipant' => true
				]
			],
			[]
		];

		yield 'Ongoing event targeting current wiki but track contributions disabled' => [
			42,
			10,
			[
				[
					'event' => $buildCtrArgs( [
						'name' => 'Ongoing Event Current Wiki No Track',
						'start' => $pastTime,
						'end' => $futureTime,
						'hasContributionTracking' => false,
						'wikis' => [ self::CURWIKIID_PLACEHOLDER ]
					] ),
					'addParticipant' => true
				]
			],
			[]
		];

		yield 'Ongoing event targeting different wiki and track contributions disabled' => [
			42,
			10,
			[
				[
					'event' => $buildCtrArgs( [
						'name' => 'Ongoing Event Different Wiki No Track',
						'start' => $pastTime,
						'end' => $futureTime,
						'hasContributionTracking' => false,
						'wikis' => [ 'differentwiki' ]
					] ),
					'addParticipant' => true
				]
			],
			[]
		];

		yield 'Event not yet started' => [
			42,
			10,
			[
				[
					'event' => $buildCtrArgs( [
						'name' => 'Future Event',
						'start' => $futureTime,
						'end' => $farFutureTime,
						'hasContributionTracking' => true,
						'wikis' => [ self::CURWIKIID_PLACEHOLDER ]
					] ),
					'addParticipant' => true
				]
			],
			[]
		];

		yield 'Event already ended' => [
			42,
			10,
			[
				[
					'event' => $buildCtrArgs( [
						'name' => 'Past Event',
						'start' => $pastTime,
						'end' => $pastTime,
						'hasContributionTracking' => true,
						'wikis' => [ self::CURWIKIID_PLACEHOLDER ]
					] ),
					'addParticipant' => true
				]
			],
			[]
		];

		yield 'Ongoing event deleted' => [
			42,
			10,
			[
				[
					'event' => $buildCtrArgs( [
						'name' => 'Ongoing Event Deleted',
						'start' => $pastTime,
						'end' => $futureTime,
						'hasContributionTracking' => true,
						'wikis' => [ self::CURWIKIID_PLACEHOLDER ],
						'del' => $currentTime
					] ),
					'addParticipant' => true
				]
			],
			[]
		];

		// Scenarios that return events (expectedCount > 0)
		yield 'Ongoing event targeting current wiki' => [
			42,
			10,
			[
				[
					'event' => $buildCtrArgs( [
						'name' => 'Ongoing Event Current Wiki',
						'start' => $pastTime,
						'end' => $futureTime,
						'hasContributionTracking' => true,
						'wikis' => [ self::CURWIKIID_PLACEHOLDER ]
					] ),
					'addParticipant' => true
				]
			],
			[ 'Ongoing Event Current Wiki' ]
		];

		yield 'Ongoing event targeting all wikis' => [
			42,
			10,
			[
				[
					'event' => $buildCtrArgs( [
						'name' => 'Ongoing Event All Wikis',
						'start' => $pastTime,
						'end' => $futureTime,
						'hasContributionTracking' => true,
						'wikis' => EventRegistration::ALL_WIKIS
					] ),
					'addParticipant' => true
				]
			],
			[ 'Ongoing Event All Wikis' ]
		];
		yield 'Ongoing event with private registration' => [
			42,
			10,
			[
				[
					'event' => $buildCtrArgs( [
						'name' => 'Ongoing Event Private',
						'start' => $pastTime,
						'end' => $futureTime,
						'hasContributionTracking' => true,
						'wikis' => [ self::CURWIKIID_PLACEHOLDER ]
					] ),
					'addParticipant' => true,
					'privateRegistration' => true
				]
			],
			[ 'Ongoing Event Private' ]
		];

		yield 'Multiple ongoing events with limit' => [
			42,
			2,
			[
				[
					'event' => $buildCtrArgs( [
						'name' => 'Ongoing Event 1',
						'start' => $pastTime,
						'end' => $futureTime,
						'hasContributionTracking' => true,
						'wikis' => [ self::CURWIKIID_PLACEHOLDER ]
					] ),
					'addParticipant' => true
				],
				[
					'event' => $buildCtrArgs( [
						'name' => 'Ongoing Event 2',
						'start' => $pastTime,
						'end' => $futureTime,
						'hasContributionTracking' => true,
						'wikis' => [ self::CURWIKIID_PLACEHOLDER ]
					] ),
					'addParticipant' => true
				],
				[
					'event' => $buildCtrArgs( [
						'name' => 'Ongoing Event 3',
						'start' => $pastTime,
						'end' => $futureTime,
						'hasContributionTracking' => true,
						'wikis' => [ self::CURWIKIID_PLACEHOLDER ]
					] ),
					'addParticipant' => true
				]
			],
			[ 'Ongoing Event 1', 'Ongoing Event 2' ]
		];

		yield 'Mixed ongoing events valid and invalid' => [
			42,
			10,
			[
				[
					'event' => $buildCtrArgs( [
						'name' => 'Valid Ongoing Event',
						'start' => $pastTime,
						'end' => $futureTime,
						'hasContributionTracking' => true,
						'wikis' => [ self::CURWIKIID_PLACEHOLDER ]
					] ),
					'addParticipant' => true
				],
				[
					'event' => $buildCtrArgs( [
						'name' => 'Invalid Ongoing Event - No Track',
						'start' => $pastTime,
						'end' => $futureTime,
						'hasContributionTracking' => false,
						'wikis' => [ self::CURWIKIID_PLACEHOLDER ]
					] ),
					'addParticipant' => true
				],
				[
					'event' => $buildCtrArgs( [
						'name' => 'Future Event',
						'start' => $futureTime,
						'end' => $farFutureTime,
						'hasContributionTracking' => true,
						'wikis' => [ self::CURWIKIID_PLACEHOLDER ]
					] ),
					'addParticipant' => true
				]
			],
			[ 'Valid Ongoing Event' ]
		];
	}
}
