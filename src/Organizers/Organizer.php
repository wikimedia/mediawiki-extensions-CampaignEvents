<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Organizers;

use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;

class Organizer {
	/** @var CentralUser */
	private $user;

	/** @var string[] */
	private $roles;

	/** @var int */
	private $organizerID;

	/** @var bool */
	private $clickwrapAcceptance;

	/**
	 * @param CentralUser $user
	 * @param string[] $roles List of Roles::ROLE_* constants
	 * @param int $organizerID Unique ID which identifies this specific organizer, for a specific event, in the DB.
	 * @param bool $clickwrapAcceptance boolean which indicates if the user has accepted the PII clickwrap agreement
	 */
	public function __construct( CentralUser $user, array $roles, int $organizerID, bool $clickwrapAcceptance ) {
		$this->user = $user;
		$this->roles = $roles;
		$this->organizerID = $organizerID;
		$this->clickwrapAcceptance = $clickwrapAcceptance;
	}

	/**
	 * @return CentralUser
	 */
	public function getUser(): CentralUser {
		return $this->user;
	}

	/**
	 * @return string[]
	 */
	public function getRoles(): array {
		return $this->roles;
	}

	/**
	 * @return int
	 */
	public function getOrganizerID(): int {
		return $this->organizerID;
	}

	/**
	 * @return bool
	 */
	public function getClickwrapAcceptance(): bool {
		return $this->clickwrapAcceptance;
	}
}
