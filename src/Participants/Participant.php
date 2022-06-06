<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Participants;

use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsUser;

class Participant {
	/** @var ICampaignsUser */
	private $user;
	/** @var string */
	private $registeredAt;

	/**
	 * @param ICampaignsUser $user
	 * @param string $registeredAt Timestamp in the TS_UNIX format
	 */
	public function __construct( ICampaignsUser $user, string $registeredAt ) {
		$this->user = $user;
		$this->registeredAt = $registeredAt;
	}

	/**
	 * @return ICampaignsUser
	 */
	public function getUser(): ICampaignsUser {
		return $this->user;
	}

	/**
	 * @return string Timestamp in the TS_UNIX format
	 */
	public function getRegisteredAt(): string {
		return $this->registeredAt;
	}
}
