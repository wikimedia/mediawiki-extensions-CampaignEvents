<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Permissions;

use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsPage;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserBlockChecker;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;

class PermissionChecker {
	public const SERVICE_NAME = 'CampaignEventsPermissionChecker';

	public const CREATE_REGISTRATIONS_RIGHT = 'campaignevents-create-registration';

	/** @var UserBlockChecker */
	private $userBlockChecker;
	/** @var OrganizersStore */
	private $organizersStore;

	/**
	 * @param UserBlockChecker $userBlockChecker
	 * @param OrganizersStore $organizersStore
	 */
	public function __construct( UserBlockChecker $userBlockChecker, OrganizersStore $organizersStore ) {
		$this->userBlockChecker = $userBlockChecker;
		$this->organizersStore = $organizersStore;
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
	 * @param int $registrationID
	 * @return bool
	 */
	public function userCanEditRegistration( ICampaignsUser $user, int $registrationID ): bool {
		return $this->userCanCreateRegistrations( $user )
			&& $this->organizersStore->isEventOrganizer( $registrationID, $user );
	}

	/**
	 * NOTE: This should be kept in sync with the special page, which has its own ways of requiring login and unblock.
	 * @param ICampaignsUser $user
	 * @return bool
	 */
	public function userCanRegisterForEvents( ICampaignsUser $user ): bool {
		// TODO Do we need another user right for this?
		return $user->isRegistered() && !$this->userBlockChecker->isSitewideBlocked( $user );
	}
}
