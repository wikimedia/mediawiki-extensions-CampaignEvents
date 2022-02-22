<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Permissions;

use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsPage;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsUser;

class PermissionChecker {
	public const SERVICE_NAME = 'CampaignEventsPermissionChecker';

	/**
	 * @param ICampaignsUser $user
	 * @return bool
	 */
	public function userCanCreateRegistrations( ICampaignsUser $user ): bool {
		return $user->hasRight( 'campaignevents-create-registration' );
	}

	/**
	 * @param ICampaignsUser $user
	 * @param ICampaignsPage $eventPage
	 * @return bool
	 */
	public function userCanCreateRegistration( ICampaignsUser $user, ICampaignsPage $eventPage ): bool {
		if ( !$this->userCanCreateRegistrations( $user ) ) {
			return false;
		}

		// TODO MVP: Check this for real
		$userCreatedEventPage = true;
		return $userCreatedEventPage;
	}
}
