<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Event;

use MediaWiki\Extension\CampaignEvents\Event\Store\IEventStore;
use MediaWiki\Extension\CampaignEvents\EventPage\EventPageCacheUpdater;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsAuthority;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolEventWatcher;
use MediaWiki\Permissions\PermissionStatus;
use StatusValue;

/**
 * Command object used for the deletion of event registrations.
 */
class DeleteEventCommand {
	public const SERVICE_NAME = 'CampaignEventsDeleteEventCommand';

	public const VALIDATE_TRACKING_TOOLS = true;
	public const SKIP_TRACKING_TOOL_VALIDATION = false;

	private IEventStore $eventStore;
	private PermissionChecker $permissionChecker;
	private TrackingToolEventWatcher $trackingToolEventWatcher;
	private EventPageCacheUpdater $eventPageCacheUpdater;

	public function __construct(
		IEventStore $eventStore,
		PermissionChecker $permissionChecker,
		TrackingToolEventWatcher $trackingToolEventWatcher,
		EventPageCacheUpdater $eventPageCacheUpdater
	) {
		$this->eventStore = $eventStore;
		$this->permissionChecker = $permissionChecker;
		$this->trackingToolEventWatcher = $trackingToolEventWatcher;
		$this->eventPageCacheUpdater = $eventPageCacheUpdater;
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

	private function authorizeDeletion(
		ExistingEventRegistration $registration,
		ICampaignsAuthority $performer
	): PermissionStatus {
		if ( !$this->permissionChecker->userCanDeleteRegistration( $performer, $registration ) ) {
			return PermissionStatus::newFatal( 'campaignevents-delete-not-allowed-registration' );
		}
		return PermissionStatus::newGood();
	}

	/**
	 * @param ExistingEventRegistration $registration
	 * @param bool $trackingToolsValidation self::VALIDATE_TRACKING_TOOLS or self::SKIP_TRACKING_TOOL_VALIDATION
	 * @return StatusValue If good, the value is true if the registration was deleted, false if it was already deleted.
	 */
	public function deleteUnsafe(
		ExistingEventRegistration $registration,
		bool $trackingToolsValidation = self::VALIDATE_TRACKING_TOOLS
	): StatusValue {
		if ( $trackingToolsValidation === self::VALIDATE_TRACKING_TOOLS ) {
			$trackingToolValidationStatus = $this->trackingToolEventWatcher->validateEventDeletion( $registration );
			if ( !$trackingToolValidationStatus->isGood() ) {
				return $trackingToolValidationStatus;
			}
		}
		$effectivelyDeleted = $this->eventStore->deleteRegistration( $registration );

		if ( $effectivelyDeleted ) {
			$this->trackingToolEventWatcher->onEventDeleted( $registration );
			$this->eventPageCacheUpdater->purgeEventPageCache( $registration );
		}
		return StatusValue::newGood( $effectivelyDeleted );
	}
}
