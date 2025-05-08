<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Event;

use InvalidArgumentException;
use Wikimedia\Message\IMessageFormatterFactory;
use Wikimedia\Message\MessageValue;

class EventTypesFormatter {
	public const SERVICE_NAME = 'CampaignEventsEventTypesFormatter';

	public const EVENT_TYPE_MSG_MAP = [
		EventTypesRegistry::EVENT_TYPE_EDITING_EVENT      => 'campaignevents-eventtype-editing-event',
		EventTypesRegistry::EVENT_TYPE_MEDIA_UPLOAD_EVENT => 'campaignevents-eventtype-media-upload-event',
		EventTypesRegistry::EVENT_TYPE_BACKLOG_DRIVE      => 'campaignevents-eventtype-backlog-drive',
		EventTypesRegistry::EVENT_TYPE_CONTEST            => 'campaignevents-eventtype-contest',
		EventTypesRegistry::EVENT_TYPE_WORKSHOP           => 'campaignevents-eventtype-workshop',
		EventTypesRegistry::EVENT_TYPE_TRAINING           => 'campaignevents-eventtype-training',
		EventTypesRegistry::EVENT_TYPE_MEETUP             => 'campaignevents-eventtype-meetup',
		EventTypesRegistry::EVENT_TYPE_HACKATHON          => 'campaignevents-eventtype-hackathon',
		EventTypesRegistry::EVENT_TYPE_CONFERENCE         => 'campaignevents-eventtype-conference',
		EventTypesRegistry::EVENT_TYPE_OTHER              => 'campaignevents-eventtype-other',
	];

	public const DEBUG_NAMES_MAP = [
		EventTypesRegistry::EVENT_TYPE_EDITING_EVENT      => 'editing_event',
		EventTypesRegistry::EVENT_TYPE_MEDIA_UPLOAD_EVENT => 'media_upload_event',
		EventTypesRegistry::EVENT_TYPE_BACKLOG_DRIVE      => 'backlog_drive',
		EventTypesRegistry::EVENT_TYPE_CONTEST            => 'contest',
		EventTypesRegistry::EVENT_TYPE_WORKSHOP           => 'workshop',
		EventTypesRegistry::EVENT_TYPE_TRAINING           => 'training',
		EventTypesRegistry::EVENT_TYPE_MEETUP             => 'meetup',
		EventTypesRegistry::EVENT_TYPE_HACKATHON          => 'hackathon',
		EventTypesRegistry::EVENT_TYPE_CONFERENCE         => 'conference',
		EventTypesRegistry::EVENT_TYPE_OTHER              => 'other',
	];

	public const EVENT_GROUP_TYPE_MSG_MAP = [
		EventTypesRegistry::EVENT_GROUP_TYPE_CONTRIBUTIONS => 'campaignevents-eventtypegroup-contributions',
		EventTypesRegistry::EVENT_GROUP_TYPE_COMMUNITY => 'campaignevents-eventtypegroup-community',
	];

	public const DEBUG_EVENT_GROUP_TYPE_NAMES_MAP = [
		EventTypesRegistry::EVENT_GROUP_TYPE_CONTRIBUTIONS => 'contributions',
		EventTypesRegistry::EVENT_GROUP_TYPE_COMMUNITY => 'community',
	];

	private IMessageFormatterFactory $messageFormatterFactory;

	public function __construct( IMessageFormatterFactory $messageFormatterFactory ) {
		$this->messageFormatterFactory = $messageFormatterFactory;
	}

	/**
	 * @param string $eventType One of the EventTypesRegistry::EVENT_TYPE_* constants
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
	 * @param string $eventGroupType One of the EventTypesRegistry::EVENT_GROUP_TYPE_* constants
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
	 * @param string $eventType One of the EventTypesRegistry::EVENT_TYPE_* constants
	 * @return string
	 */
	public function getEventTypeDebugName( string $eventType ): string {
		if ( !isset( self::DEBUG_NAMES_MAP[$eventType] ) ) {
			throw new InvalidArgumentException( "Invalid event type: $eventType" );
		}
		return self::DEBUG_NAMES_MAP[$eventType];
	}

	/**
	 * @param string $eventGroupType One of the EventTypesRegistry::EVENT_GROUP_TYPE_* constants
	 * @return string
	 */
	public function getEventTypeGroupsDebugName( string $eventGroupType ): string {
		if ( !isset( self::DEBUG_EVENT_GROUP_TYPE_NAMES_MAP[$eventGroupType] ) ) {
			throw new InvalidArgumentException( "Invalid event type group: $eventGroupType" );
		}
		return self::DEBUG_EVENT_GROUP_TYPE_NAMES_MAP[$eventGroupType];
	}
}
