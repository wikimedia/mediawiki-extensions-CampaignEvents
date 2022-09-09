<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Event;

use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsPage;
use MWTimestamp;
use Wikimedia\Assert\Assert;

/**
 * Immutable value object that represents an abstract registration, i.e. one that may not exist in the database.
 */
class EventRegistration {
	public const STATUS_OPEN = 'open';
	public const STATUS_CLOSED = 'closed';
	public const VALID_STATUSES = [ self::STATUS_OPEN, self::STATUS_CLOSED ];

	public const TYPE_GENERIC = 'generic';
	public const VALID_TYPES = [ self::TYPE_GENERIC ];

	public const MEETING_TYPE_ONLINE = 1 << 0;
	public const MEETING_TYPE_IN_PERSON = 1 << 1;
	public const MEETING_TYPE_ONLINE_AND_IN_PERSON = self::MEETING_TYPE_ONLINE | self::MEETING_TYPE_IN_PERSON;
	public const VALID_MEETING_TYPES = [
		self::MEETING_TYPE_ONLINE,
		self::MEETING_TYPE_IN_PERSON,
		self::MEETING_TYPE_ONLINE_AND_IN_PERSON
	];

	/** @var int|null */
	private $id;
	/**
	 * @var string
	 * @todo Is this necessary?
	 */
	private $name;
	/** @var ICampaignsPage */
	private $page;
	/** @var string|null */
	private $chatURL;
	/**
	 * @var string|null
	 * @todo Should this and the URL be moved to a separate interface?
	 */
	private $trackingToolName;
	/** @var string|null */
	private $trackingToolURL;
	/** @var string One of the STATUS_* constants */
	private $status;
	/** @var string */
	private $startTimestamp;
	/** @var string */
	private $endTimestamp;
	/** @var string One of the TYPE_* constants */
	private $type;
	/** @var int One of the MEETING_TYPE_* constants */
	private $meetingType;
	/** @var string|null */
	private $meetingURL;
	/** @var string|null */
	private $meetingCountry;
	/** @var string|null */
	private $meetingAddress;
	/** @var string|null */
	private $creationTimestamp;
	/** @var string|null */
	private $lastEditTimestamp;
	/** @var string|null */
	private $deletionTimestamp;

	/**
	 * @param int|null $id
	 * @param string $name
	 * @param ICampaignsPage $page
	 * @param string|null $chatURL
	 * @param string|null $trackingToolName
	 * @param string|null $trackingToolURL
	 * @param string $status
	 * @param string $startTimestamp TS_MW timestamp
	 * @param string $endTimestamp TS_MW timestamp
	 * @param string $type
	 * @param int $meetingType
	 * @param string|null $meetingURL
	 * @param string|null $meetingCountry
	 * @param string|null $meetingAddress
	 * @param string|null $creationTimestamp UNIX timestamp
	 * @param string|null $lastEditTimestamp UNIX timestamp
	 * @param string|null $deletionTimestamp UNIX timestamp
	 */
	public function __construct(
		?int $id,
		string $name,
		ICampaignsPage $page,
		?string $chatURL,
		?string $trackingToolName,
		?string $trackingToolURL,
		string $status,
		string $startTimestamp,
		string $endTimestamp,
		string $type,
		int $meetingType,
		?string $meetingURL,
		?string $meetingCountry,
		?string $meetingAddress,
		?string $creationTimestamp,
		?string $lastEditTimestamp,
		?string $deletionTimestamp
	) {
		Assert::parameter(
			MWTimestamp::convert( TS_MW, $startTimestamp ) === $startTimestamp,
			'$startTimestamp',
			'Should be in TS_MW format.'
		);
		Assert::parameter(
			MWTimestamp::convert( TS_MW, $endTimestamp ) === $endTimestamp,
			'$endTimestamp',
			'Should be in TS_MW format.'
		);
		$this->id = $id;
		$this->name = $name;
		$this->page = $page;
		$this->chatURL = $chatURL;
		$this->trackingToolName = $trackingToolName;
		$this->trackingToolURL = $trackingToolURL;
		$this->status = $status;
		$this->startTimestamp = $startTimestamp;
		$this->endTimestamp = $endTimestamp;
		$this->type = $type;
		$this->meetingType = $meetingType;
		$this->meetingURL = $meetingURL;
		$this->meetingCountry = $meetingCountry;
		$this->meetingAddress = $meetingAddress;
		$this->creationTimestamp = $creationTimestamp;
		$this->lastEditTimestamp = $lastEditTimestamp;
		$this->deletionTimestamp = $deletionTimestamp;
	}

	/**
	 * @return int|null
	 */
	public function getID(): ?int {
		return $this->id;
	}

	/**
	 * @return string
	 */
	public function getName(): string {
		return $this->name;
	}

	/**
	 * @return ICampaignsPage
	 */
	public function getPage(): ICampaignsPage {
		return $this->page;
	}

	/**
	 * @return string|null
	 */
	public function getChatURL(): ?string {
		return $this->chatURL;
	}

	/**
	 * @return string|null
	 */
	public function getTrackingToolName(): ?string {
		return $this->trackingToolName;
	}

	/**
	 * @return string|null
	 */
	public function getTrackingToolURL(): ?string {
		return $this->trackingToolURL;
	}

	/**
	 * @return string
	 */
	public function getStatus(): string {
		return $this->status;
	}

	/**
	 * @return string Timestamp in the TS_MW format
	 */
	public function getStartTimestamp(): string {
		return $this->startTimestamp;
	}

	/**
	 * @return string Timestamp in TS_MW format
	 */
	public function getEndTimestamp(): string {
		return $this->endTimestamp;
	}

	/**
	 * @return string
	 */
	public function getType(): string {
		return $this->type;
	}

	/**
	 * @return int
	 */
	public function getMeetingType(): int {
		return $this->meetingType;
	}

	/**
	 * @return string|null
	 */
	public function getMeetingURL(): ?string {
		return $this->meetingURL;
	}

	/**
	 * @return string|null
	 */
	public function getMeetingCountry(): ?string {
		return $this->meetingCountry;
	}

	/**
	 * @return string|null
	 */
	public function getMeetingAddress(): ?string {
		return $this->meetingAddress;
	}

	/**
	 * @return string|null
	 */
	public function getCreationTimestamp(): ?string {
		return $this->creationTimestamp;
	}

	/**
	 * @return string|null
	 */
	public function getLastEditTimestamp(): ?string {
		return $this->lastEditTimestamp;
	}

	/**
	 * @return string|null
	 */
	public function getDeletionTimestamp(): ?string {
		return $this->deletionTimestamp;
	}
}
