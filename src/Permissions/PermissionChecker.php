<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Permissions;

use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsPage;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserBlockChecker;

class PermissionChecker {
	public const SERVICE_NAME = 'CampaignEventsPermissionChecker';

	public const CREATE_REGISTRATIONS_RIGHT = 'campaignevents-create-registration';

	/** @var UserBlockChecker */
	private $userBlockChecker;

	/**
	 * @param UserBlockChecker $userBlockChecker
	 */
	public function __construct( UserBlockChecker $userBlockChecker ) {
		$this->userBlockChecker = $userBlockChecker;
	}

	/**
	 * NOTE: This should be kept in sync with the special page, which has its own ways of requiring login, unblock,
	 * and rights.
	 * @param ICampaignsUser $user
	 * @return bool
	 */
	public function userCanCreateRegistrations( ICampaignsUser $user ): bool {
		return $user->isRegistered()
			&& $user->hasRight( self::CREATE_REGISTRATIONS_RIGHT )
			&& !$this->userBlockChecker->isSitewideBlocked( $user );
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
