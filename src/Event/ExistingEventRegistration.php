<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Event;

use DateTimeZone;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWPageProxy;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolAssociation;

/**
 * Immutable value object that represents a registration that exists in the database.
 */
class ExistingEventRegistration extends EventRegistration {
	/**
	 * Same as the parent, but ID, creation timestamp and last edit timestamp are not nullable.
	 *
	 * @param int $id
	 * @param string $name
	 * @param MWPageProxy $page
	 * @param string|null $chatURL
	 * @param string[]|true $wikis
	 * @param string[] $topics
	 * @param TrackingToolAssociation[] $trackingTools
	 * @phan-param list<TrackingToolAssociation> $trackingTools
	 * @param string $status
	 * @param DateTimeZone $timezone
	 * @param string $startLocalTimestamp TS_MW timestamp
	 * @param string $endLocalTimestamp TS_MW timestamp
	 * @param list<string> $types
	 * @param int $meetingType
	 * @param string|null $meetingURL
	 * @param string|null $meetingCountry
	 * @param string|null $meetingAddress
	 * @param int[] $participantQuestions
	 * @param string $creationTimestamp UNIX timestamp
	 * @param string $lastEditTimestamp UNIX timestamp
	 * @param string|null $deletionTimestamp UNIX timestamp
	 * @param bool $isTestEvent
	 */
	public function __construct(
		int $id,
		string $name,
		MWPageProxy $page,
		?string $chatURL,
		$wikis,
		array $topics,
		array $trackingTools,
		string $status,
		DateTimeZone $timezone,
		string $startLocalTimestamp,
		string $endLocalTimestamp,
		array $types,
		int $meetingType,
		?string $meetingURL,
		?string $meetingCountry,
		?string $meetingAddress,
		array $participantQuestions,
		string $creationTimestamp,
		string $lastEditTimestamp,
		?string $deletionTimestamp,
		bool $isTestEvent
	) {
		parent::__construct(
			$id,
			$name,
			$page,
			$chatURL,
			$wikis,
			$topics,
			$trackingTools,
			$status,
			$timezone,
			$startLocalTimestamp,
			$endLocalTimestamp,
			$types,
			$meetingType,
			$meetingURL,
			$meetingCountry,
			$meetingAddress,
			$participantQuestions,
			$creationTimestamp,
			$lastEditTimestamp,
			$deletionTimestamp,
			$isTestEvent
		);
	}

	/**
	 * @return int
	 */
	public function getID(): int {
		// @phan-suppress-next-line PhanTypeMismatchReturnNullable
		return parent::getID();
	}

	/**
	 * @return string
	 */
	public function getCreationTimestamp(): string {
		// @phan-suppress-next-line PhanTypeMismatchReturnNullable
		return parent::getCreationTimestamp();
	}

	/**
	 * @return string
	 */
	public function getLastEditTimestamp(): string {
		// @phan-suppress-next-line PhanTypeMismatchReturnNullable
		return parent::getLastEditTimestamp();
	}
}
