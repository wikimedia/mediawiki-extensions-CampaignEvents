<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Invitation;

use MediaWiki\Extension\CampaignEvents\Event\PageEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFactory;
use MediaWiki\Extension\CampaignEvents\MWEntity\InvalidEventPageException;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\Permissions\Authority;
use MediaWiki\Permissions\PermissionStatus;
use StatusValue;

/**
 * Service to create and store a new invitation list.
 */
class InvitationListGenerator {
	public const SERVICE_NAME = 'CampaignEventsInvitationListGenerator';

	public const INVITATION_LIST_NAME_MAXLENGTH_BYTES = 255;

	private PermissionChecker $permissionChecker;
	private CampaignsPageFactory $pageFactory;
	private PageEventLookup $pageEventLookup;
	private OrganizersStore $organizersStore;
	private CampaignsCentralUserLookup $centralUserLookup;
	private InvitationListStore $invitationListStore;
	private JobQueueGroup $jobQueueGroup;

	public function __construct(
		PermissionChecker $permissionChecker,
		CampaignsPageFactory $pageFactory,
		PageEventLookup $pageEventLookup,
		OrganizersStore $organizersStore,
		CampaignsCentralUserLookup $centralUserLookup,
		InvitationListStore $invitationListStore,
		JobQueueGroup $jobQueueGroup
	) {
		$this->permissionChecker = $permissionChecker;
		$this->pageFactory = $pageFactory;
		$this->pageEventLookup = $pageEventLookup;
		$this->organizersStore = $organizersStore;
		$this->centralUserLookup = $centralUserLookup;
		$this->invitationListStore = $invitationListStore;
		$this->jobQueueGroup = $jobQueueGroup;
	}

	/**
	 * @return StatusValue If good, the value shall be the ID of the invitation list.
	 */
	public function createIfAllowed(
		string $name,
		?string $eventPage,
		Worklist $worklist,
		Authority $performer
	): StatusValue {
		$permStatus = $this->authorizeCreation( $performer );
		if ( !$permStatus->isGood() ) {
			return $permStatus;
		}
		return $this->createUnsafe( $name, $eventPage, $worklist, $performer );
	}

	private function authorizeCreation( Authority $performer ): PermissionStatus {
		if ( !$this->permissionChecker->userCanUseInvitationLists( $performer ) ) {
			return PermissionStatus::newFatal( 'campaignevents-invitation-list-not-allowed' );
		}
		return PermissionStatus::newGood();
	}

	/**
	 * @return StatusValue If good, the value shall be the ID of the invitation list.
	 */
	public function createUnsafe(
		string $name,
		?string $eventPage,
		Worklist $worklist,
		Authority $performer
	): StatusValue {
		if ( trim( $name ) === '' ) {
			return StatusValue::newFatal( 'campaignevents-invitation-list-error-empty-name' );
		}

		if ( strlen( $name ) > self::INVITATION_LIST_NAME_MAXLENGTH_BYTES ) {
			return StatusValue::newFatal( 'campaignevents-generateinvitationlist-name-too-long' );
		}

		$eventID = null;
		if ( $eventPage !== null ) {
			$eventPageStatus = $this->validateEventPage( $eventPage, $performer );
			if ( !$eventPageStatus->isGood() ) {
				return $eventPageStatus;
			}
			$eventID = $eventPageStatus->getValue();
		}

		$user = $this->centralUserLookup->newFromAuthority( $performer );
		$listID = $this->invitationListStore->createInvitationList( $name, $eventID, $user );
		$this->invitationListStore->storeWorklist( $listID, $worklist );

		$findInviteesJob = new FindPotentialInviteesJob( [
			'list-id' => $listID,
			'serialized-worklist' => $worklist->toPlainArray()
		] );
		$this->jobQueueGroup->push( $findInviteesJob );

		return StatusValue::newGood( $listID );
	}

	/**
	 * @return StatusValue Can have fatal errors, or if good, the value shall be the event ID.
	 */
	public function validateEventPage( string $eventPage, Authority $performer ): StatusValue {
		try {
			$page = $this->pageFactory->newLocalExistingPageFromString( $eventPage );
		} catch ( InvalidEventPageException $_ ) {
			return StatusValue::newFatal( 'campaignevents-invitation-list-error-invalid-page' );
		}

		$event = $this->pageEventLookup->getRegistrationForPage( $page, PageEventLookup::GET_DIRECT );
		if ( !$event ) {
			return StatusValue::newFatal( 'campaignevents-invitation-list-error-invalid-page' );
		}
		if ( $event->isPast() ) {
			return StatusValue::newFatal( 'campaignevents-invitation-list-error-event-ended' );
		}

		$user = $this->centralUserLookup->newFromAuthority( $performer );
		if ( !$this->organizersStore->isEventOrganizer( $event->getID(), $user ) ) {
			return StatusValue::newFatal( 'campaignevents-invitation-list-error-not-organizer' );
		}

		return StatusValue::newGood( $event->getID() );
	}
}
