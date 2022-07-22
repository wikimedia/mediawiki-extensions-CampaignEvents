<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

use MediaWiki\User\UserIdentity;

class MWUserProxy implements ICampaignsUser {
	/** @var UserIdentity */
	private $userIdentity;

	/**
	 * @param UserIdentity $identity
	 */
	public function __construct( UserIdentity $identity ) {
		$this->userIdentity = $identity;
	}

	/**
	 * @inheritDoc
	 */
	public function getLocalID(): int {
		return $this->userIdentity->getId();
	}

	/**
	 * @inheritDoc
	 */
	public function isRegistered(): bool {
		return $this->userIdentity->isRegistered();
	}

	/**
	 * @inheritDoc
	 */
	public function getName(): string {
		return $this->userIdentity->getName();
	}

	/**
	 * @inheritDoc
	 */
	public function equals( ICampaignsUser $other ): bool {
		// XXX This is not entirely correct given that our users are central. Fix together with T313133.
		return $this->getName() === $other->getName();
	}

	/**
	 * @return UserIdentity
	 */
	public function getUserIdentity(): UserIdentity {
		return $this->userIdentity;
	}
}
