<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Permissions;

use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
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
	public const SEND_EVENTS_EMAIL_RIGHT = 'campaignevents-email-participants';

	private OrganizersStore $organizersStore;
	private PageAuthorLookup $pageAuthorLookup;
	private CampaignsCentralUserLookup $centralUserLookup;
	private IPermissionsLookup $permissionsLookup;

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
	 * NOTE: This should be kept in sync with the special page, which has its own ways of requiring named account,
	 * unblock, and rights.
	 * @param ICampaignsAuthority $performer
	 * @return bool
	 */
	public function userCanEnableRegistrations( ICampaignsAuthority $performer ): bool {
		return $performer->isNamed()
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
		return $this->permissionsLookup->userIsNamed( $username ) &&
			$this->permissionsLookup->userHasRight( $username, self::ORGANIZE_EVENTS_RIGHT ) &&
			!$this->permissionsLookup->userIsSitewideBlocked( $username );
	}

	/**
	 * @param ICampaignsAuthority $performer
	 * @param ExistingEventRegistration $event
	 * @return bool
	 */
	public function userCanEditRegistration( ICampaignsAuthority $performer, ExistingEventRegistration $event ): bool {
		if (
			!$event->isOnLocalWiki() ||
			(
				!$this->userCanEnableRegistrations( $performer ) &&
				!$this->userCanOrganizeEvents( $performer->getName() )
			)
		) {
			return false;
		}
		try {
			$centralUser = $this->centralUserLookup->newFromAuthority( $performer );
		} catch ( UserNotGlobalException $_ ) {
			return false;
		}
		$eventID = $event->getID();
		if ( $eventID ) {
			return $this->organizersStore->isEventOrganizer( $eventID, $centralUser );
		}
		return false;
	}

	/**
	 * @param ICampaignsAuthority $performer
	 * @param ExistingEventRegistration $event
	 * @return bool
	 */
	public function userCanDeleteRegistration(
		ICampaignsAuthority $performer,
		ExistingEventRegistration $event
	): bool {
		return $event->isOnLocalWiki() &&
			$this->userCanDeleteRegistrations( $performer ) ||
			$this->userCanEditRegistration( $performer, $event );
	}

	/**
	 * @param ICampaignsAuthority $performer
	 * @return bool
	 */
	public function userCanDeleteRegistrations( ICampaignsAuthority $performer ): bool {
		return $performer->isNamed() &&
			$performer->hasRight( 'campaignevents-delete-registration' ) &&
			!$performer->isSitewideBlocked();
	}

	/**
	 * NOTE: This should be kept in sync with the special page, which has its own ways of requiring named account
	 * and unblock.
	 * @param ICampaignsAuthority $performer
	 * @param ExistingEventRegistration $event
	 * @return bool
	 */
	public function userCanRegisterForEvent( ICampaignsAuthority $performer, ExistingEventRegistration $event ): bool {
		// TODO Do we need another user right for this?
		return $event->isOnLocalWiki() && $performer->isNamed() && !$performer->isSitewideBlocked();
	}

	/**
	 * NOTE: This should be kept in sync with the special page, which has its own way of requiring a named account.
	 * @param ICampaignsAuthority $performer
	 * @return bool
	 */
	public function userCanCancelRegistration( ICampaignsAuthority $performer ): bool {
		// Note that blocked users can cancel their own registration, see T322380.
		return $performer->isNamed();
	}

	/**
	 * @param ICampaignsAuthority $performer
	 * @param ExistingEventRegistration $event
	 * @return bool
	 */
	public function userCanRemoveParticipants(
		ICampaignsAuthority $performer,
		ExistingEventRegistration $event
	): bool {
		return $this->userCanEditRegistration( $performer, $event );
	}

	/**
	 * @param ICampaignsAuthority $performer
	 * @param ExistingEventRegistration $event
	 * @return bool
	 */
	public function userCanViewPrivateParticipants(
		ICampaignsAuthority $performer,
		ExistingEventRegistration $event
	): bool {
		return $this->userCanEditRegistration( $performer, $event );
	}

	/**
	 * @param ICampaignsAuthority $performer
	 * @return bool
	 */
	public function userCanViewSensitiveEventData( ICampaignsAuthority $performer ): bool {
		return !$performer->isSitewideBlocked();
	}

	/**
	 * @param ICampaignsAuthority $performer
	 * @param ExistingEventRegistration $event
	 * @return bool
	 */
	public function userCanViewNonPIIParticipantsData(
		ICampaignsAuthority $performer,
		ExistingEventRegistration $event
	): bool {
		return $this->userCanEditRegistration( $performer, $event );
	}

	/**
	 * @param ICampaignsAuthority $performer
	 * @param ExistingEventRegistration $event
	 * @return bool
	 */
	public function userCanEmailParticipants( ICampaignsAuthority $performer, ExistingEventRegistration $event ): bool {
		return $this->userCanEditRegistration( $performer, $event )
			&& $performer->hasRight( self::SEND_EVENTS_EMAIL_RIGHT );
	}
}
