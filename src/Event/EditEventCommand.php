<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Event;

use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsUser;
use MediaWiki\Extension\CampaignEvents\Organizers\Organizer;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\Store\IEventStore;
use MediaWiki\Permissions\PermissionStatus;
use StatusValue;

/**
 * Command object used for creation and editing of event registrations.
 */
class EditEventCommand {
	public const SERVICE_NAME = 'CampaignEventsEditEventCommand';

	/** @var IEventStore */
	private $eventStore;
	/** @var OrganizersStore */
	private $organizerStore;
	/** @var PermissionChecker */
	private $permissionChecker;

	/**
	 * @param IEventStore $eventStore
	 * @param OrganizersStore $organizersStore
	 * @param PermissionChecker $permissionChecker
	 */
	public function __construct(
		IEventStore $eventStore,
		OrganizersStore $organizersStore,
		PermissionChecker $permissionChecker
	) {
		$this->eventStore = $eventStore;
		$this->organizerStore = $organizersStore;
		$this->permissionChecker = $permissionChecker;
	}

	/**
	 * @param EventRegistration $registration
	 * @param ICampaignsUser $creator
	 * @return StatusValue If good, the value shall be the ID of the event. Will be a PermissionStatus for
	 *   permissions-related errors.
	 */
	public function doEditIfAllowed( EventRegistration $registration, ICampaignsUser $creator ): StatusValue {
		$permStatus = $this->authorizeEdit( $registration, $creator );
		if ( !$permStatus->isGood() ) {
			return $permStatus;
		}
		return $this->doEditUnsafe( $registration, $creator );
	}

	/**
	 * @param EventRegistration $registration
	 * @param ICampaignsUser $creator
	 * @return PermissionStatus
	 */
	private function authorizeEdit( EventRegistration $registration, ICampaignsUser $creator ): PermissionStatus {
		$isCreation = $registration->getID() === null;
		$eventPage = $registration->getPage();
		if ( $isCreation && !$this->permissionChecker->userCanCreateRegistration( $creator, $eventPage ) ) {
			return PermissionStatus::newFatal( 'campaignevents-create-not-allowed-page' );
		} elseif ( !$isCreation && !$this->permissionChecker->userCanEditRegistration( $creator, $eventPage ) ) {
			return PermissionStatus::newFatal( 'campaignevents-edit-not-allowed-page' );
		}
		return PermissionStatus::newGood();
	}

	/**
	 * @param EventRegistration $registration
	 * @param ICampaignsUser $creator
	 * @return StatusValue If good, the value shall be the ID of the event.
	 */
	public function doEditUnsafe( EventRegistration $registration, ICampaignsUser $creator ): StatusValue {
		$saveStatus = $this->eventStore->saveRegistration( $registration );
		if ( !$saveStatus->isGood() ) {
			return $saveStatus;
		}
		$eventID = $saveStatus->getValue();
		if ( $registration->getID() === null ) {
			$this->organizerStore->addOrganizerToEvent( $eventID, $creator, [ Organizer::ROLE_CREATOR ] );
		}
		return StatusValue::newGood( $eventID );
	}
}
