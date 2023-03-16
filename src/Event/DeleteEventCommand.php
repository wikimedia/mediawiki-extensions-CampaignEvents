<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Event;

use MediaWiki\Extension\CampaignEvents\Event\Store\IEventStore;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsAuthority;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Permissions\PermissionStatus;
use StatusValue;

/**
 * Command object used for the deletion of event registrations.
 */
class DeleteEventCommand {
	public const SERVICE_NAME = 'CampaignEventsDeleteEventCommand';

	/** @var IEventStore */
	private $eventStore;
	/** @var PermissionChecker */
	private $permissionChecker;

	/**
	 * @param IEventStore $eventStore
	 * @param PermissionChecker $permissionChecker
	 */
	public function __construct(
		IEventStore $eventStore,
		PermissionChecker $permissionChecker
	) {
		$this->eventStore = $eventStore;
		$this->permissionChecker = $permissionChecker;
	}

	/**
	 * @param ExistingEventRegistration $registration
	 * @param ICampaignsAuthority $performer
	 * @return StatusValue If good, the value is true if the registration was deleted, false if it was already deleted.
	 *   Will be a PermissionStatus for permissions-related errors.
	 */
	public function deleteIfAllowed(
		ExistingEventRegistration $registration,
		ICampaignsAuthority $performer
	): StatusValue {
		$permStatus = $this->authorizeDeletion( $registration, $performer );
		if ( !$permStatus->isGood() ) {
			return $permStatus;
		}
		return $this->deleteUnsafe( $registration );
	}

	/**
	 * @param ExistingEventRegistration $registration
	 * @param ICampaignsAuthority $performer
	 * @return PermissionStatus
	 */
	private function authorizeDeletion(
		ExistingEventRegistration $registration,
		ICampaignsAuthority $performer
	): PermissionStatus {
		if ( !$this->permissionChecker->userCanDeleteRegistration( $performer, $registration->getID() ) ) {
			return PermissionStatus::newFatal( 'campaignevents-delete-not-allowed-registration' );
		}
		return PermissionStatus::newGood();
	}

	/**
	 * @param ExistingEventRegistration $registration
	 * @return StatusValue If good, the value is true if the registration was deleted, false if it was already deleted.
	 */
	public function deleteUnsafe( ExistingEventRegistration $registration ): StatusValue {
		return StatusValue::newGood( $this->eventStore->deleteRegistration( $registration ) );
	}
}
