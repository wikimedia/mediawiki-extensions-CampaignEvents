<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Event;

use DateTime;
use DateTimeZone;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsPage;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolAssociation;
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
	 * @var TrackingToolAssociation[]
	 * @phan-var list<TrackingToolAssociation>
	 */
	private $trackingTools;
	/** @var string One of the STATUS_* constants */
	private $status;
	/** @var DateTimeZone */
	private $timezone;
	/** @var string */
	private $startLocalTimestamp;
	/** @var string */
	private $endLocalTimestamp;
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
	/** @var int[] Array of database IDs */
	private array $participantQuestions;
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
	 * @param array $participantQuestions
	 * @param string|null $creationTimestamp UNIX timestamp
	 * @param string|null $lastEditTimestamp UNIX timestamp
	 * @param string|null $deletionTimestamp UNIX timestamp
	 */
	public function __construct(
		?int $id,
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
		array $participantQuestions,
		?string $creationTimestamp,
		?string $lastEditTimestamp,
		?string $deletionTimestamp
	) {
		Assert::parameter(
			MWTimestamp::convert( TS_MW, $startLocalTimestamp ) === $startLocalTimestamp,
			'$startLocalTimestamp',
			'Should be in TS_MW format.'
		);
		Assert::parameter(
			MWTimestamp::convert( TS_MW, $endLocalTimestamp ) === $endLocalTimestamp,
			'$endLocalTimestamp',
			'Should be in TS_MW format.'
		);
		Assert::parameter(
			count( $trackingTools ) <= 1,
			'$trackingTools',
			'Should not have more than one tracking tool'
		);
		$this->id = $id;
		$this->name = $name;
		$this->page = $page;
		$this->chatURL = $chatURL;
		$this->trackingTools = $trackingTools;
		$this->status = $status;
		$this->timezone = $timezone;
		$this->startLocalTimestamp = $startLocalTimestamp;
		$this->endLocalTimestamp = $endLocalTimestamp;
		$this->type = $type;
		$this->meetingType = $meetingType;
		$this->meetingURL = $meetingURL;
		$this->meetingCountry = $meetingCountry;
		$this->meetingAddress = $meetingAddress;
		$this->participantQuestions = $participantQuestions;
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
	 * @return TrackingToolAssociation[]
	 * @phan-return list<TrackingToolAssociation>
	 */
	public function getTrackingTools(): array {
		return $this->trackingTools;
	}

	/**
	 * @return string
	 */
	public function getStatus(): string {
		return $this->status;
	}

	/**
	 * @return DateTimeZone
	 */
	public function getTimezone(): DateTimeZone {
		return $this->timezone;
	}

	/**
	 * @return string Timestamp in the TS_MW format
	 */
	public function getStartLocalTimestamp(): string {
		return $this->startLocalTimestamp;
	}

	/**
	 * @return string Timestamp in the TS_MW format
	 */
	public function getStartUTCTimestamp(): string {
		$localDateTime = new DateTime( $this->startLocalTimestamp, $this->timezone );
		$utcStartTime = $localDateTime->setTimezone( new DateTimeZone( 'UTC' ) )->getTimestamp();
		return wfTimestamp( TS_MW, $utcStartTime );
	}

	/**
	 * @return string Timestamp in TS_MW format
	 */
	public function getEndLocalTimestamp(): string {
		return $this->endLocalTimestamp;
	}

	/**
	 * @return string Timestamp in the TS_MW format
	 */
	public function getEndUTCTimestamp(): string {
		$localDateTime = new DateTime( $this->endLocalTimestamp, $this->timezone );
		$utcEndTime = $localDateTime->setTimezone( new DateTimeZone( 'UTC' ) )->getTimestamp();
		return wfTimestamp( TS_MW, $utcEndTime );
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
	 * @return int[]
	 */
	public function getParticipantQuestions(): array {
		return $this->participantQuestions;
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
