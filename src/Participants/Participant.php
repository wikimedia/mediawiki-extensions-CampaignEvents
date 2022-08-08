<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Participants;

use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;

class Participant {
	/** @var CentralUser */
	private $user;
	/** @var string */
	private $registeredAt;
	/** @var int */
	private $participantID;

	/**
	 * @param CentralUser $user
	 * @param string $registeredAt Timestamp in the TS_UNIX format
	 * @param int $participantID participant_id, ID generated when a participant register for an event
	 */
	public function __construct( CentralUser $user, string $registeredAt, int $participantID ) {
		$this->user = $user;
		$this->registeredAt = $registeredAt;
		$this->participantID = $participantID;
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
}
