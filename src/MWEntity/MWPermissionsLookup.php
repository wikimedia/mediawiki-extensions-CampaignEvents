<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

use InvalidArgumentException;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserNameUtils;

/**
 * This class can be used to perform permission checks on users other than the performer of the request (for that,
 * use Authority).
 */
class MWPermissionsLookup {

	public const SERVICE_NAME = 'CampaignEventsPermissionLookup';

	private UserFactory $userFactory;
	private UserNameUtils $userNameUtils;

	public function __construct(
		UserFactory $userFactory,
		UserNameUtils $userNameUtils
	) {
		$this->userFactory = $userFactory;
		$this->userNameUtils = $userNameUtils;
	}

	/**
	 * @param string $username Callers should make sure that the username is valid
	 * @param string $right
	 * @return bool
	 */
	public function userHasRight( string $username, string $right ): bool {
		return $this->getUser( $username )->isAllowed( $right );
	}

	/**
	 * @param string $username Callers should make sure that the username is valid
	 * @return bool
	 */
	public function userIsSitewideBlocked( string $username ): bool {
		$block = $this->getUser( $username )->getBlock();
		return $block && $block->isSitewide();
	}

	/**
	 * @param string $username Callers should make sure that the username is valid
	 * @return bool
	 */
	public function userIsNamed( string $username ): bool {
		return $this->getUser( $username )->isNamed();
	}

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
