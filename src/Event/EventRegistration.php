<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Event;

use DateTime;
use DateTimeZone;
use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Extension\CampaignEvents\Address\Address;
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

	public const PARTICIPATION_OPTION_ONLINE = 1 << 0;
	public const PARTICIPATION_OPTION_IN_PERSON = 1 << 1;
	public const PARTICIPATION_OPTION_ONLINE_AND_IN_PERSON =
		self::PARTICIPATION_OPTION_ONLINE | self::PARTICIPATION_OPTION_IN_PERSON;
	public const VALID_PARTICIPATION_OPTIONS = [
		self::PARTICIPATION_OPTION_ONLINE,
		self::PARTICIPATION_OPTION_IN_PERSON,
		self::PARTICIPATION_OPTION_ONLINE_AND_IN_PERSON
	];

	private ?int $id;
	/**
	 * @var string
	 * @todo Is this necessary?
	 */
	private string $name;
	private MWPageProxy $page;
	/** @var string One of the STATUS_* constants */
	private string $status;
	private DateTimeZone $timezone;
	private string $startLocalTimestamp;
	private string $endLocalTimestamp;
	/** @var non-empty-list<string> Event type names */
	private array $types;
	/** @var string[]|true List of wikis, or self::ALL_WIKIS */
	private $wikis;
	/** @var string[] */
	private array $topics;
	/**
	 * @var TrackingToolAssociation[]
	 * @phan-var list<TrackingToolAssociation>
	 */
	private array $trackingTools;
	/** @var int One of the PARTICIPATION_OPTION_* constants */
	private int $participationOptions;
	private ?string $meetingURL;
	private ?Address $meetingAddress;
	private ?string $chatURL;
	private bool $isTestEvent;
	/** @var int[] Array of database IDs */
	private array $participantQuestions;
	private ?string $creationTimestamp;
	private ?string $lastEditTimestamp;
	private ?string $deletionTimestamp;

	/**
	 * @param int|null $id
	 * @param string $name
	 * @param MWPageProxy $page
	 * @param string $status
	 * @param DateTimeZone $timezone
	 * @param string $startLocalTimestamp TS_MW timestamp
	 * @param string $endLocalTimestamp TS_MW timestamp
	 * @param non-empty-list<string> $types
	 * @param string[]|true $wikis A list of wiki IDs, or {@see self::ALL_WIKIS}.
	 * @param string[] $topics
	 * @param TrackingToolAssociation[] $trackingTools
	 * @phan-param list<TrackingToolAssociation> $trackingTools
	 * @param int $participationOptions
	 * @param string|null $meetingURL
	 * @param Address|null $meetingAddress
	 * @param string|null $chatURL
	 * @param bool $isTestEvent
	 * @param list<int> $participantQuestions
	 * @param string|null $creationTimestamp UNIX timestamp
	 * @param string|null $lastEditTimestamp UNIX timestamp
	 * @param string|null $deletionTimestamp UNIX timestamp
	 */
	public function __construct(
		?int $id,
		string $name,
		MWPageProxy $page,
		string $status,
		DateTimeZone $timezone,
		string $startLocalTimestamp,
		string $endLocalTimestamp,
		array $types,
		$wikis,
		array $topics,
		array $trackingTools,
		int $participationOptions,
		?string $meetingURL,
		?Address $meetingAddress,
		?string $chatURL,
		bool $isTestEvent,
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
			count( $types ) >= 1,
			'$types',
			'Must have at least one type'
		);
		Assert::parameter(
			count( $trackingTools ) <= 1,
			'$trackingTools',
			'Should not have more than one tracking tool'
		);
		$this->id = $id;
		$this->name = $name;
		$this->page = $page;
		$this->status = $status;
		$this->timezone = $timezone;
		$this->startLocalTimestamp = $startLocalTimestamp;
		$this->endLocalTimestamp = $endLocalTimestamp;
		$this->types = $types;
		$this->wikis = $wikis;
		$this->topics = $topics;
		$this->trackingTools = $trackingTools;
		$this->participationOptions = $participationOptions;
		$this->meetingURL = $meetingURL;
		$this->meetingAddress = $meetingAddress;
		$this->chatURL = $chatURL;
		$this->isTestEvent = $isTestEvent;
		$this->participantQuestions = $participantQuestions;
		$this->creationTimestamp = $creationTimestamp;
		$this->lastEditTimestamp = $lastEditTimestamp;
		$this->deletionTimestamp = $deletionTimestamp;
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

	/** @return non-empty-list<string> */
	public function getTypes(): array {
		return $this->types;
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

	public function getParticipationOptions(): int {
		return $this->participationOptions;
	}

	public function getMeetingURL(): ?string {
		return $this->meetingURL;
	}

	public function getAddress(): ?Address {
		return $this->meetingAddress;
	}

	public function getChatURL(): ?string {
		return $this->chatURL;
	}

	public function getIsTestEvent(): bool {
		return $this->isTestEvent;
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

	public function isOnLocalWiki(): bool {
		$eventPage = $this->getPage();
		$wikiID = $eventPage->getWikiId();
		return $wikiID === WikiAwareEntity::LOCAL;
	}
}
