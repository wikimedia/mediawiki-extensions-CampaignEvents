<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Permissions;

use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsAuthority;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsPage;
use MediaWiki\Extension\CampaignEvents\MWEntity\IPermissionsLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageAuthorLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;

class PermissionChecker {
	public const SERVICE_NAME = 'CampaignEventsPermissionChecker';

	public const ENABLE_REGISTRATIONS_RIGHT = 'campaignevents-enable-registration';
	public const ORGANIZE_EVENTS_RIGHT = 'campaignevents-organize-events';

	/** @var OrganizersStore */
	private $organizersStore;
	/** @var PageAuthorLookup */
	private $pageAuthorLookup;
	/** @var CampaignsCentralUserLookup */
	private $centralUserLookup;
	/** @var IPermissionsLookup */
	private $permissionsLookup;

	/**
	 * @param OrganizersStore $organizersStore
	 * @param PageAuthorLookup $pageAuthorLookup
	 * @param CampaignsCentralUserLookup $centralUserLookup
	 * @param IPermissionsLookup $permissionsLookup
	 */
	public function __construct(
		OrganizersStore $organizersStore,
		PageAuthorLookup $pageAuthorLookup,
		CampaignsCentralUserLookup $centralUserLookup,
		IPermissionsLookup $permissionsLookup
	) {
		$this->organizersStore = $organizersStore;
		$this->pageAuthorLookup = $pageAuthorLookup;
		$this->centralUserLookup = $centralUserLookup;
		$this->permissionsLookup = $permissionsLookup;
	}

	/**
	 * NOTE: This should be kept in sync with the special page, which has its own ways of requiring login, unblock,
	 * and rights.
	 * @param ICampaignsAuthority $performer
	 * @return bool
	 */
	public function userCanEnableRegistrations( ICampaignsAuthority $performer ): bool {
		return $performer->isRegistered()
			&& $performer->hasRight( self::ENABLE_REGISTRATIONS_RIGHT )
			&& !$performer->isSitewideBlocked();
	}

	/**
	 * @param ICampaignsAuthority $performer
	 * @param ICampaignsPage $eventPage
	 * @return bool
	 */
	public function userCanEnableRegistration( ICampaignsAuthority $performer, ICampaignsPage $eventPage ): bool {
		if ( !$this->userCanEnableRegistrations( $performer ) ) {
			return false;
		}

		$pageAuthor = $this->pageAuthorLookup->getAuthor( $eventPage );
		if ( !$pageAuthor ) {
			return false;
		}

		try {
			$centralUser = $this->centralUserLookup->newFromAuthority( $performer );
		} catch ( UserNotGlobalException $_ ) {
			return false;
		}
		return $pageAuthor->equals( $centralUser );
	}

	/**
	 * @param string $username
	 * @return bool
	 */
	public function userCanOrganizeEvents( string $username ): bool {
		return $this->permissionsLookup->userIsRegistered( $username ) &&
			$this->permissionsLookup->userHasRight( $username, self::ORGANIZE_EVENTS_RIGHT ) &&
			!$this->permissionsLookup->userIsSitewideBlocked( $username );
	}

	/**
	 * @param ICampaignsAuthority $performer
	 * @param int $registrationID
	 * @return bool
	 */
	public function userCanEditRegistration( ICampaignsAuthority $performer, int $registrationID ): bool {
		if (
			!$this->userCanEnableRegistrations( $performer ) &&
			!$this->userCanOrganizeEvents( $performer->getName() )
		) {
			return false;
		}
		try {
			$centralUser = $this->centralUserLookup->newFromAuthority( $performer );
		} catch ( UserNotGlobalException $_ ) {
			return false;
		}
		return $this->organizersStore->isEventOrganizer( $registrationID, $centralUser );
	}

	/**
	 * @param ICampaignsAuthority $performer
	 * @param int $registrationID
	 * @return bool
	 */
	public function userCanDeleteRegistration( ICampaignsAuthority $performer, int $registrationID ): bool {
		return $this->userCanDeleteRegistrations( $performer ) ||
			$this->userCanEditRegistration( $performer, $registrationID );
	}

	/**
	 * @param ICampaignsAuthority $performer
	 * @return bool
	 */
	public function userCanDeleteRegistrations( ICampaignsAuthority $performer ): bool {
		return $performer->hasRight( 'campaignevents-delete-registration' ) &&
			!$performer->isSitewideBlocked();
	}

	/**
	 * NOTE: This should be kept in sync with the special page, which has its own ways of requiring login and unblock.
	 * @param ICampaignsAuthority $performer
	 * @return bool
	 */
	public function userCanRegisterForEvents( ICampaignsAuthority $performer ): bool {
		// TODO Do we need another user right for this?
		return $performer->isRegistered() && !$performer->isSitewideBlocked();
	}

	/**
	 * NOTE: This should be kept in sync with the special page, which has its own way of requiring login.
	 * @param ICampaignsAuthority $performer
	 * @return bool
	 */
	public function userCanUnregisterForEvents( ICampaignsAuthority $performer ): bool {
		// Note that blocked users can cancel their own registration, see T322380.
		return $performer->isRegistered();
	}

	/**
	 * @param ICampaignsAuthority $performer
	 * @param int $registrationID
	 * @return bool
	 */
	public function userCanRemoveParticipants( ICampaignsAuthority $performer, int $registrationID ): bool {
		return $this->userCanEditRegistration( $performer, $registrationID );
	}

	/**
	 * @param ICampaignsAuthority $performer
	 * @param int $eventID
	 * @return bool
	 */
	public function userCanViewPrivateParticipants( ICampaignsAuthority $performer, int $eventID ): bool {
		return $this->userCanEditRegistration( $performer, $eventID );
	}
}
