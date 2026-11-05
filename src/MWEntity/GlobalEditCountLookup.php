<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

use MediaWiki\Extension\CentralAuth\CentralAuthEditCounter;
use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\User\UserEditTracker;
use MediaWiki\User\UserIdentity;

/**
 * Gives the number of edits of a user on all the wikis.
 *
 * When CentralAuth is installed, the count is the global edit count of the account. The counts of
 * each wiki are not added up. Only the count that CentralAuth already stores is read, from a
 * replica. Thus a page view does not cause CentralAuth to calculate the count again and write it
 * to the primary database.
 *
 * The count is the edit count on the local wiki when:
 * - CentralAuth is not installed. Then the edit counter is null.
 * - The user has no global account.
 * - CentralAuth does not store a count for the user yet.
 */
class GlobalEditCountLookup {
	public const SERVICE_NAME = 'CampaignEventsGlobalEditCountLookup';

	public function __construct(
		private readonly UserEditTracker $userEditTracker,
		private readonly ?CentralAuthEditCounter $centralAuthEditCounter,
	) {
	}

	public function getGlobalEditCount( UserIdentity $user ): int {
		if ( $this->centralAuthEditCounter ) {
			$centralUser = $this->getCentralAuthUser( $user );
			if ( $centralUser->exists() ) {
				$count = $this->centralAuthEditCounter->getCountIfInitialized( $centralUser );
				if ( $count !== null ) {
					return $count;
				}
			}
		}
		return $this->userEditTracker->getUserEditCount( $user ) ?? 0;
	}

	/**
	 * Protected, so that tests can replace it.
	 */
	protected function getCentralAuthUser( UserIdentity $user ): CentralAuthUser {
		return CentralAuthUser::getInstance( $user );
	}
}
