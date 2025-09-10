<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Event;

use DateTimeZone;
use MediaWiki\Extension\CampaignEvents\Address\Address;
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
	 * @param string $status
	 * @param DateTimeZone $timezone
	 * @param string $startLocalTimestamp TS_MW timestamp
	 * @param string $endLocalTimestamp TS_MW timestamp
	 * @param non-empty-list<string> $types
	 * @param string[]|true $wikis
	 * @param string[] $topics
	 * @param int $participationOptions
	 * @param string|null $meetingURL
	 * @param Address|null $address
	 * @param bool $hasContributionTracking
	 * @param TrackingToolAssociation[] $trackingTools
	 * @phan-param list<TrackingToolAssociation> $trackingTools
	 * @param string|null $chatURL
	 * @param bool $isTestEvent
	 * @param int[] $participantQuestions
	 * @param string $creationTimestamp UNIX timestamp
	 * @param string $lastEditTimestamp UNIX timestamp
	 * @param string|null $deletionTimestamp UNIX timestamp
	 */
	public function __construct(
		int $id,
		string $name,
		MWPageProxy $page,
		string $status,
		DateTimeZone $timezone,
		string $startLocalTimestamp,
		string $endLocalTimestamp,
		array $types,
		$wikis,
		array $topics,
		int $participationOptions,
		?string $meetingURL,
		?Address $address,
		bool $hasContributionTracking,
		array $trackingTools,
		?string $chatURL,
		bool $isTestEvent,
		array $participantQuestions,
		string $creationTimestamp,
		string $lastEditTimestamp,
		?string $deletionTimestamp
	) {
		parent::__construct(
			$id,
			$name,
			$page,
			$status,
			$timezone,
			$startLocalTimestamp,
			$endLocalTimestamp,
			$types,
			$wikis,
			$topics,
			$participationOptions,
			$meetingURL,
			$address,
			$hasContributionTracking,
			$trackingTools,
			$chatURL,
			$isTestEvent,
			$participantQuestions,
			$creationTimestamp,
			$lastEditTimestamp,
			$deletionTimestamp
		);
	}

	public function getID(): int {
		// @phan-suppress-next-line PhanTypeMismatchReturnNullable
		return parent::getID();
	}

	public function getCreationTimestamp(): string {
		// @phan-suppress-next-line PhanTypeMismatchReturnNullable
		return parent::getCreationTimestamp();
	}

	public function getLastEditTimestamp(): string {
		// @phan-suppress-next-line PhanTypeMismatchReturnNullable
		return parent::getLastEditTimestamp();
	}
}
