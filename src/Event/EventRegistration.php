<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Event;

use DateTime;
use DateTimeZone;
use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWPageProxy;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolAssociation;
use MediaWiki\Utils\MWTimestamp;
use Wikimedia\Assert\Assert;

/**
 * Immutable value object that represents an abstract registration, i.e. one that may not exist in the database.
 */
class EventRegistration {
	public const STATUS_OPEN = 'open';
	public const STATUS_CLOSED = 'closed';
	public const VALID_STATUSES = [ self::STATUS_OPEN, self::STATUS_CLOSED ];

	public const ALL_WIKIS = true;

	public const TYPE_GENERIC = 'generic';

	public const MEETING_TYPE_ONLINE = 1 << 0;
	public const MEETING_TYPE_IN_PERSON = 1 << 1;
	public const MEETING_TYPE_ONLINE_AND_IN_PERSON = self::MEETING_TYPE_ONLINE | self::MEETING_TYPE_IN_PERSON;
	public const VALID_MEETING_TYPES = [
		self::MEETING_TYPE_ONLINE,
		self::MEETING_TYPE_IN_PERSON,
		self::MEETING_TYPE_ONLINE_AND_IN_PERSON
	];

	private ?int $id;
	/**
	 * @var string
	 * @todo Is this necessary?
	 */
	private string $name;
	private MWPageProxy $page;
	private ?string $chatURL;
	/** @var string[]|true List of wikis, or self::ALL_WIKIS */
	private $wikis;
	/** @var string[] */
	private array $topics;
	/**
	 * @var TrackingToolAssociation[]
	 * @phan-var list<TrackingToolAssociation>
	 */
	private array $trackingTools;
	/** @var string One of the STATUS_* constants */
	private string $status;
	private DateTimeZone $timezone;
	private string $startLocalTimestamp;
	private string $endLocalTimestamp;
	/** @var int One of the MEETING_TYPE_* constants */
	private int $meetingType;
	private ?string $meetingURL;
	private ?string $meetingCountry;
	private ?string $meetingAddress;
	/** @var int[] Array of database IDs */
	private array $participantQuestions;
	private ?string $creationTimestamp;
	private ?string $lastEditTimestamp;
	private ?string $deletionTimestamp;
	private bool $isTestEvent;

	/**
	 * @param int|null $id
	 * @param string $name
	 * @param MWPageProxy $page
	 * @param string|null $chatURL
	 * @param string[]|true $wikis A list of wiki IDs, or {@see self::ALL_WIKIS}.
	 * @param string[] $topics
	 * @param TrackingToolAssociation[] $trackingTools
	 * @phan-param list<TrackingToolAssociation> $trackingTools
	 * @param string $status
	 * @param DateTimeZone $timezone
	 * @param string $startLocalTimestamp TS_MW timestamp
	 * @param string $endLocalTimestamp TS_MW timestamp
	 * @param int $meetingType
	 * @param string|null $meetingURL
	 * @param string|null $meetingCountry
	 * @param string|null $meetingAddress
	 * @param array $participantQuestions
	 * @param string|null $creationTimestamp UNIX timestamp
	 * @param string|null $lastEditTimestamp UNIX timestamp
	 * @param string|null $deletionTimestamp UNIX timestamp
	 * @param bool $isTestEvent
	 */
	public function __construct(
		?int $id,
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
		int $meetingType,
		?string $meetingURL,
		?string $meetingCountry,
		?string $meetingAddress,
		array $participantQuestions,
		?string $creationTimestamp,
		?string $lastEditTimestamp,
		?string $deletionTimestamp,
		bool $isTestEvent
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
		$this->wikis = $wikis;
		$this->trackingTools = $trackingTools;
		$this->status = $status;
		$this->timezone = $timezone;
		$this->startLocalTimestamp = $startLocalTimestamp;
		$this->endLocalTimestamp = $endLocalTimestamp;
		$this->meetingType = $meetingType;
		$this->meetingURL = $meetingURL;
		$this->meetingCountry = $meetingCountry;
		$this->meetingAddress = $meetingAddress;
		$this->participantQuestions = $participantQuestions;
		$this->creationTimestamp = $creationTimestamp;
		$this->lastEditTimestamp = $lastEditTimestamp;
		$this->deletionTimestamp = $deletionTimestamp;
		$this->isTestEvent = $isTestEvent;
		$this->topics = $topics;
	}

	public function getID(): ?int {
		return $this->id;
	}

	public function getName(): string {
		return $this->name;
	}

	public function getPage(): MWPageProxy {
		return $this->page;
	}

	public function getChatURL(): ?string {
		return $this->chatURL;
	}

	/**
	 * @return string[]|true A list of wiki IDs, or {@see self::ALL_WIKIS}.
	 */
	public function getWikis() {
		return $this->wikis;
	}

	/**
	 * @return string[] A list of topics.
	 */
	public function getTopics(): array {
		return $this->topics;
	}

	/**
	 * @return TrackingToolAssociation[]
	 * @phan-return list<TrackingToolAssociation>
	 */
	public function getTrackingTools(): array {
		return $this->trackingTools;
	}

	public function getStatus(): string {
		return $this->status;
	}

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

	public function isPast(): bool {
		return wfTimestamp( TS_UNIX, $this->getEndUTCTimestamp() ) < MWTimestamp::now( TS_UNIX );
	}

	public function getMeetingType(): int {
		return $this->meetingType;
	}

	public function getMeetingURL(): ?string {
		return $this->meetingURL;
	}

	public function getMeetingCountry(): ?string {
		return $this->meetingCountry;
	}

	public function getMeetingAddress(): ?string {
		return $this->meetingAddress;
	}

	/**
	 * @return int[]
	 */
	public function getParticipantQuestions(): array {
		return $this->participantQuestions;
	}

	public function getCreationTimestamp(): ?string {
		return $this->creationTimestamp;
	}

	public function getLastEditTimestamp(): ?string {
		return $this->lastEditTimestamp;
	}

	public function getDeletionTimestamp(): ?string {
		return $this->deletionTimestamp;
	}

	public function getIsTestEvent(): bool {
		return $this->isTestEvent;
	}

	public function isOnLocalWiki(): bool {
		$eventPage = $this->getPage();
		$wikiID = $eventPage->getWikiId();
		return $wikiID === WikiAwareEntity::LOCAL;
	}
}
