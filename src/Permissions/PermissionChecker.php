<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Permissions;

use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsPage;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageAuthorLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserBlockChecker;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;

class PermissionChecker {
	public const SERVICE_NAME = 'CampaignEventsPermissionChecker';

	public const ENABLE_REGISTRATIONS_RIGHT = 'campaignevents-enable-registration';

	/** @var UserBlockChecker */
	private $userBlockChecker;
	/** @var OrganizersStore */
	private $organizersStore;
	/** @var PageAuthorLookup */
	private $pageAuthorLookup;

	/**
	 * @param UserBlockChecker $userBlockChecker
	 * @param OrganizersStore $organizersStore
	 * @param PageAuthorLookup $pageAuthorLookup
	 */
	public function __construct(
		UserBlockChecker $userBlockChecker,
		OrganizersStore $organizersStore,
		PageAuthorLookup $pageAuthorLookup
	) {
		$this->userBlockChecker = $userBlockChecker;
		$this->organizersStore = $organizersStore;
		$this->pageAuthorLookup = $pageAuthorLookup;
	}

	/**
	 * NOTE: This should be kept in sync with the special page, which has its own ways of requiring login, unblock,
	 * and rights.
	 * @param ICampaignsUser $user
	 * @return bool
	 */
	public function userCanEnableRegistrations( ICampaignsUser $user ): bool {
		return $user->isRegistered()
			&& $user->hasRight( self::ENABLE_REGISTRATIONS_RIGHT )
			&& !$this->userBlockChecker->isSitewideBlocked( $user );
	}

	/**
	 * @param ICampaignsUser $user
	 * @param ICampaignsPage $eventPage
	 * @return bool
	 */
	public function userCanEnableRegistration( ICampaignsUser $user, ICampaignsPage $eventPage ): bool {
		if ( !$this->userCanEnableRegistrations( $user ) ) {
			return false;
		}

		$pageAuthor = $this->pageAuthorLookup->getAuthor( $eventPage );
		return $pageAuthor && $pageAuthor->equals( $user );
	}

	/**
	 * @param ICampaignsUser $user
	 * @param int $registrationID
	 * @return bool
	 */
	public function userCanEditRegistration( ICampaignsUser $user, int $registrationID ): bool {
		return $this->userCanEnableRegistrations( $user )
			&& $this->organizersStore->isEventOrganizer( $registrationID, $user );
	}

	/**
	 * @param ICampaignsUser $user
	 * @param int $registrationID
	 * @return bool
	 */
	public function userCanDeleteRegistration( ICampaignsUser $user, int $registrationID ): bool {
		return $this->userCanDeleteRegistrations( $user ) ||
			$this->userCanEditRegistration( $user, $registrationID );
	}

	/**
	 * @param ICampaignsUser $user
	 * @return bool
	 */
	public function userCanDeleteRegistrations( ICampaignsUser $user ): bool {
		return $user->hasRight( 'campaignevents-delete-registration' ) &&
			!$this->userBlockChecker->isSitewideBlocked( $user );
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

	/**
	 * NOTE: This should be kept in sync with the special page, which has its own way of requiring login.
	 * @param ICampaignsUser $user
	 * @return bool
	 */
	public function userCanUnregisterForEvents( ICampaignsUser $user ): bool {
		return $user->isRegistered();
	}

	/**
	 * @param ICampaignsUser $user
	 * @param int $registrationID
	 * @return bool
	 */
	public function userCanRemoveParticipants( ICampaignsUser $user, int $registrationID ): bool {
		return $user->isRegistered() && $this->organizersStore->isEventOrganizer( $registrationID, $user ) &&
			!$this->userBlockChecker->isSitewideBlocked( $user );
	}
}
