<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Event;

class EventTypesRegistry {

	public const SERVICE_NAME = 'CampaignEventsEventTypesRegistry';

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

	public function getAllTypes(): array {
		return array_keys( self::EVENT_TYPES_MAP );
	}
}
