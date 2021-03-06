<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Event\Store;

use InvalidArgumentException;
use LogicException;
use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFactory;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsPage;
use MediaWiki\Extension\CampaignEvents\Utils;
use StatusValue;
use stdClass;

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

	/**
	 * @param CampaignsDatabaseHelper $dbHelper
	 * @param CampaignsPageFactory $campaignsPageFactory
	 */
	public function __construct(
		CampaignsDatabaseHelper $dbHelper,
		CampaignsPageFactory $campaignsPageFactory
	) {
		$this->dbHelper = $dbHelper;
		$this->campaignsPageFactory = $campaignsPageFactory;
	}

	/**
	 * @inheritDoc
	 */
	public function getEventByID( int $eventID ): ExistingEventRegistration {
		$eventRow = $this->dbHelper->getDBConnection( DB_REPLICA )->selectRow(
			'campaign_events',
			'*',
			[ 'event_id' => $eventID ]
		);
		if ( !$eventRow ) {
			throw new EventNotFoundException( "Event $eventID not found" );
		}
		return $this->newEventFromDBRow( $eventRow );
	}

	/**
	 * @inheritDoc
	 */
	public function getEventByPage( ICampaignsPage $page ): ExistingEventRegistration {
		$eventRow = $this->dbHelper->getDBConnection( DB_REPLICA )->selectRow(
			'campaign_events',
			'*',
			[
				'event_page_namespace' => $page->getNamespace(),
				'event_page_title' => $page->getDBkey(),
				'event_page_wiki' => Utils::getWikiIDString( $page->getWikiId() ),
			]
		);
		if ( !$eventRow ) {
			throw new EventNotFoundException(
				"No event found for the given page (ns={$page->getNamespace()}, " .
					"dbkey={$page->getDBkey()}, wiki={$page->getWikiId()})"
			);
		}
		return $this->newEventFromDBRow( $eventRow );
	}

	/**
	 * @inheritDoc
	 */
	public function getEventsByOrganizer( int $organizerID, int $limit = null ): array {
		$events = [];

		$eventsRow = $this->dbHelper->getDBConnection( DB_REPLICA )->select(
			[ 'campaign_events', 'ce_organizers' ],
			'*',
			[ 'ceo_user_id' => $organizerID ],
			$limit !== null ? [ 'LIMIT' => $limit ] : [],
			[
				'ce_organizers' => [ 'INNER JOIN', [ 'event_id=ceo_event_id' ] ]
			]
		);

		foreach ( $eventsRow as $row ) {
			$events[] = $this->newEventFromDBRow( $row );
		}

		return $events;
	}

	/**
	 * @inheritDoc
	 */
	public function getEventsByParticipant( int $participantID, int $limit = null ): array {
		$events = [];

		$eventsRow = $this->dbHelper->getDBConnection( DB_REPLICA )->select(
			[ 'campaign_events', 'ce_participants' ],
			'*',
			[
				'cep_user_id' => $participantID,
				'cep_unregistered_at' => null,
			],
			$limit !== null ? [ 'LIMIT' => $limit ] : [],
			[
				'ce_participants' => [ 'INNER JOIN', [ 'event_id=cep_event_id' ] ]
			]
		);

		foreach ( $eventsRow as $row ) {
			$events[] = $this->newEventFromDBRow( $row );
		}

		return $events;
	}

	/**
	 * @param stdClass $row
	 * @return ExistingEventRegistration
	 */
	private function newEventFromDBRow( stdClass $row ): ExistingEventRegistration {
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
		return new ExistingEventRegistration(
			(int)$row->event_id,
			$row->event_name,
			$eventPage,
			$row->event_chat_url !== '' ? $row->event_chat_url : null,
			$row->event_tracking_tool !== '' ? $row->event_tracking_tool : null,
			$row->event_tracking_url !== '' ? $row->event_tracking_url : null,
			array_search( (int)$row->event_status, self::EVENT_STATUS_MAP, true ),
			wfTimestamp( TS_UNIX, $row->event_start ),
			wfTimestamp( TS_UNIX, $row->event_end ),
			array_search( $row->event_type, self::EVENT_TYPE_MAP, true ),
			$meetingType,
			$row->event_meeting_url !== '' ? $row->event_meeting_url : null,
			$row->event_meeting_country !== '' ? $row->event_meeting_country : null,
			$row->event_meeting_address !== '' ? $row->event_meeting_address : null,
			wfTimestamp( TS_UNIX, $row->event_created_at ),
			wfTimestamp( TS_UNIX, $row->event_last_edit ),
			wfTimestampOrNull( TS_UNIX, $row->event_deleted_at )
		);
	}

	/**
	 * @inheritDoc
	 */
	public function saveRegistration( EventRegistration $event ): StatusValue {
		$dbw = $this->dbHelper->getDBConnection( DB_PRIMARY );
		$curDBTimestamp = $dbw->timestamp();
		$meetingType = 0;
		foreach ( self::EVENT_MEETING_TYPE_MAP as $eventVal => $dbVal ) {
			if ( $event->getMeetingType() & $eventVal ) {
				$meetingType |= $dbVal;
			}
		}
		$curCreationTS = $event->getCreationTimestamp();
		$curDeletionTS = $event->getDeletionTimestamp();
		$newRow = [
			'event_name' => $event->getName(),
			'event_page_namespace' => $event->getPage()->getNamespace(),
			'event_page_title' => $event->getPage()->getDBkey(),
			'event_page_prefixedtext' => $event->getPage()->getPrefixedText(),
			'event_page_wiki' => Utils::getWikiIDString( $event->getPage()->getWikiId() ),
			'event_chat_url' => $event->getChatURL() ?? '',
			'event_tracking_tool' => $event->getTrackingToolName() ?? '',
			'event_tracking_url' => $event->getTrackingToolURL() ?? '',
			'event_status' => self::EVENT_STATUS_MAP[$event->getStatus()],
			'event_start' => $dbw->timestamp( $event->getStartTimestamp() ),
			'event_end' => $dbw->timestamp( $event->getEndTimestamp() ),
			'event_type' => self::EVENT_TYPE_MAP[$event->getType()],
			'event_meeting_type' => $meetingType,
			'event_meeting_url' => $event->getMeetingURL() ?? '',
			'event_meeting_country' => $event->getMeetingCountry() ?? '',
			'event_meeting_address' => $event->getMeetingAddress() ?? '',
			'event_created_at' => $curCreationTS ? $dbw->timestamp( $curCreationTS ) : $curDBTimestamp,
			'event_last_edit' => $curDBTimestamp,
			'event_deleted_at' => $curDeletionTS ? $dbw->timestamp( $curDeletionTS ) : null,
		];
		$eventID = $event->getID();
		if ( $eventID === null ) {
			$dbw->insert( 'campaign_events', $newRow );
		} else {
			$dbw->update( 'campaign_events', $newRow, [ 'event_id' => $eventID ] );
		}
		return StatusValue::newGood( $event->getID() ?? $dbw->insertId() );
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
