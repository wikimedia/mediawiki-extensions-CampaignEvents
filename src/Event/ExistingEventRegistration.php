<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Event;

use DateTimeZone;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsPage;
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
	 * @param ICampaignsPage $page
	 * @param string|null $chatURL
	 * @param TrackingToolAssociation[] $trackingTools
	 * @phan-param list<TrackingToolAssociation> $trackingTools
	 * @param string $status
	 * @param DateTimeZone $timezone
	 * @param string $startLocalTimestamp TS_MW timestamp
	 * @param string $endLocalTimestamp TS_MW timestamp
	 * @param string $type
	 * @param int $meetingType
	 * @param string|null $meetingURL
	 * @param string|null $meetingCountry
	 * @param string|null $meetingAddress
	 * @param string $creationTimestamp UNIX timestamp
	 * @param string $lastEditTimestamp UNIX timestamp
	 * @param string|null $deletionTimestamp UNIX timestamp
	 */
	public function __construct(
		int $id,
		string $name,
		ICampaignsPage $page,
		?string $chatURL,
		array $trackingTools,
		string $status,
		DateTimeZone $timezone,
		string $startLocalTimestamp,
		string $endLocalTimestamp,
		string $type,
		int $meetingType,
		?string $meetingURL,
		?string $meetingCountry,
		?string $meetingAddress,
		string $creationTimestamp,
		string $lastEditTimestamp,
		?string $deletionTimestamp
	) {
		// @phan-suppress-next-line PhanParamTooFewUnpack
		parent::__construct( ...func_get_args() );
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
