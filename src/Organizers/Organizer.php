<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Organizers;

use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsUser;

class Organizer {
	/** @var ICampaignsUser */
	private $user;

	/** @var string[] */
	private $roles;

	/**
	 * @param ICampaignsUser $user
	 * @param string[] $roles List of Roles::ROLE_* constants
	 */
	public function __construct( ICampaignsUser $user, array $roles ) {
		$this->user = $user;
		$this->roles = $roles;
	}

	/**
	 * @return ICampaignsUser
	 */
	public function getUser(): ICampaignsUser {
		return $this->user;
	}

	/**
	 * @return string[]
	 */
	public function getRoles(): array {
		return $this->roles;
	}
}
