<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Organizers;

use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;

class Organizer {
	/** @var CentralUser */
	private $user;

	/** @var string[] */
	private $roles;

	/**
	 * @param CentralUser $user
	 * @param string[] $roles List of Roles::ROLE_* constants
	 */
	public function __construct( CentralUser $user, array $roles ) {
		$this->user = $user;
		$this->roles = $roles;
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
}
