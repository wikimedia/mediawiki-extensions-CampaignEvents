<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

use InvalidArgumentException;
use MediaWiki\User\UserFactory;

class MWPermissionsLookup implements IPermissionsLookup {

	public const SERVICE_NAME = 'CampaignEventsPermissionLookup';

	/** @var UserFactory */
	private $userFactory;

	/**
	 * @param UserFactory $userFactory
	 */
	public function __construct(
		UserFactory $userFactory
	) {
		$this->userFactory = $userFactory;
	}

	/**
	 * @inheritDoc
	 */
	public function userHasRight( string $username, string $right ): bool {
		$user = $this->userFactory->newFromName( $username );
		if ( !$user ) {
			throw new InvalidArgumentException( "'$username' is not a valid username." );
		}
		return $user->isAllowed( $right );
	}

	/**
	 * @inheritDoc
	 */
	public function userIsSitewideBlocked( string $username ): bool {
		$user = $this->userFactory->newFromName( $username );
		if ( !$user ) {
			throw new InvalidArgumentException( "'$username' is not a valid username." );
		}
		$block = $user->getBlock();
		return $block && $block->isSitewide();
	}

	/**
	 * @inheritDoc
	 */
	public function userIsRegistered( string $username ): bool {
		$user = $this->userFactory->newFromName( $username );
		if ( !$user ) {
			throw new InvalidArgumentException( "'$username' is not a valid username." );
		}
		return $user->isRegistered();
	}
}
