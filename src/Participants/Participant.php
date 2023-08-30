<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Participants;

use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\Questions\Answer;

class Participant {
	/** @var CentralUser */
	private $user;
	/** @var string */
	private $registeredAt;
	/** @var int */
	private $participantID;
	/** @var bool */
	private $privateRegistration;
	/** @var Answer[] */
	private array $answers;
	private ?string $firstAnswerTimestamp;
	private ?string $aggregationTimestamp;

	/**
	 * @param CentralUser $user
	 * @param string $registeredAt Timestamp in the TS_UNIX format
	 * @param int $participantID participant_id, ID generated when a participant register for an event
	 * @param bool $privateRegistration
	 * @param Answer[] $answers
	 * @param string|null $firstAnswerTimestamp Timestamp in the TS_UNIX format
	 * @param string|null $aggregationTimestamp Timestamp in the TS_UNIX format
	 */
	public function __construct(
		CentralUser $user,
		string $registeredAt,
		int $participantID,
		bool $privateRegistration,
		array $answers,
		?string $firstAnswerTimestamp,
		?string $aggregationTimestamp
	) {
		$this->user = $user;
		$this->registeredAt = $registeredAt;
		$this->participantID = $participantID;
		$this->privateRegistration = $privateRegistration;
		$this->answers = $answers;
		$this->firstAnswerTimestamp = $firstAnswerTimestamp;
		$this->aggregationTimestamp = $aggregationTimestamp;
	}

	/**
	 * @return CentralUser
	 */
	public function getUser(): CentralUser {
		return $this->user;
	}

	/**
	 * @return string Timestamp in the TS_UNIX format
	 */
	public function getRegisteredAt(): string {
		return $this->registeredAt;
	}

	/**
	 * @return int participant_id, ID generated when a participant register for an event
	 */
	public function getParticipantID(): int {
		return $this->participantID;
	}

	/**
	 * @return bool privateRegistration, true if registration is private.
	 */
	public function isPrivateRegistration(): bool {
		return $this->privateRegistration;
	}

	/**
	 * @return Answer[]
	 */
	public function getAnswers(): array {
		return $this->answers;
	}

	/**
	 * @return string|null Timestamp in the TS_UNIX format, or null
	 */
	public function getFirstAnswerTimestamp(): ?string {
		return $this->firstAnswerTimestamp;
	}

	/**
	 * @return string|null Timestamp in the TS_UNIX format, or null
	 */
	public function getAggregationTimestamp(): ?string {
		return $this->aggregationTimestamp;
	}
}
