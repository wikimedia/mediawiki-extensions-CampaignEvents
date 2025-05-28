<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Event;

use InvalidArgumentException;
use Wikimedia\Message\IMessageFormatterFactory;
use Wikimedia\Message\MessageValue;

class EventTypesRegistry {

	public const SERVICE_NAME = 'CampaignEventsEventTypesRegistry';

	public const EVENT_TYPE_OTHER = 'other';

	public const EVENT_TYPES = [
		[
			'group' => 'contributions',
			'msgKey' => 'campaignevents-eventtypegroup-contributions',
			'types' => [
				[
					'type' => 'editing-event',
					'msgKey'  => 'campaignevents-eventtype-editing-event',
					'dbValue' => 1 << 0,
				],
				[
					'type' => 'media-upload-event',
					'msgKey'  => 'campaignevents-eventtype-media-upload-event',
					'dbValue' => 1 << 1,
				],
				[
					'type' => 'backlog-drive',
					'msgKey'  => 'campaignevents-eventtype-backlog-drive',
					'dbValue' => 1 << 2,
				],
				[
					'type' => 'contest',
					'msgKey'  => 'campaignevents-eventtype-contest',
					'dbValue' => 1 << 3,
				],
			]
		],
		[
			'group' => 'community',
			'msgKey' => 'campaignevents-eventtypegroup-community',
			'types' => [
				[
					'type' => 'workshop',
					'msgKey'  => 'campaignevents-eventtype-workshop',
					'dbValue' => 1 << 4,
				],
				[
					'type' => 'training',
					'msgKey'  => 'campaignevents-eventtype-training',
					'dbValue' => 1 << 5,
				],
				[
					'type' => 'meetup',
					'msgKey'  => 'campaignevents-eventtype-meetup',
					'dbValue' => 1 << 6,
				],
				[
					'type' => 'hackathon',
					'msgKey'  => 'campaignevents-eventtype-hackathon',
					'dbValue' => 1 << 7,
				],
				[
					'type' => 'conference',
					'msgKey'  => 'campaignevents-eventtype-conference',
					'dbValue' => 1 << 8,
				],
			]
		],
		[
			'group' => 'other',
			'msgKey' => 'campaignevents-edit-field-eventtypes-other-section-header',
			'types' => [
				[
					'type' => self::EVENT_TYPE_OTHER,
					'msgKey'  => 'campaignevents-eventtype-other',
					'dbValue' => 0,
				],
			],
		],
	];

	private IMessageFormatterFactory $messageFormatterFactory;

	public function __construct( IMessageFormatterFactory $messageFormatterFactory ) {
		$this->messageFormatterFactory = $messageFormatterFactory;
	}

	/** @return list<string> */
	public function getAllTypes(): array {
		$types = [];
		foreach ( self::EVENT_TYPES as $group ) {
			$types = array_merge( $types, array_column( $group['types'], 'type' ) );
		}
		return $types;
	}

	/**
	 * @param string $eventType One of the self::EVENT_TYPE_* constants
	 * @param string $languageCode
	 * @return string
	 */
	public function getLocalizedEventTypeName( string $eventType, string $languageCode ): string {
		foreach ( self::EVENT_TYPES as $group ) {
			foreach ( $group['types'] as $typeInfo ) {
				if ( $typeInfo['type'] === $eventType ) {
					$formatter = $this->messageFormatterFactory->getTextFormatter( $languageCode );
					return $formatter->format( MessageValue::new( $typeInfo['msgKey'] ) );
				}
			}
		}
		throw new InvalidArgumentException( "Invalid event type: $eventType" );
	}

	/**
	 * @return array<string,array<string,string>> That maps message keys to topic IDs,
	 *  with category groups represented as nested array of <string,string> suitable for use
	 *  in multiselect widgets.
	 */
	public function getAllOptionMessages(): array {
		$optionsMessages = [];
		foreach ( self::EVENT_TYPES as $group ) {
			$groupLabelMsgKey = $group['msgKey'];
			$optionsMessages[$groupLabelMsgKey] = [];

			foreach ( $group['types'] as $typeInfo ) {
				$eventTypeMsgKey = $typeInfo['msgKey'];
				$optionsMessages[$groupLabelMsgKey][$eventTypeMsgKey] = $typeInfo['type'];
			}
		}
		return $optionsMessages;
	}

	/**
	 * Converts event types as stored in the DB into a list of human-readable type identifiers (NOT localized).
	 * @param string $rawDBEventType
	 * @return list<string>
	 */
	public static function getEventTypesFromDBVal( string $rawDBEventType ): array {
		$dbEventType = (int)$rawDBEventType;
		$result = [];
		foreach ( self::EVENT_TYPES as $group ) {
			foreach ( $group['types'] as $typeInfo ) {
				$curDBVal = $typeInfo['dbValue'];
				if (
					( $curDBVal === 0 && $dbEventType === 0 ) ||
					( $curDBVal !== 0 && ( $dbEventType & $curDBVal ) === $curDBVal )
				) {
					$result[] = $typeInfo['type'];
				}
			}
		}
		return $result;
	}

	/**
	 * Converts a list of human-readable event type identifiers (NOT localized) to a combination of numeric constants
	 * that can be stored into the DB.
	 * @param list<string> $eventTypes
	 * @return int
	 */
	public static function eventTypesToDBVal( array $eventTypes ): int {
		$dbEventType = 0;
		foreach ( self::EVENT_TYPES as $group ) {
			foreach ( $group['types'] as $typeInfo ) {
				if ( in_array( $typeInfo['type'], $eventTypes, true ) ) {
					$dbEventType |= $typeInfo['dbValue'];
				}
			}
		}
		return $dbEventType;
	}
}
