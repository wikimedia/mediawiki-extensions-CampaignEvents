<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

use MediaWiki\Permissions\Authority;
use MediaWiki\User\UserIdentity;

class MWUserProxy implements ICampaignsUser {
	/** @var UserIdentity */
	private $userIdentity;
	/** @var Authority */
	private $authority;

	/**
	 * @param UserIdentity $identity
	 * @param Authority $authority
	 */
	public function __construct( UserIdentity $identity, Authority $authority ) {
		$this->userIdentity = $identity;
		$this->authority = $authority;
	}

	/**
	 * @inheritDoc
	 */
	public function getId(): int {
		return $this->userIdentity->getId();
	}

	/**
	 * @inheritDoc
	 */
	public function hasRight( string $right ): bool {
		return $this->authority->isAllowed( $right );
	}
}
