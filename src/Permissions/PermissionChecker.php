<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Permissions;

use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWPageProxy;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWPermissionsLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageAuthorLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Permissions\Authority;

class PermissionChecker {
	public const SERVICE_NAME = 'CampaignEventsPermissionChecker';

	public const ENABLE_REGISTRATIONS_RIGHT = 'campaignevents-enable-registration';
	public const ORGANIZE_EVENTS_RIGHT = 'campaignevents-organize-events';
	public const SEND_EVENTS_EMAIL_RIGHT = 'campaignevents-email-participants';
	public const VIEW_PRIVATE_PARTICIPANTS_RIGHT = 'campaignevents-view-private-participants';
	public const DELETE_REGISTRATION_RIGHT = 'campaignevents-delete-registration';

	private OrganizersStore $organizersStore;
	private PageAuthorLookup $pageAuthorLookup;
	private CampaignsCentralUserLookup $centralUserLookup;
	private MWPermissionsLookup $permissionsLookup;

	public function __construct(
		OrganizersStore $organizersStore,
		PageAuthorLookup $pageAuthorLookup,
		CampaignsCentralUserLookup $centralUserLookup,
		MWPermissionsLookup $permissionsLookup
	) {
		$this->organizersStore = $organizersStore;
		$this->pageAuthorLookup = $pageAuthorLookup;
		$this->centralUserLookup = $centralUserLookup;
		$this->permissionsLookup = $permissionsLookup;
	}

	/**
	 * NOTE: This should be kept in sync with the special page, which has its own ways of requiring named account,
	 * unblock, and rights.
	 */
	public function userCanEnableRegistrations( Authority $performer ): bool {
		return $performer->isNamed()
			&& $performer->isAllowed( self::ENABLE_REGISTRATIONS_RIGHT )
			&& !$performer->getBlock()?->isSitewide();
	}

	public function userCanEnableRegistration( Authority $performer, MWPageProxy $eventPage ): bool {
		if ( !$this->userCanEnableRegistrations( $performer ) ) {
			return false;
		}

		$pageAuthor = $this->pageAuthorLookup->getAuthor( $eventPage );
		if ( !$pageAuthor ) {
			return false;
		}

		try {
			$centralUser = $this->centralUserLookup->newFromAuthority( $performer );
		} catch ( UserNotGlobalException ) {
			return false;
		}
		return $pageAuthor->equals( $centralUser );
	}

	public function userCanOrganizeEvents( string $username ): bool {
		return $this->permissionsLookup->userIsNamed( $username ) &&
			$this->permissionsLookup->userHasRight( $username, self::ORGANIZE_EVENTS_RIGHT ) &&
			!$this->permissionsLookup->userIsSitewideBlocked( $username );
	}

	public function userCanEditRegistration( Authority $performer, ExistingEventRegistration $event ): bool {
		if (
			!$event->isOnLocalWiki() ||
			(
				!$this->userCanEnableRegistrations( $performer ) &&
				!$this->userCanOrganizeEvents( $performer->getUser()->getName() )
			)
		) {
			return false;
		}
		try {
			$centralUser = $this->centralUserLookup->newFromAuthority( $performer );
		} catch ( UserNotGlobalException ) {
			return false;
		}
		$eventID = $event->getID();
		if ( $eventID ) {
			return $this->organizersStore->isEventOrganizer( $eventID, $centralUser );
		}
		return false;
	}

	public function userCanDeleteRegistration(
		Authority $performer,
		ExistingEventRegistration $event
	): bool {
		return $event->isOnLocalWiki() && (
				$this->userCanDeleteRegistrations( $performer ) ||
				$this->userCanEditRegistration( $performer, $event )
			);
	}

	public function userCanDeleteRegistrations( Authority $performer ): bool {
		return $performer->isNamed() &&
			$performer->isAllowed( self::DELETE_REGISTRATION_RIGHT ) &&
			!$performer->getBlock()?->isSitewide();
	}

	/**
	 * NOTE: This should be kept in sync with the special page, which has its own ways of requiring named account
	 * and unblock.
	 */
	public function userCanRegisterForEvent( Authority $performer, ExistingEventRegistration $event ): bool {
		// TODO Do we need another user right for this?
		return $event->isOnLocalWiki() && $performer->isNamed() && !$performer->getBlock()?->isSitewide();
	}

	/**
	 * NOTE: This should be kept in sync with the special page, which has its own way of requiring a named account.
	 */
	public function userCanCancelRegistration( Authority $performer ): bool {
		// Note that blocked users can cancel their own registration, see T322380.
		return $performer->isNamed();
	}

	public function userCanRemoveParticipants(
		Authority $performer,
		ExistingEventRegistration $event
	): bool {
		return $this->userCanEditRegistration( $performer, $event );
	}

	public function userCanViewPrivateParticipants(
		Authority $performer,
		ExistingEventRegistration $event
	): bool {
		return $this->userCanEditRegistration( $performer, $event ) ||
			( $event->isOnLocalWiki()
				&& $performer->isNamed()
				&& $performer->isAllowed( self::VIEW_PRIVATE_PARTICIPANTS_RIGHT )
				&& !$performer->getBlock()?->isSitewide() );
	}

	public function userCanViewSensitiveEventData( Authority $performer ): bool {
		return !$performer->getBlock()?->isSitewide();
	}

	public function userCanViewNonPIIParticipantsData(
		Authority $performer,
		ExistingEventRegistration $event
	): bool {
		return $this->userCanEditRegistration( $performer, $event );
	}

	public function userCanViewAggregatedAnswers(
		Authority $performer,
		ExistingEventRegistration $event
	): bool {
		return $this->userCanEditRegistration( $performer, $event );
	}

	public function userCanEmailParticipants( Authority $performer, ExistingEventRegistration $event ): bool {
		return $this->userCanEditRegistration( $performer, $event )
			&& $performer->isAllowed( self::SEND_EVENTS_EMAIL_RIGHT );
	}

	public function userCanUseInvitationLists( Authority $performer ): bool {
		return $this->userCanOrganizeEvents( $performer->getUser()->getName() ) ||
			$this->userCanEnableRegistrations( $performer );
	}
}
