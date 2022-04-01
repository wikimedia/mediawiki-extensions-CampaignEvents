<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Permissions;

use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsPage;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsUser;

class PermissionChecker {
	public const SERVICE_NAME = 'CampaignEventsPermissionChecker';

	private const CREATE_REGISTRATIONS_RIGHT = 'campaignevents-create-registration';

	/**
	 * @param ICampaignsUser $user
	 * @return bool
	 */
	public function userCanCreateRegistrations( ICampaignsUser $user ): bool {
		// TODO Should this also check whether the user is blocked?
		return $user->hasRight( self::CREATE_REGISTRATIONS_RIGHT );
	}

	/**
	 * @return string
	 */
	public function getCreateRegistrationsRight(): string {
		return self::CREATE_REGISTRATIONS_RIGHT;
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

	/**
	 * @param ICampaignsUser $user
	 * @param ICampaignsPage $eventPage
	 * @return bool
	 */
	public function userCanEditRegistration( ICampaignsUser $user, ICampaignsPage $eventPage ): bool {
		return $this->userCanCreateRegistration( $user, $eventPage );
	}

	/**
	 * @param ICampaignsUser $user
	 * @return bool
	 */
	public function userCanRegisterForEvents( ICampaignsUser $user ): bool {
		// TODO Do we need another user right for this? And should this also check whether the user is blocked?
		return $user->isRegistered();
	}
}
