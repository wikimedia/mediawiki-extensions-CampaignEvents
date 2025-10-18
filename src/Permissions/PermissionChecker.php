<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Permissions;

use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWPageProxy;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWPermissionsLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageAuthorLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Permissions\Authority;
use StatusValue;

class PermissionChecker {
	public const SERVICE_NAME = 'CampaignEventsPermissionChecker';

	public const ENABLE_REGISTRATIONS_RIGHT = 'campaignevents-enable-registration';
	public const ORGANIZE_EVENTS_RIGHT = 'campaignevents-organize-events';
	public const SEND_EVENTS_EMAIL_RIGHT = 'campaignevents-email-participants';
	public const VIEW_PRIVATE_PARTICIPANTS_RIGHT = 'campaignevents-view-private-participants';
	public const DELETE_REGISTRATION_RIGHT = 'campaignevents-delete-registration';
	public const GENERATE_INVITATION_LISTS_RIGHT = 'campaignevents-generate-invitation-lists';

	public function __construct(
		private readonly OrganizersStore $organizersStore,
		private readonly PageAuthorLookup $pageAuthorLookup,
		private readonly CampaignsCentralUserLookup $centralUserLookup,
		private readonly MWPermissionsLookup $permissionsLookup,
		private readonly ParticipantsStore $participantsStore,
	) {
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

	/**
	 * Returns whether the performer can delete all contributions for the given event.
	 *
	 * A user can delete all contributions if they are an organizer of the event.
	 */
	public function userCanDeleteAllContributions(
		Authority $performer,
		ExistingEventRegistration $event
	): bool {
		return $this->userCanEditRegistration( $performer, $event );
	}

	/**
	 * Returns whether the performer can delete a single contribution record for the given event.
	 *
	 * A user can delete a contribution if either:
	 * - is an organizer of the event (can delete all contributions), or
	 * - is the author of the contribution.
	 */
	public function userCanDeleteContribution(
		Authority $performer,
		ExistingEventRegistration $event,
		int $contributionAuthorCentralId
	): bool {
		// Check if user is named first
		if ( !$performer->isNamed() ) {
			return false;
		}

		try {
			$centralUser = $this->centralUserLookup->newFromAuthority( $performer );
		} catch ( UserNotGlobalException ) {
			return false;
		}

		return $this->userCanDeleteAllContributions( $performer, $event ) ||
			( $centralUser->getCentralID() === $contributionAuthorCentralId );
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
		return $performer->isNamed()
			&& $performer->isAllowed( self::GENERATE_INVITATION_LISTS_RIGHT )
			&& !$performer->getBlock()?->isSitewide();
	}

	/**
	 * Returns whether the performer can add a single contribution record to the given event.
	 *
	 * A user can add a contribution if either:
	 * - is an organizer of the event, or
	 * - is the author of the contribution.
	 */
	public function userCanAddAnyValidContribution(
		Authority $performer,
		ExistingEventRegistration $event
	): bool {
		return $this->userCanEditRegistration( $performer, $event );
	}

	/**
	 * Returns whether the performer can add a single contribution record to the given event.
	 *
	 * A user can add a contribution if either:
	 * - is an organizer of the event, or
	 * - is the author of the contribution.
	 */
	public function userCanAddContribution(
		Authority $performer,
		ExistingEventRegistration $event,
		int $contributionAuthorCentralId
	): StatusValue {
		try {
			$performerCentralUser = $this->centralUserLookup->newFromAuthority( $performer );
			$authorCentralUser = new CentralUser( $contributionAuthorCentralId );
		} catch ( UserNotGlobalException ) {
			return StatusValue::newFatal( 'campaignevents-event-contribution-user-not-global' );
		}
		$authorIsParticipant = $this->participantsStore->userParticipatesInEvent(
			$event->getID(),
			$authorCentralUser,
			true
		);
		if ( !$authorIsParticipant ) {
			return StatusValue::newFatal( 'campaignevents-event-contribution-not-participant' );
		}
		if ( $performerCentralUser->getCentralID() === $contributionAuthorCentralId ) {
			return StatusValue::newGood();
		}

		if ( $this->userCanAddAnyValidContribution( $performer, $event ) ) {
			return StatusValue::newGood();
		}
		return StatusValue::newFatal( 'campaignevents-event-contribution-not-owner' );
	}
}
