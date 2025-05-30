<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Event;

use InvalidArgumentException;
use Wikimedia\Message\IMessageFormatterFactory;
use Wikimedia\Message\MessageValue;

class EventTypesRegistry {

	public const SERVICE_NAME = 'CampaignEventsEventTypesRegistry';

	public const EVENT_TYPE_MSG_MAP = [
		self::EVENT_TYPE_EDITING_EVENT      => 'campaignevents-eventtype-editing-event',
		self::EVENT_TYPE_MEDIA_UPLOAD_EVENT => 'campaignevents-eventtype-media-upload-event',
		self::EVENT_TYPE_BACKLOG_DRIVE      => 'campaignevents-eventtype-backlog-drive',
		self::EVENT_TYPE_CONTEST            => 'campaignevents-eventtype-contest',
		self::EVENT_TYPE_WORKSHOP           => 'campaignevents-eventtype-workshop',
		self::EVENT_TYPE_TRAINING           => 'campaignevents-eventtype-training',
		self::EVENT_TYPE_MEETUP             => 'campaignevents-eventtype-meetup',
		self::EVENT_TYPE_HACKATHON          => 'campaignevents-eventtype-hackathon',
		self::EVENT_TYPE_CONFERENCE         => 'campaignevents-eventtype-conference',
		self::EVENT_TYPE_OTHER              => 'campaignevents-eventtype-other',
	];

	public const DEBUG_NAMES_MAP = [
		self::EVENT_TYPE_EDITING_EVENT      => 'editing_event',
		self::EVENT_TYPE_MEDIA_UPLOAD_EVENT => 'media_upload_event',
		self::EVENT_TYPE_BACKLOG_DRIVE      => 'backlog_drive',
		self::EVENT_TYPE_CONTEST            => 'contest',
		self::EVENT_TYPE_WORKSHOP           => 'workshop',
		self::EVENT_TYPE_TRAINING           => 'training',
		self::EVENT_TYPE_MEETUP             => 'meetup',
		self::EVENT_TYPE_HACKATHON          => 'hackathon',
		self::EVENT_TYPE_CONFERENCE         => 'conference',
		self::EVENT_TYPE_OTHER              => 'other',
	];

	public const EVENT_GROUP_TYPE_MSG_MAP = [
		self::EVENT_GROUP_TYPE_CONTRIBUTIONS => 'campaignevents-eventtypegroup-contributions',
		self::EVENT_GROUP_TYPE_COMMUNITY => 'campaignevents-eventtypegroup-community',
	];

	public const DEBUG_EVENT_GROUP_TYPE_NAMES_MAP = [
		self::EVENT_GROUP_TYPE_CONTRIBUTIONS => 'contributions',
		self::EVENT_GROUP_TYPE_COMMUNITY => 'community',
	];

	// Event type constants
	public const EVENT_TYPE_EDITING_EVENT      = 'event_type_editing_event';
	public const EVENT_TYPE_MEDIA_UPLOAD_EVENT = 'event_type_media_upload_event';
	public const EVENT_TYPE_BACKLOG_DRIVE      = 'event_type_backlog_drive';
	public const EVENT_TYPE_CONTEST            = 'event_type_contest';
	public const EVENT_TYPE_WORKSHOP           = 'event_type_workshop';
	public const EVENT_TYPE_TRAINING           = 'event_type_training';
	public const EVENT_TYPE_MEETUP             = 'event_type_meetup';
	public const EVENT_TYPE_HACKATHON          = 'event_type_hackathon';
	public const EVENT_TYPE_CONFERENCE         = 'event_type_conference';
	public const EVENT_TYPE_OTHER              = 'event_type_other';

	public const EVENT_GROUP_TYPE_CONTRIBUTIONS = 'contributions';
	public const EVENT_GROUP_TYPE_COMMUNITY = 'community';

	// Event type groups
	public const EVENT_TYPE_GROUPS = [
		self::EVENT_GROUP_TYPE_CONTRIBUTIONS => [
			self::EVENT_TYPE_EDITING_EVENT,
			self::EVENT_TYPE_MEDIA_UPLOAD_EVENT,
			self::EVENT_TYPE_BACKLOG_DRIVE,
			self::EVENT_TYPE_CONTEST,
		],
		self::EVENT_GROUP_TYPE_COMMUNITY => [
			self::EVENT_TYPE_WORKSHOP,
			self::EVENT_TYPE_TRAINING,
			self::EVENT_TYPE_MEETUP,
			self::EVENT_TYPE_HACKATHON,
			self::EVENT_TYPE_CONFERENCE,
		],
	];

	// Bitwise map (OTHER is 0 and exclusive)
	public const EVENT_TYPES_MAP = [
		self::EVENT_TYPE_OTHER              => 0,
		self::EVENT_TYPE_EDITING_EVENT      => 1,
		self::EVENT_TYPE_MEDIA_UPLOAD_EVENT => 1 << 1,
		self::EVENT_TYPE_BACKLOG_DRIVE      => 1 << 2,
		self::EVENT_TYPE_CONTEST            => 1 << 3,
		self::EVENT_TYPE_WORKSHOP           => 1 << 4,
		self::EVENT_TYPE_TRAINING           => 1 << 5,
		self::EVENT_TYPE_MEETUP             => 1 << 6,
		self::EVENT_TYPE_HACKATHON          => 1 << 7,
		self::EVENT_TYPE_CONFERENCE         => 1 << 8,
	];

	private IMessageFormatterFactory $messageFormatterFactory;

	public function __construct( IMessageFormatterFactory $messageFormatterFactory ) {
		$this->messageFormatterFactory = $messageFormatterFactory;
	}

	public function getAllTypes(): array {
		return array_keys( self::EVENT_TYPES_MAP );
	}

	/**
	 * @param string $eventType One of the self::EVENT_TYPE_* constants
	 * @param string $languageCode
	 * @return string
	 */
	public function getLocalizedEventTypeName( string $eventType, string $languageCode ): string {
		if ( !isset( self::EVENT_TYPE_MSG_MAP[$eventType] ) ) {
			throw new InvalidArgumentException( "Invalid event type: $eventType" );
		}
		$formatter = $this->messageFormatterFactory->getTextFormatter( $languageCode );
		return $formatter->format( MessageValue::new( self::EVENT_TYPE_MSG_MAP[$eventType] ) );
	}

	/**
	 * @param string $eventGroupType One of the self::EVENT_GROUP_TYPE_* constants
	 * @param string $languageCode
	 * @return string
	 */
	public function getLocalizedGroupTypeName( string $eventGroupType, string $languageCode ): string {
		if ( !isset( self::EVENT_GROUP_TYPE_MSG_MAP[$eventGroupType] ) ) {
			throw new InvalidArgumentException( "Invalid event group type: $eventGroupType" );
		}
		$formatter = $this->messageFormatterFactory->getTextFormatter( $languageCode );
		return $formatter->format( MessageValue::new( self::EVENT_GROUP_TYPE_MSG_MAP[$eventGroupType] ) );
	}

	/**
	 * @param string $eventType One of the self::EVENT_TYPE_* constants
	 * @return string
	 */
	public function getEventTypeDebugName( string $eventType ): string {
		if ( !isset( self::DEBUG_NAMES_MAP[$eventType] ) ) {
			throw new InvalidArgumentException( "Invalid event type: $eventType" );
		}
		return self::DEBUG_NAMES_MAP[$eventType];
	}

	/**
	 * @param string $eventGroupType One of the self::EVENT_GROUP_TYPE_* constants
	 * @return string
	 */
	public function getEventTypeGroupsDebugName( string $eventGroupType ): string {
		if ( !isset( self::DEBUG_EVENT_GROUP_TYPE_NAMES_MAP[$eventGroupType] ) ) {
			throw new InvalidArgumentException( "Invalid event type group: $eventGroupType" );
		}
		return self::DEBUG_EVENT_GROUP_TYPE_NAMES_MAP[$eventGroupType];
	}

	/**
	 * @return array
	 */
	public function getAllOptionMessages() {
		$optionsMessages = [];
		foreach ( self::EVENT_TYPE_GROUPS as $groupType => $eventTypes ) {
			$groupLabelMsgKey = self::EVENT_GROUP_TYPE_MSG_MAP[$groupType];
			$optionsMessages[$groupLabelMsgKey] = [];

			foreach ( $eventTypes as $eventType ) {
				$eventTypeMsgKey = self::EVENT_TYPE_MSG_MAP[$eventType];
				$optionsMessages[$groupLabelMsgKey][$eventTypeMsgKey] = $eventType;
			}
		}
		$otherSectionLabelMsgKey = 'campaignevents-edit-field-eventtypes-other-section-header';
		$otherType = self::EVENT_TYPE_OTHER;
		$otherTypeMsgKey = self::EVENT_TYPE_MSG_MAP[$otherType];

		$optionsMessages[$otherSectionLabelMsgKey] = [
			$otherTypeMsgKey => $otherType,
		];

		return $optionsMessages;
	}
}
