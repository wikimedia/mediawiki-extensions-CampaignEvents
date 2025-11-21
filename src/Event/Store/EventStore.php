<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Event\Store;

use DateTimeZone;
use InvalidArgumentException;
use LogicException;
use MediaWiki\Extension\CampaignEvents\Address\Address;
use MediaWiki\Extension\CampaignEvents\Address\AddressStore;
use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\EventTypesRegistry;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFactory;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWPageProxy;
use MediaWiki\Extension\CampaignEvents\Questions\EventQuestionsStore;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolAssociation;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolUpdater;
use MediaWiki\Extension\CampaignEvents\Utils;
use MediaWiki\WikiMap\WikiMap;
use RuntimeException;
use stdClass;
use Wikimedia\JsonCodec\JsonCodec;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Rdbms\Database;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IDBAccessObject;

class EventStore implements IEventStore, IEventLookup {
	private const EVENT_STATUS_MAP = [
		EventRegistration::STATUS_OPEN => 1,
		EventRegistration::STATUS_CLOSED => 2,
	];

	public const PARTICIPATION_OPTION_MAP = [
		EventRegistration::PARTICIPATION_OPTION_IN_PERSON => 1,
		EventRegistration::PARTICIPATION_OPTION_ONLINE => 2,
	];

	private CampaignsDatabaseHelper $dbHelper;
	private CampaignsPageFactory $campaignsPageFactory;
	private AddressStore $addressStore;
	private TrackingToolUpdater $trackingToolUpdater;
	private EventQuestionsStore $eventQuestionsStore;
	private EventWikisStore $eventWikisStore;
	private EventTopicsStore $eventTopicsStore;
	private WANObjectCache $wanCache;
	private JsonCodec $jsonCodec;

	private const PAGE_EVENT_CACHE_TTL = 1 * WANObjectCache::TTL_WEEK;

	/**
	 * @var array<int,ExistingEventRegistration> Cache of stored registrations, keyed by ID.
	 */
	private array $cache = [];

	public function __construct(
		CampaignsDatabaseHelper $dbHelper,
		CampaignsPageFactory $campaignsPageFactory,
		AddressStore $addressStore,
		TrackingToolUpdater $trackingToolUpdater,
		EventQuestionsStore $eventQuestionsStore,
		EventWikisStore $eventWikisStore,
		EventTopicsStore $eventTopicsStore,
		WANObjectCache $wanCache,
		JsonCodec $jsonCodec,
		private readonly bool $contributionTrackingEnabled
	) {
		$this->dbHelper = $dbHelper;
		$this->campaignsPageFactory = $campaignsPageFactory;
		$this->addressStore = $addressStore;
		$this->trackingToolUpdater = $trackingToolUpdater;
		$this->eventQuestionsStore = $eventQuestionsStore;
		$this->eventWikisStore = $eventWikisStore;
		$this->eventTopicsStore = $eventTopicsStore;
		$this->wanCache = $wanCache;
		$this->jsonCodec = $jsonCodec;
	}

	/**
	 * @inheritDoc
	 */
	public function getEventByID( int $eventID ): ExistingEventRegistration {
		if ( isset( $this->cache[$eventID] ) ) {
			return $this->cache[$eventID];
		}

		$dbr = $this->dbHelper->getDBConnection( DB_REPLICA );
		$eventRow = $dbr->newSelectQueryBuilder()
			->select( '*' )
			->from( 'campaign_events' )
			->where( [ 'event_id' => $eventID ] )
			->caller( __METHOD__ )
			->fetchRow();
		if ( !$eventRow ) {
			throw new EventNotFoundException( "Event $eventID not found" );
		}

		$this->cache[$eventID] = $this->newEventFromDBRow(
			$eventRow,
			$this->addressStore->getEventAddress( $dbr, $eventID ),
			$this->getEventTrackingToolRow( $dbr, $eventID ),
			$this->eventWikisStore->getEventWikis( $eventID ),
			$this->eventTopicsStore->getEventTopics( $eventID ),
			$this->eventQuestionsStore->getEventQuestions( $eventID )
		);
		return $this->cache[$eventID];
	}

	/**
	 * @inheritDoc
	 */
	public function getEventByPage(
		MWPageProxy $page,
		int $readFlags = IDBAccessObject::READ_NORMAL
	): ExistingEventRegistration {
		if ( ( $readFlags & IDBAccessObject::READ_LATEST ) === IDBAccessObject::READ_LATEST ) {
			return $this->loadEventFromDB( $page, $readFlags );
		}

		$cachedEventEncoded = $this->wanCache->getWithSetCallback(
			$this->makePageEventCacheKey( $page ),
			self::PAGE_EVENT_CACHE_TTL,
			/**
			 * @param ExistingEventRegistration|false|null $oldValue
			 * @param array<string,mixed> &$setOpts
			 */
			function ( ExistingEventRegistration|bool|null $oldValue, int &$ttl, array &$setOpts )
				use ( $page, $readFlags ): ?string
			{
				$db = $this->dbHelper->getDBConnection( DB_REPLICA );

				$setOpts += Database::getCacheSetOptions( $db );

				try {
					$event = $this->loadEventFromDB( $page, $readFlags );
					$lastMod = max( $event->getLastEditTimestamp(), $event->getDeletionTimestamp() );
					$ttl = $this->wanCache->adaptiveTTL( $lastMod, self::PAGE_EVENT_CACHE_TTL );
					return $this->jsonCodec->toJsonString( $event );
				} catch ( EventNotFoundException ) {
					return null;
				}
			},
			[ 'version' => 7 ]
		);

		if ( $cachedEventEncoded === null ) {
			throw new EventNotFoundException(
				"No event found for the given page (ns={$page->getNamespace()}, " .
				"dbkey={$page->getDBkey()}, wiki={$page->getWikiId()})"
			);
		}

		return $this->jsonCodec->newFromJsonString( $cachedEventEncoded );
	}

	/**
	 * Load the event associated with the given page from the database.
	 * @throws EventNotFoundException If no event is associated with this page
	 */
	private function loadEventFromDB( MWPageProxy $page, int $readFlags ): ExistingEventRegistration {
		if ( ( $readFlags & IDBAccessObject::READ_LATEST ) === IDBAccessObject::READ_LATEST ) {
			$db = $this->dbHelper->getDBConnection( DB_PRIMARY );
		} else {
			$db = $this->dbHelper->getDBConnection( DB_REPLICA );
		}

		$eventRow = $db->newSelectQueryBuilder()
			->select( '*' )
			->from( 'campaign_events' )
			->where( [
				'event_page_namespace' => $page->getNamespace(),
				'event_page_title' => $page->getDBkey(),
				'event_page_wiki' => Utils::getWikiIDString( $page->getWikiId() ),
			] )
			->caller( __METHOD__ )
			->recency( $readFlags )
			->fetchRow();
		if ( !$eventRow ) {
			throw new EventNotFoundException(
				"No event found for the given page (ns={$page->getNamespace()}, " .
				"dbkey={$page->getDBkey()}, wiki={$page->getWikiId()})"
			);
		}

		$eventID = (int)$eventRow->event_id;
		return $this->newEventFromDBRow(
			$eventRow,
			$this->addressStore->getEventAddress( $db, $eventID ),
			$this->getEventTrackingToolRow( $db, $eventID ),
			$this->eventWikisStore->getEventWikis( $eventID ),
			$this->eventTopicsStore->getEventTopics( $eventID ),
			$this->eventQuestionsStore->getEventQuestions( $eventID )
		);
	}

	private function makePageEventCacheKey( MWPageProxy $page ): string {
		return $this->wanCache->makeKey(
			'CampaignEvents-EventStore',
			$page->getNamespace(),
			$page->getDBkey(),
			$page->getWikiId()
		);
	}

	private function getEventTrackingToolRow( IDatabase $db, int $eventID ): ?stdClass {
		$trackingToolsRows = $db->newSelectQueryBuilder()
			->select( '*' )
			->from( 'ce_tracking_tools' )
			->where( [ 'cett_event' => $eventID ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		// TODO Add support for multiple tracking tools per event
		if ( count( $trackingToolsRows ) > 1 ) {
			throw new RuntimeException( 'Events should have only one tracking tool.' );
		}

		$trackingToolRow = null;
		foreach ( $trackingToolsRows as $row ) {
			$trackingToolRow = $row;
			break;
		}
		return $trackingToolRow;
	}

	/**
	 * @inheritDoc
	 */
	public function getEventsByOrganizer( int $organizerID, int $limit ): array {
		$dbr = $this->dbHelper->getDBConnection( DB_REPLICA );

		$eventRows = $dbr->newSelectQueryBuilder()
			->select( '*' )
			->from( 'campaign_events' )
			->join( 'ce_organizers', null, [
				'event_id=ceo_event_id',
				'ceo_deleted_at' => null
			] )
			->where( [ 'ceo_user_id' => $organizerID ] )
			->orderBy( 'event_id' )
			->limit( $limit )
			->caller( __METHOD__ )
			->fetchResultSet();

		return $this->newEventsFromDBRows( $dbr, $eventRows );
	}

	/**
	 * @inheritDoc
	 */
	public function getEventsByParticipant( int $participantID, int $limit ): array {
		$dbr = $this->dbHelper->getDBConnection( DB_REPLICA );

		$eventRows = $dbr->newSelectQueryBuilder()
			->select( '*' )
			->from( 'campaign_events' )
			->join( 'ce_participants', null, [
				'event_id=cep_event_id',
				// TODO Perhaps consider more granular permission check here.
				'cep_private' => false,
			] )
			->where( [
				'cep_user_id' => $participantID,
				'cep_unregistered_at' => null,
			] )
			->orderBy( 'event_id' )
			->limit( $limit )
			->caller( __METHOD__ )
			->fetchResultSet();

		return $this->newEventsFromDBRows( $dbr, $eventRows );
	}

	/**
	 * @inheritDoc
	 */
	public function getEventsForContributionAssociationByParticipant( int $participantID, int $limit ): array {
		$dbr = $this->dbHelper->getDBConnection( DB_REPLICA );
		$currentTime = $dbr->timestamp();

		$eventRows = $dbr->newSelectQueryBuilder()
			->select( '*' )
			->from( 'campaign_events' )
			->join( 'ce_participants', null, [
				'event_id=cep_event_id',
				'cep_user_id' => $participantID,
				'cep_unregistered_at' => null,
			] )
			->join( 'ce_event_wikis', null, 'event_id=ceew_event_id' )
			->where( [
				'event_deleted_at' => null,
				'event_track_contributions' => true,
				'ceew_wiki' => [ EventWikisStore::ALL_WIKIS_DB_VALUE, WikiMap::getCurrentWikiId() ],
				$dbr->expr( 'event_start_utc', '<=', $currentTime ),
				$dbr->expr( 'event_end_utc', '>=', $currentTime )
			] )
			->orderBy( 'event_id' )
			->limit( $limit )
			->caller( __METHOD__ )
			->fetchResultSet();

		return $this->newEventsFromDBRows( $dbr, $eventRows );
	}

	/**
	 * @param IDatabase $db
	 * @param iterable<stdClass> $eventRows
	 * @return ExistingEventRegistration[]
	 */
	public function newEventsFromDBRows( IDatabase $db, iterable $eventRows ): array {
		$eventIDs = [];
		foreach ( $eventRows as $eventRow ) {
			if ( !property_exists( $eventRow, 'event_id' ) ) {
				throw new InvalidArgumentException( "Got row without event ID: " . var_export( $eventRow, true ) );
			}
			$eventIDs[] = (int)$eventRow->event_id;
		}

		if ( !$eventIDs ) {
			return [];
		}

		$addressRowsByEvent = $this->addressStore->getAddressesForEvents( $db, $eventIDs );
		$trackingToolRowsByEvent = $this->getTrackingToolsRowsForEvents( $db, $eventIDs );
		$wikisByEvent = $this->eventWikisStore->getEventWikisMulti( $eventIDs );
		$topicsByEvent = $this->eventTopicsStore->getEventTopicsMulti( $eventIDs );
		$questionsByEvent = $this->eventQuestionsStore->getEventQuestionsMulti( $eventIDs );

		$events = [];
		foreach ( $eventRows as $row ) {
			$curEventID = (int)$row->event_id;
			$events[$curEventID] = $this->newEventFromDBRow(
				$row,
				$addressRowsByEvent[$curEventID] ?? null,
				$trackingToolRowsByEvent[$curEventID] ?? null,
				$wikisByEvent[$curEventID],
				$topicsByEvent[$curEventID],
				$questionsByEvent[$curEventID]
			);
		}
		return $events;
	}

	/**
	 * @param IDatabase $db
	 * @param int[] $eventIDs
	 * @return array<int,stdClass> Maps event IDs to the corresponding tracking tool row
	 */
	private function getTrackingToolsRowsForEvents(
		IDatabase $db,
		array $eventIDs
	): array {
		$trackingToolsRows = $db->newSelectQueryBuilder()
			->select( '*' )
			->from( 'ce_tracking_tools' )
			->where( [ 'cett_event' => $eventIDs ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		$trackingToolsRowsByEvent = [];
		foreach ( $trackingToolsRows as $trackingToolRow ) {
			$curEventID = (int)$trackingToolRow->cett_event;
			if ( isset( $trackingToolsRowsByEvent[$curEventID] ) ) {
				// TODO Add support for multiple tracking tools per event
				throw new RuntimeException( "Event $curEventID should have only one tracking tool." );
			}
			$trackingToolsRowsByEvent[$curEventID] = $trackingToolRow;
		}
		return $trackingToolsRowsByEvent;
	}

	/**
	 * @param stdClass $row
	 * @param Address|null $address
	 * @param stdClass|null $trackingToolRow
	 * @param string[]|true $wikis List of wiki IDs or {@see EventRegistration::ALL_WIKIS}
	 * @param string[] $topics
	 * @param int[] $questionIDs
	 */
	private function newEventFromDBRow(
		stdClass $row,
		?Address $address,
		?stdClass $trackingToolRow,
		array|bool $wikis,
		array $topics,
		array $questionIDs
	): ExistingEventRegistration {
		self::assertValidRow( $row );

		$eventPage = $this->campaignsPageFactory->newPageFromDB(
			(int)$row->event_page_namespace,
			$row->event_page_title,
			$row->event_page_prefixedtext,
			$row->event_page_wiki
		);
		$types = EventTypesRegistry::getEventTypesFromDBVal( $row->event_types );

		$participationOptions = self::getParticipationOptionsFromDBVal( $row->event_meeting_type );

		$tracksContributions = false;
		if ( $this->contributionTrackingEnabled ) {
			$tracksContributions = (bool)$row->event_track_contributions;
		}

		if ( $trackingToolRow ) {
			$trackingTools = [
				new TrackingToolAssociation(
					(int)$trackingToolRow->cett_tool_id,
					$trackingToolRow->cett_tool_event_id,
					TrackingToolUpdater::dbSyncStatusToConst( (int)$trackingToolRow->cett_sync_status ),
					wfTimestampOrNull( TS_UNIX, $trackingToolRow->cett_last_sync )
				)
			];
		} else {
			$trackingTools = [];
		}
		return new ExistingEventRegistration(
			(int)$row->event_id,
			$row->event_name,
			$eventPage,
			self::getEventStatusFromDBVal( $row->event_status ),
			new DateTimeZone( $row->event_timezone ),
			wfTimestamp( TS_MW, $row->event_start_local ),
			wfTimestamp( TS_MW, $row->event_end_local ),
			$types,
			$wikis,
			$topics,
			$participationOptions,
			$row->event_meeting_url !== '' ? $row->event_meeting_url : null,
			$address,
			$tracksContributions,
			$trackingTools,
			$row->event_chat_url !== '' ? $row->event_chat_url : null,
			(bool)$row->event_is_test_event,
			$questionIDs,
			wfTimestamp( TS_UNIX, $row->event_created_at ),
			wfTimestamp( TS_UNIX, $row->event_last_edit ),
			wfTimestampOrNull( TS_UNIX, $row->event_deleted_at )
		);
	}

	private function assertValidRow( stdClass $row ): void {
		$requiredProperties = [
			'event_id',
			'event_name',
			'event_page_namespace',
			'event_page_title',
			'event_page_wiki',
			'event_page_prefixedtext',
			'event_chat_url',
			'event_status',
			'event_timezone',
			'event_start_local',
			// event_start_utc not required
			'event_end_local',
			// event_end_utc not required
			'event_types',
			// event_track_contributions conditionally checked below
			'event_meeting_type',
			'event_meeting_url',
			'event_created_at',
			'event_last_edit',
			'event_deleted_at',
			'event_is_test_event',
		];
		if ( $this->contributionTrackingEnabled ) {
			$requiredProperties[] = 'event_track_contributions';
		}
		foreach ( $requiredProperties as $property ) {
			if ( !property_exists( $row, $property ) ) {
				throw new InvalidArgumentException(
					"Event row lacks required prop '$property': " . var_export( $row, true )
				);
			}
		}
	}

	/**
	 * @inheritDoc
	 */
	public function saveRegistration( EventRegistration $event ): int {
		$dbw = $this->dbHelper->getDBConnection( DB_PRIMARY );
		$curDBTimestamp = $dbw->timestamp();

		$curCreationTS = $event->getCreationTimestamp();
		$curDeletionTS = $event->getDeletionTimestamp();
		// The local timestamps are already guaranteed to be in TS_MW format and the EventRegistration constructor
		// enforces that, but convert them again as an extra safeguard to avoid any chance of storing garbage.
		$localStartDB = wfTimestamp( TS_MW, $event->getStartLocalTimestamp() );
		$localEndDB = wfTimestamp( TS_MW, $event->getEndLocalTimestamp() );
		$newRow = [
			'event_name' => $event->getName(),
			'event_page_namespace' => $event->getPage()->getNamespace(),
			'event_page_title' => $event->getPage()->getDBkey(),
			'event_page_prefixedtext' => $event->getPage()->getPrefixedText(),
			'event_page_wiki' => Utils::getWikiIDString( $event->getPage()->getWikiId() ),
			'event_chat_url' => $event->getChatURL() ?? '',
			'event_status' => self::EVENT_STATUS_MAP[$event->getStatus()],
			'event_timezone' => $event->getTimezone()->getName(),
			'event_start_local' => $localStartDB,
			'event_start_utc' => $dbw->timestamp( $event->getStartUTCTimestamp() ),
			'event_end_local' => $localEndDB,
			'event_end_utc' => $dbw->timestamp( $event->getEndUTCTimestamp() ),
			'event_types' => EventTypesRegistry::eventTypesToDBVal( $event->getTypes() ),
			'event_meeting_type' => self::participationOptionsToDBVal( $event->getParticipationOptions() ),
			'event_meeting_url' => $event->getMeetingURL() ?? '',
			'event_created_at' => $curCreationTS ? $dbw->timestamp( $curCreationTS ) : $curDBTimestamp,
			'event_last_edit' => $curDBTimestamp,
			'event_deleted_at' => $curDeletionTS ? $dbw->timestamp( $curDeletionTS ) : null,
			'event_is_test_event' => $event->getIsTestEvent()
		];
		if ( $this->contributionTrackingEnabled ) {
			$newRow['event_track_contributions'] = $event->hasContributionTracking();
		}

		$eventID = $event->getID();
		$dbw->startAtomic( __METHOD__ );
		if ( $eventID === null ) {
			$dbw->newInsertQueryBuilder()
				->insertInto( 'campaign_events' )
				->row( $newRow )
				->caller( __METHOD__ )
				->execute();
			$eventID = $dbw->insertId();
		} else {
			$dbw->newUpdateQueryBuilder()
				->update( 'campaign_events' )
				->set( $newRow )
				->where( [ 'event_id' => $eventID ] )
				->caller( __METHOD__ )
				->execute();
		}

		$this->addressStore->updateAddresses( $event->getAddress(), $eventID );
		$this->trackingToolUpdater->replaceEventTools( $eventID, $event->getTrackingTools(), $dbw );
		$this->eventQuestionsStore->replaceEventQuestions( $eventID, $event->getParticipantQuestions() );
		$this->eventWikisStore->addOrUpdateEventWikis( $eventID, $event->getWikis() );
		$this->eventTopicsStore->addOrUpdateEventTopics( $eventID, $event->getTopics() );

		$dbw->onTransactionCommitOrIdle(
			fn (): bool => $this->wanCache->delete( $this->makePageEventCacheKey( $event->getPage() ) ),
			__METHOD__
		);

		$dbw->endAtomic( __METHOD__ );

		unset( $this->cache[$eventID] );

		return $eventID;
	}

	/**
	 * @inheritDoc
	 */
	public function deleteRegistration( ExistingEventRegistration $registration ): bool {
		$dbw = $this->dbHelper->getDBConnection( DB_PRIMARY );
		$dbw->newUpdateQueryBuilder()
			->update( 'campaign_events' )
			->set( [ 'event_deleted_at' => $dbw->timestamp() ] )
			->where( [
				'event_id' => $registration->getID(),
				'event_deleted_at' => null
			] )
			->caller( __METHOD__ )
			->execute();
		unset( $this->cache[$registration->getID()] );

		$dbw->onTransactionCommitOrIdle(
			fn (): bool => $this->wanCache->delete( $this->makePageEventCacheKey( $registration->getPage() ) ),
			__METHOD__
		);

		return $dbw->affectedRows() > 0;
	}

	/**
	 * Converts participation options as stored in the DB into a combination of the
	 * EventRegistration::PARTICIPATION_OPTION_* constants.
	 */
	public static function getParticipationOptionsFromDBVal( string $dbParticipationOptions ): int {
		$ret = 0;
		$dbParticipationOptionsNum = (int)$dbParticipationOptions;
		foreach ( self::PARTICIPATION_OPTION_MAP as $eventVal => $dbVal ) {
			if ( $dbParticipationOptionsNum & $dbVal ) {
				$ret |= $eventVal;
			}
		}
		return $ret;
	}

	/**
	 * Converts an EventRegistration::PARTICIPATION_OPTION_* constant to the corresponding value used in the database.
	 */
	public static function participationOptionsToDBVal( int $participationOptions ): int {
		$dbParticipationOptions = 0;
		foreach ( self::PARTICIPATION_OPTION_MAP as $eventVal => $dbVal ) {
			if ( $participationOptions & $eventVal ) {
				$dbParticipationOptions |= $dbVal;
			}
		}
		return $dbParticipationOptions;
	}

	/**
	 * Converts an EventRegistration::STATUS_* constant into the respective DB value.
	 */
	public static function getEventStatusDBVal( string $eventStatus ): int {
		if ( isset( self::EVENT_STATUS_MAP[$eventStatus] ) ) {
			return self::EVENT_STATUS_MAP[$eventStatus];
		}
		throw new LogicException( "Unknown status $eventStatus" );
	}

	/**
	 * Converts an event status as stored in the database to an EventRegistration::STATUS_* constant
	 */
	public static function getEventStatusFromDBVal( string $eventStatus ): string {
		$val = array_search( (int)$eventStatus, self::EVENT_STATUS_MAP, true );
		if ( $val === false ) {
			throw new InvalidArgumentException( "Unknown event status: $eventStatus" );
		}
		return $val;
	}
}
