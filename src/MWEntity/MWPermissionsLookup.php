<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

use InvalidArgumentException;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserNameUtils;
use User;

class MWPermissionsLookup implements IPermissionsLookup {

	public const SERVICE_NAME = 'CampaignEventsPermissionLookup';

	/** @var UserFactory */
	private UserFactory $userFactory;
	/** @var UserNameUtils */
	private UserNameUtils $userNameUtils;

	/**
	 * @param UserFactory $userFactory
	 * @param UserNameUtils $userNameUtils
	 */
	public function __construct(
		UserFactory $userFactory,
		UserNameUtils $userNameUtils
	) {
		$this->userFactory = $userFactory;
		$this->userNameUtils = $userNameUtils;
	}

	/**
	 * @inheritDoc
	 */
	public function userHasRight( string $username, string $right ): bool {
		return $this->getUser( $username )->isAllowed( $right );
	}

	/**
	 * @inheritDoc
	 */
	public function userIsSitewideBlocked( string $username ): bool {
		$block = $this->getUser( $username )->getBlock();
		return $block && $block->isSitewide();
	}

	/**
	 * @inheritDoc
	 */
	public function userIsRegistered( string $username ): bool {
		return $this->getUser( $username )->isRegistered();
	}

	/**
	 * @param string $username
	 * @return User
	 */
	private function getUser( string $username ): User {
		if ( $this->userNameUtils->isIP( $username ) ) {
			return $this->userFactory->newAnonymous( $username );
		}

		$user = $this->userFactory->newFromName( $username );
		if ( !$user ) {
			throw new InvalidArgumentException( "'$username' is not a valid username." );
		}
		return $user;
	}
}