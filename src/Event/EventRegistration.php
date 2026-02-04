<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Event;

use DateTime;
use DateTimeZone;
use LogicException;
use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Extension\CampaignEvents\Address\Address;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWPageProxy;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolAssociation;
use MediaWiki\Page\PageIdentityValue;
use MediaWiki\Utils\MWTimestamp;
use Wikimedia\Assert\Assert;
use Wikimedia\JsonCodec\JsonCodecable;
use Wikimedia\JsonCodec\JsonCodecableTrait;
use Wikimedia\Timestamp\TimestampFormat as TS;

/**
 * Immutable value object that represents an abstract registration, i.e. one that may not exist in the database.
 */
class EventRegistration implements JsonCodecable {
	use JsonCodecableTrait;

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

	/**
	 * @param int|null $id
	 * @param string $name @todo Is this necessary?
	 * @param MWPageProxy $page
	 * @param string $status One of the STATUS_* constants
	 * @param DateTimeZone $timezone
	 * @param string $startLocalTimestamp TS::MW timestamp
	 * @param string $endLocalTimestamp TS::MW timestamp
	 * @param non-empty-list<string> $types Event type names
	 * @param string[]|true $wikis A list of wiki IDs, or {@see self::ALL_WIKIS}.
	 * @param string[] $topics
	 * @param int $participationOptions One of the PARTICIPATION_OPTION_* constants
	 * @param string|null $meetingURL
	 * @param Address|null $address Required when $participationOptions contains self::PARTICIPATION_OPTION_IN_PERSON
	 * @param bool $hasContributionTracking
	 * @param TrackingToolAssociation[] $trackingTools
	 * @phan-param list<TrackingToolAssociation> $trackingTools
	 * @param string|null $chatURL
	 * @param bool $isTestEvent
	 * @param list<int> $participantQuestions Array of database IDs
	 * @param string|null $creationTimestamp UNIX timestamp
	 * @param string|null $lastEditTimestamp UNIX timestamp
	 * @param string|null $deletionTimestamp UNIX timestamp
	 */
	public function __construct(
		private readonly ?int $id,
		private readonly string $name,
		private readonly MWPageProxy $page,
		private readonly string $status,
		private readonly DateTimeZone $timezone,
		private readonly string $startLocalTimestamp,
		private readonly string $endLocalTimestamp,
		private readonly array $types,
		private readonly array|bool $wikis,
		private readonly array $topics,
		private readonly int $participationOptions,
		private readonly ?string $meetingURL,
		private readonly ?Address $address,
		private readonly bool $hasContributionTracking,
		private readonly array $trackingTools,
		private readonly ?string $chatURL,
		private readonly bool $isTestEvent,
		private readonly array $participantQuestions,
		private readonly ?string $creationTimestamp,
		private readonly ?string $lastEditTimestamp,
		private readonly ?string $deletionTimestamp,
	) {
		Assert::parameter(
			MWTimestamp::convert( TS::MW, $startLocalTimestamp ) === $startLocalTimestamp,
			'$startLocalTimestamp',
			'Should be in TS::MW format.'
		);
		Assert::parameter(
			MWTimestamp::convert( TS::MW, $endLocalTimestamp ) === $endLocalTimestamp,
			'$endLocalTimestamp',
			'Should be in TS::MW format.'
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
		if ( $participationOptions & self::PARTICIPATION_OPTION_IN_PERSON ) {
			Assert::parameter( $address !== null, '$address', 'In-person and hybrid events must have an Address' );
		}
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
	 * @return string Timestamp in the TS::MW format
	 */
	public function getStartLocalTimestamp(): string {
		return $this->startLocalTimestamp;
	}

	/**
	 * @return string Timestamp in the TS::MW format
	 */
	public function getStartUTCTimestamp(): string {
		$localDateTime = new DateTime( $this->startLocalTimestamp, $this->timezone );
		$utcStartTime = $localDateTime->setTimezone( new DateTimeZone( 'UTC' ) )->getTimestamp();
		return wfTimestamp( TS::MW, $utcStartTime );
	}

	/**
	 * @return string Timestamp in TS::MW format
	 */
	public function getEndLocalTimestamp(): string {
		return $this->endLocalTimestamp;
	}

	/**
	 * @return string Timestamp in the TS::MW format
	 */
	public function getEndUTCTimestamp(): string {
		$localDateTime = new DateTime( $this->endLocalTimestamp, $this->timezone );
		$utcEndTime = $localDateTime->setTimezone( new DateTimeZone( 'UTC' ) )->getTimestamp();
		return wfTimestamp( TS::MW, $utcEndTime );
	}

	public function isPast(): bool {
		return wfTimestamp( TS::UNIX, $this->getEndUTCTimestamp() ) < MWTimestamp::now( TS::UNIX );
	}

	public function isOngoing(): bool {
		return !$this->isPast() && !$this->isFuture();
	}

	public function isFuture(): bool {
		return wfTimestamp( TS::UNIX, $this->getStartUTCTimestamp() ) > MWTimestamp::now( TS::UNIX );
	}

	/** @return non-empty-list<string> */
	public function getTypes(): array {
		return $this->types;
	}

	/**
	 * @return string[]|true A list of wiki IDs, or {@see self::ALL_WIKIS}.
	 */
	public function getWikis(): array|bool {
		return $this->wikis;
	}

	/**
	 * @return string[] A list of topics.
	 */
	public function getTopics(): array {
		return $this->topics;
	}

	public function getParticipationOptions(): int {
		return $this->participationOptions;
	}

	public function getMeetingURL(): ?string {
		return $this->meetingURL;
	}

	public function getAddress(): ?Address {
		return $this->address;
	}

	/**
	 * Returns the event address if there is one, throwing an exception otherwise. This is only meant to be used when
	 * the caller has made sure that an address is set, for example by checking the participation options and verifying
	 * that the self::PARTICIPATION_OPTION_IN_PERSON bit is set.
	 */
	public function getAddressOrThrow(): Address {
		if ( !$this->address ) {
			throw new LogicException( 'Called on event without address.' );
		}
		return $this->address;
	}

	public function hasContributionTracking(): bool {
		return $this->hasContributionTracking;
	}

	/**
	 * @return TrackingToolAssociation[]
	 * @phan-return list<TrackingToolAssociation>
	 */
	public function getTrackingTools(): array {
		return $this->trackingTools;
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

	/**
	 * @inheritDoc
	 * @return array<string,mixed>
	 */
	public function toJsonArray(): array {
		$pageIdentity = $this->page->getPageIdentity();
		return [
			'id' => $this->id,
			'name' => $this->name,
			'pageID' => $pageIdentity->getId( $pageIdentity->getWikiId() ),
			'pageNamespace' => $pageIdentity->getNamespace(),
			'pageDbKey' => $pageIdentity->getDBkey(),
			'pageWikiID' => $pageIdentity->getWikiId(),
			'prefixedText' => $this->page->getPrefixedText(),
			'status' => $this->status,
			// Note, this works for all timezone types, even those whose "name" is just an offset
			'timezoneName' => $this->timezone->getName(),
			'startLocalTimestamp' => $this->startLocalTimestamp,
			'endLocalTimestamp' => $this->endLocalTimestamp,
			'types' => $this->types,
			'wikis' => $this->wikis,
			'topics' => $this->topics,
			'participationOptions' => $this->participationOptions,
			'meetingURL' => $this->meetingURL,
			'addressEncoded' => $this->address?->toJsonArray(),
			'hasContributionTracking' => $this->hasContributionTracking,
			'trackingToolsEncoded' => array_map(
				/** @return array<string,mixed> */
				static fn ( TrackingToolAssociation $assoc ): array => $assoc->toJsonArray(),
				$this->trackingTools
			),
			'chatURL' => $this->chatURL,
			'isTestEvent' => $this->isTestEvent,
			'participantQuestions' => $this->participantQuestions,
			'creationTimestamp' => $this->creationTimestamp,
			'lastEditTimestamp' => $this->lastEditTimestamp,
			'deletionTimestamp' => $this->deletionTimestamp,
		];
	}

	/**
	 * @inheritDoc
	 * @param array<string,mixed> $json
	 */
	public static function newFromJsonArray( array $json ): static {
		return new static(
			$json['id'],
			$json['name'],
			new MWPageProxy(
				new PageIdentityValue(
					$json['pageID'],
					$json['pageNamespace'],
					$json['pageDbKey'],
					$json['pageWikiID']
				),
				$json['prefixedText']
			),
			$json['status'],
			new DateTimeZone( $json['timezoneName'] ),
			$json['startLocalTimestamp'],
			$json['endLocalTimestamp'],
			$json['types'],
			$json['wikis'],
			$json['topics'],
			$json['participationOptions'],
			$json['meetingURL'],
			$json['addressEncoded'] ? Address::newFromJsonArray( $json['addressEncoded'] ) : null,
			$json['hasContributionTracking'],
			array_map( TrackingToolAssociation::newFromJsonArray( ... ), $json['trackingToolsEncoded'] ),
			$json['chatURL'],
			$json['isTestEvent'],
			$json['participantQuestions'],
			$json['creationTimestamp'],
			$json['lastEditTimestamp'],
			$json['deletionTimestamp'],
		);
	}
}
