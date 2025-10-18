<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Event;

use MediaWiki\Extension\CampaignEvents\Event\Store\IEventStore;
use MediaWiki\Extension\CampaignEvents\EventPage\EventPageCacheUpdater;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolEventWatcher;
use MediaWiki\Permissions\Authority;
use MediaWiki\Permissions\PermissionStatus;
use StatusValue;

/**
 * Command object used for the deletion of event registrations.
 */
class DeleteEventCommand {
	public const SERVICE_NAME = 'CampaignEventsDeleteEventCommand';

	public const VALIDATE_TRACKING_TOOLS = true;
	public const SKIP_TRACKING_TOOL_VALIDATION = false;

	public function __construct(
		private readonly IEventStore $eventStore,
		private readonly PermissionChecker $permissionChecker,
		private readonly TrackingToolEventWatcher $trackingToolEventWatcher,
		private readonly EventPageCacheUpdater $eventPageCacheUpdater,
	) {
	}

	/**
	 * @return StatusValue If good, the value is true if the registration was deleted, false if it was already deleted.
	 *   Will be a PermissionStatus for permissions-related errors.
	 */
	public function deleteIfAllowed(
		ExistingEventRegistration $registration,
		Authority $performer
	): StatusValue {
		$permStatus = $this->authorizeDeletion( $registration, $performer );
		if ( !$permStatus->isGood() ) {
			return $permStatus;
		}
		return $this->deleteUnsafe( $registration );
	}

	private function authorizeDeletion(
		ExistingEventRegistration $registration,
		Authority $performer
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
