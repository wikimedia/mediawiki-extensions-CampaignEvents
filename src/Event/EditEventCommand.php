<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Event;

use MediaWiki\Extension\CampaignEvents\Event\Store\EventNotFoundException;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventStore;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsUser;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Organizers\Roles;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Permissions\PermissionStatus;
use StatusValue;

/**
 * Command object used for creation and editing of event registrations.
 */
class EditEventCommand {
	public const SERVICE_NAME = 'CampaignEventsEditEventCommand';

	/** @var IEventStore */
	private $eventStore;
	/** @var IEventLookup */
	private $eventLookup;
	/** @var OrganizersStore */
	private $organizerStore;
	/** @var PermissionChecker */
	private $permissionChecker;

	/**
	 * @param IEventStore $eventStore
	 * @param IEventLookup $eventLookup
	 * @param OrganizersStore $organizersStore
	 * @param PermissionChecker $permissionChecker
	 */
	public function __construct(
		IEventStore $eventStore,
		IEventLookup $eventLookup,
		OrganizersStore $organizersStore,
		PermissionChecker $permissionChecker
	) {
		$this->eventStore = $eventStore;
		$this->eventLookup = $eventLookup;
		$this->organizerStore = $organizersStore;
		$this->permissionChecker = $permissionChecker;
	}

	/**
	 * @param EventRegistration $registration
	 * @param ICampaignsUser $performer
	 * @return StatusValue If good, the value shall be the ID of the event. Will be a PermissionStatus for
	 *   permissions-related errors.
	 */
	public function doEditIfAllowed( EventRegistration $registration, ICampaignsUser $performer ): StatusValue {
		$permStatus = $this->authorizeEdit( $registration, $performer );
		if ( !$permStatus->isGood() ) {
			return $permStatus;
		}
		return $this->doEditUnsafe( $registration, $performer );
	}

	/**
	 * @param EventRegistration $registration
	 * @param ICampaignsUser $performer
	 * @return PermissionStatus
	 */
	private function authorizeEdit( EventRegistration $registration, ICampaignsUser $performer ): PermissionStatus {
		$registrationID = $registration->getID();
		$isCreation = $registrationID === null;
		$eventPage = $registration->getPage();
		if ( $isCreation && !$this->permissionChecker->userCanEnableRegistration( $performer, $eventPage ) ) {
			return PermissionStatus::newFatal( 'campaignevents-enable-registration-not-allowed-page' );
		} elseif ( !$isCreation && !$this->permissionChecker->userCanEditRegistration( $performer, $registrationID ) ) {
			// @phan-suppress-previous-line PhanTypeMismatchArgumentNullable
			return PermissionStatus::newFatal( 'campaignevents-edit-not-allowed-registration' );
		}
		return PermissionStatus::newGood();
	}

	/**
	 * @param EventRegistration $registration
	 * @param ICampaignsUser $performer
	 * @return StatusValue If good, the value shall be the ID of the event.
	 */
	public function doEditUnsafe( EventRegistration $registration, ICampaignsUser $performer ): StatusValue {
		try {
			$existingRegistrationForPage = $this->eventLookup->getEventByPage( $registration->getPage() );
			if ( $existingRegistrationForPage->getID() !== $registration->getID() ) {
				$msg = $existingRegistrationForPage->getDeletionTimestamp() !== null
					? 'campaignevents-error-page-already-registered-deleted'
					: 'campaignevents-error-page-already-registered';
				return StatusValue::newFatal( $msg );
			}
			if ( $existingRegistrationForPage->getDeletionTimestamp() !== null ) {
				return StatusValue::newFatal( 'campaignevents-edit-registration-deleted' );
			}
		} catch ( EventNotFoundException $_ ) {
			// The page has no associated registration, and we're creating one now. No problem.
		}

		$saveStatus = $this->eventStore->saveRegistration( $registration );
		if ( !$saveStatus->isGood() ) {
			return $saveStatus;
		}
		$eventID = $saveStatus->getValue();
		if ( $registration->getID() === null ) {
			$this->organizerStore->addOrganizerToEvent( $eventID, $performer, [ Roles::ROLE_CREATOR ] );
		}
		return StatusValue::newGood( $eventID );
	}
}
