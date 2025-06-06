<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Event;

use InvalidArgumentException;

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
					'type' => 'training-seminar',
					'msgKey'  => 'campaignevents-eventtype-training-seminar',
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

	/** @return list<string> */
	public function getAllTypes(): array {
		$types = [];
		foreach ( self::EVENT_TYPES as $group ) {
			$types = array_merge( $types, array_column( $group['types'], 'type' ) );
		}
		return $types;
	}

	/**
	 * @param list<string> $typeNames Event type names
	 * @return array<string,string> Maps the provided names to message keys
	 */
	public function getTypeMessages( array $typeNames ): array {
		$ret = array_fill_keys( $typeNames, null );
		foreach ( self::EVENT_TYPES as $group ) {
			foreach ( $group['types'] as $typeInfo ) {
				$typeName = $typeInfo['type'];
				if ( array_key_exists( $typeName, $ret ) ) {
					$ret[$typeName] = $typeInfo['msgKey'];
				}
			}
		}
		if ( in_array( null, $ret, true ) ) {
			$invalidTypes = array_keys( array_filter( $ret, static fn ( ?string $t ): bool => $t === null ) );
			throw new InvalidArgumentException( "Invalid types: " . implode( ', ', $invalidTypes ) );
		}
		return $ret;
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
	 * @param string $rawDBTypes
	 * @return list<string>
	 */
	public static function getEventTypesFromDBVal( string $rawDBTypes ): array {
		$dbEventType = (int)$rawDBTypes;
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
	 * @param list<string> $types
	 * @return int
	 */
	public static function eventTypesToDBVal( array $types ): int {
		$dbEventType = 0;
		foreach ( self::EVENT_TYPES as $group ) {
			foreach ( $group['types'] as $typeInfo ) {
				if ( in_array( $typeInfo['type'], $types, true ) ) {
					$dbEventType |= $typeInfo['dbValue'];
				}
			}
		}
		return $dbEventType;
	}
}
