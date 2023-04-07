<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Event\Store;

use DateTimeZone;
use DBAccessObjectUtils;
use InvalidArgumentException;
use LogicException;
use MediaWiki\Extension\CampaignEvents\Address\AddressStore;
use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFactory;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsDatabase;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsPage;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolAssociation;
use MediaWiki\Extension\CampaignEvents\Utils;
use RuntimeException;
use stdClass;

/**
 * @note Some pieces of code involving addresses may seem unnecessarily complex, but this is necessary because
 * we will add support for multiple addresses (T321811).
 */
class EventStore implements IEventStore, IEventLookup {
	private const EVENT_STATUS_MAP = [
		EventRegistration::STATUS_OPEN => 1,
		EventRegistration::STATUS_CLOSED => 2,
	];

	private const EVENT_TYPE_MAP = [
		EventRegistration::TYPE_GENERIC => 'generic',
	];

	private const EVENT_MEETING_TYPE_MAP = [
		EventRegistration::MEETING_TYPE_IN_PERSON => 1,
		EventRegistration::MEETING_TYPE_ONLINE => 2,
	];

	/** @var CampaignsDatabaseHelper */
	private $dbHelper;
	/** @var CampaignsPageFactory */
	private $campaignsPageFactory;
	/** @var AddressStore */
	private $addressStore;

	/**
	 * @var array<int,ExistingEventRegistration> Cache of stored registrations, keyed by ID.
	 */
	private array $cache = [];

	/**
	 * @param CampaignsDatabaseHelper $dbHelper
	 * @param CampaignsPageFactory $campaignsPageFactory
	 * @param AddressStore $addressStore
	 */
	public function __construct(
		CampaignsDatabaseHelper $dbHelper,
		CampaignsPageFactory $campaignsPageFactory,
		AddressStore $addressStore
	) {
		$this->dbHelper = $dbHelper;
		$this->campaignsPageFactory = $campaignsPageFactory;
		$this->addressStore = $addressStore;
	}

	/**
	 * @inheritDoc
	 */
	public function getEventByID( int $eventID ): ExistingEventRegistration {
		if ( isset( $this->cache[$eventID] ) ) {
			return $this->cache[$eventID];
		}

		$dbr = $this->dbHelper->getDBConnection( DB_REPLICA );
		$eventRow = $dbr->selectRow(
			'campaign_events',
			'*',
			[ 'event_id' => $eventID ]
		);
		if ( !$eventRow ) {
			throw new EventNotFoundException( "Event $eventID not found" );
		}

		$this->cache[$eventID] = $this->newEventFromDBRow( $eventRow, $this->getEventAddressRow( $dbr, $eventID ) );
		return $this->cache[$eventID];
	}

	/**
	 * @inheritDoc
	 */
	public function getEventByPage(
		ICampaignsPage $page,
		int $readFlags = self::READ_NORMAL
	): ExistingEventRegistration {
		[ $dbIndex, $dbOptions ] = DBAccessObjectUtils::getDBOptions( $readFlags );
		$db = $this->dbHelper->getDBConnection( $dbIndex );

		$eventRow = $db->selectRow(
			'campaign_events',
			'*',
			[
				'event_page_namespace' => $page->getNamespace(),
				'event_page_title' => $page->getDBkey(),
				'event_page_wiki' => Utils::getWikiIDString( $page->getWikiId() ),
			],
			$dbOptions
		);
		if ( !$eventRow ) {
			throw new EventNotFoundException(
				"No event found for the given page (ns={$page->getNamespace()}, " .
				"dbkey={$page->getDBkey()}, wiki={$page->getWikiId()})"
			);
		}

		$eventID = (int)$eventRow->event_id;
		return $this->newEventFromDBRow( $eventRow, $this->getEventAddressRow( $db, $eventID ) );
	}

	/**
	 * @param ICampaignsDatabase $db
	 * @param int $eventID
	 * @return stdClass|null
	 */
	private function getEventAddressRow( ICampaignsDatabase $db, int $eventID ): ?stdClass {
		$addressRows = $db->select(
			[ 'ce_address', 'ce_event_address' ],
			'*',
			[],
			[],
			[
				'ce_event_address' => [ 'INNER JOIN', [ 'ceea_address=cea_id', 'ceea_event' => $eventID ] ]
			]
		);

		// TODO Add support for multiple addresses per event
		if ( count( $addressRows ) > 1 ) {
			throw new RuntimeException( 'Events should have only one address.' );
		}

		$addressRow = null;
		foreach ( $addressRows as $row ) {
			$addressRow = $row;
			break;
		}
		return $addressRow;
	}

	/**
	 * @inheritDoc
	 */
	public function getEventsByOrganizer( int $organizerID, int $limit = null ): array {
		$dbr = $this->dbHelper->getDBConnection( DB_REPLICA );

		$opts = [ 'ORDER BY' => 'event_id' ];
		if ( $limit !== null ) {
			$opts['LIMIT'] = $limit;
		}
		$eventRows = $dbr->select(
			[ 'campaign_events', 'ce_organizers' ],
			'*',
			[ 'ceo_user_id' => $organizerID ],
			$opts,
			[
				'ce_organizers' => [
					'INNER JOIN',
					[
						'event_id=ceo_event_id',
						'ceo_deleted_at' => null
					]
				]
			]
		);

		return $this->newEventsFromDBRows( $dbr, $eventRows );
	}

	/**
	 * @inheritDoc
	 */
	public function getEventsByParticipant( int $participantID, int $limit = null ): array {
		$dbr = $this->dbHelper->getDBConnection( DB_REPLICA );

		$opts = [ 'ORDER BY' => 'event_id' ];
		if ( $limit !== null ) {
			$opts['LIMIT'] = $limit;
		}
		$eventRows = $dbr->select(
			[ 'campaign_events', 'ce_participants' ],
			'*',
			[
				'cep_user_id' => $participantID,
				'cep_unregistered_at' => null,
			],
			$opts,
			[
				'ce_participants' => [
					'INNER JOIN',
					[
						'event_id=cep_event_id',
						// TODO Perhaps consider more granular permission check here.
						'cep_private' => false,
					]
				]
			]
		);

		return $this->newEventsFromDBRows( $dbr, $eventRows );
	}

	/**
	 * @param ICampaignsDatabase $db
	 * @param iterable<stdClass> $eventRows
	 * @return ExistingEventRegistration[]
	 */
	private function newEventsFromDBRows( ICampaignsDatabase $db, iterable $eventRows ): array {
		$eventIDs = [];
		foreach ( $eventRows as $eventRow ) {
			$eventIDs[] = (int)$eventRow->event_id;
		}

		$addressRowsByEvent = $this->getAddressRowsForEvents( $db, $eventIDs );

		$events = [];
		foreach ( $eventRows as $row ) {
			$curEventID = (int)$row->event_id;
			$events[] = $this->newEventFromDBRow( $row, $addressRowsByEvent[$curEventID] ?? null );
		}
		return $events;
	}

	/**
	 * @param ICampaignsDatabase $db
	 * @param int[] $eventIDs
	 * @return array<int,stdClass> Maps event IDs to the corresponding address row
	 */
	private function getAddressRowsForEvents( ICampaignsDatabase $db, array $eventIDs ): array {
		$addressRows = $db->select(
			[ 'ce_address', 'ce_event_address' ],
			'*',
			[],
			[],
			[
				'ce_event_address' => [ 'INNER JOIN', [ 'ceea_address=cea_id', 'ceea_event' => $eventIDs ] ]
			]
		);

		$addressRowsByEvent = [];
		foreach ( $addressRows as $addressRow ) {
			$curEventID = (int)$addressRow->ceea_event;
			if ( isset( $addressRowsByEvent[$curEventID] ) ) {
				// TODO Add support for multiple addresses per event
				throw new RuntimeException( "Event $curEventID should have only one address." );
			}
			$addressRowsByEvent[$curEventID] = $addressRow;
		}
		return $addressRowsByEvent;
	}

	/**
	 * @param stdClass $row
	 * @param stdClass|null $addressRow
	 * @return ExistingEventRegistration
	 */
	private function newEventFromDBRow( stdClass $row, ?stdClass $addressRow ): ExistingEventRegistration {
		$eventPage = $this->campaignsPageFactory->newPageFromDB(
			(int)$row->event_page_namespace,
			$row->event_page_title,
			$row->event_page_prefixedtext,
			$row->event_page_wiki
		);
		$dbMeetingType = (int)$row->event_meeting_type;
		$meetingType = 0;
		foreach ( self::EVENT_MEETING_TYPE_MAP as $eventVal => $dbVal ) {
			if ( $dbMeetingType & $dbVal ) {
				$meetingType |= $eventVal;
			}
		}

		$address = null;
		$country = null;
		if ( $addressRow ) {
			// TODO this is ugly and should be removed as soon as we remove the country on the front end
			$address = explode( " \n ", $addressRow->cea_full_address );
			array_pop( $address );
			$address = implode( " \n ", $address );
			$country = $addressRow->cea_country;
		}

		if ( $row->event_tracking_tool_id !== null ) {
			$trackingTools = [
				new TrackingToolAssociation(
					(int)$row->event_tracking_tool_id,
					$row->event_tracking_tool_event_id,
					TrackingToolAssociation::SYNC_STATUS_UNKNOWN,
					null
				)
			];
		} else {
			$trackingTools = [];
		}

		return new ExistingEventRegistration(
			(int)$row->event_id,
			$row->event_name,
			$eventPage,
			$row->event_chat_url !== '' ? $row->event_chat_url : null,
			$trackingTools,
			array_search( (int)$row->event_status, self::EVENT_STATUS_MAP, true ),
			new DateTimeZone( $row->event_timezone ),
			wfTimestamp( TS_MW, $row->event_start_local ),
			wfTimestamp( TS_MW, $row->event_end_local ),
			array_search( $row->event_type, self::EVENT_TYPE_MAP, true ),
			$meetingType,
			$row->event_meeting_url !== '' ? $row->event_meeting_url : null,
			$country,
			$address,
			wfTimestamp( TS_UNIX, $row->event_created_at ),
			wfTimestamp( TS_UNIX, $row->event_last_edit ),
			wfTimestampOrNull( TS_UNIX, $row->event_deleted_at )
		);
	}

	/**
	 * @inheritDoc
	 */
	public function saveRegistration( EventRegistration $event ): int {
		$dbw = $this->dbHelper->getDBConnection( DB_PRIMARY );
		$curDBTimestamp = $dbw->timestamp();

		$meetingType = 0;
		foreach ( self::EVENT_MEETING_TYPE_MAP as $eventVal => $dbVal ) {
			if ( $event->getMeetingType() & $eventVal ) {
				$meetingType |= $dbVal;
			}
		}
		$trackingTools = $event->getTrackingTools();
		if ( count( $trackingTools ) === 1 ) {
			$toolAssociation = $trackingTools[0];
			$trackingToolDBID = $toolAssociation->getToolID();
			$trackingToolEventID = $toolAssociation->getToolEventID();
		} elseif ( !$trackingTools ) {
			$trackingToolDBID = $trackingToolEventID = null;
		} else {
			// Not implemented.
			throw new LogicException( "There should only be at most 1 tracking tool for now" );
		}
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
			'event_tracking_tool_id' => $trackingToolDBID,
			'event_tracking_tool_event_id' => $trackingToolEventID,
			'event_status' => self::EVENT_STATUS_MAP[$event->getStatus()],
			'event_timezone' => $event->getTimezone()->getName(),
			'event_start_local' => $localStartDB,
			'event_start_utc' => $dbw->timestamp( $event->getStartUTCTimestamp() ),
			'event_end_local' => $localEndDB,
			'event_end_utc' => $dbw->timestamp( $event->getEndUTCTimestamp() ),
			'event_type' => self::EVENT_TYPE_MAP[$event->getType()],
			'event_meeting_type' => $meetingType,
			'event_meeting_url' => $event->getMeetingURL() ?? '',
			'event_created_at' => $curCreationTS ? $dbw->timestamp( $curCreationTS ) : $curDBTimestamp,
			'event_last_edit' => $curDBTimestamp,
			'event_deleted_at' => $curDeletionTS ? $dbw->timestamp( $curDeletionTS ) : null,
		];

		$eventID = $event->getID();
		$dbw->startAtomic();
		if ( $eventID === null ) {
			$dbw->insert( 'campaign_events', $newRow );
			$eventID = $dbw->insertId();
		} else {
			$dbw->update( 'campaign_events', $newRow, [ 'event_id' => $eventID ] );
		}

		$this->updateStoredAddresses( $dbw, $event->getMeetingAddress(), $event->getMeetingCountry(), $eventID );

		$dbw->endAtomic();

		unset( $this->cache[$eventID] );

		return $eventID;
	}

	/**
	 * @param ICampaignsDatabase $dbw
	 * @param string|null $meetingAddress
	 * @param string|null $meetingCountry
	 * @param int $eventID
	 * @return void
	 */
	private function updateStoredAddresses(
		ICampaignsDatabase $dbw,
		?string $meetingAddress,
		?string $meetingCountry,
		int $eventID
	): void {
		$where = [ 'ceea_event' => $eventID ];
		if ( $meetingAddress || $meetingCountry ) {
			$meetingAddress .= " \n " . $meetingCountry;
			$where[] = 'cea_full_address NOT IN (' . $dbw->makeCommaList( [ $meetingAddress ] ) . ' ) ';
		}

		$dbw->deleteJoin(
			'ce_event_address',
			'ce_address',
			'ceea_address',
			'cea_id',
			$where
		);

		if ( $meetingAddress ) {
			$addressID = $this->addressStore->acquireAddressID( $meetingAddress, $meetingCountry );
			$dbw->insert(
				'ce_event_address',
				[
					'ceea_event' => $eventID,
					'ceea_address' => $addressID
				],
				[ 'IGNORE' ]
			);
		}
	}

	/**
	 * @inheritDoc
	 */
	public function deleteRegistration( ExistingEventRegistration $registration ): bool {
		$dbw = $this->dbHelper->getDBConnection( DB_PRIMARY );
		$dbw->update(
			'campaign_events',
			[ 'event_deleted_at' => $dbw->timestamp() ],
			[
				'event_id' => $registration->getID(),
				'event_deleted_at' => null
			]
		);
		unset( $this->cache[$registration->getID()] );
		return $dbw->affectedRows() > 0;
	}

	/**
	 * Converts a meeting type as stored in the DB into a combination of the EventRegistration::MEETING_TYPE_* constants
	 * @param string $dbMeetingType
	 * @return int
	 */
	public static function getMeetingTypeFromDBVal( string $dbMeetingType ): int {
		$ret = 0;
		$dbMeetingTypeNum = (int)$dbMeetingType;
		foreach ( self::EVENT_MEETING_TYPE_MAP as $eventVal => $dbVal ) {
			if ( $dbMeetingTypeNum & $dbVal ) {
				$ret |= $eventVal;
			}
		}
		return $ret;
	}

	/**
	 * Converts an EventRegistration::STATUS_* constant into the respective DB value.
	 * @param string $eventStatus
	 * @return int
	 */
	public static function getEventStatusDBVal( string $eventStatus ): int {
		if ( isset( self::EVENT_STATUS_MAP[$eventStatus] ) ) {
			return self::EVENT_STATUS_MAP[$eventStatus];
		}
		throw new LogicException( "Unknown status $eventStatus" );
	}

	/**
	 * Converts an event status as stored in the database to an EventRegistration::STATUS_* constant
	 * @param string $eventStatus
	 * @return string
	 */
	public static function getEventStatusFromDBVal( string $eventStatus ): string {
		$val = array_search( (int)$eventStatus, self::EVENT_STATUS_MAP, true );
		if ( $val === false ) {
			throw new InvalidArgumentException( "Unknown event status: $eventStatus" );
		}
		return $val;
	}
}
