<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Participants;

use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsUser;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Permissions\PermissionStatus;
use MWTimestamp;
use StatusValue;

class UnregisterParticipantCommand {
	public const SERVICE_NAME = 'CampaignEventsUnregisterParticipantCommand';

	/** @var ParticipantsStore */
	private $participantsStore;
	/** @var PermissionChecker */
	private $permissionChecker;

	/**
	 * @param ParticipantsStore $participantsStore
	 * @param PermissionChecker $permissionChecker
	 */
	public function __construct( ParticipantsStore $participantsStore, PermissionChecker $permissionChecker ) {
		$this->participantsStore = $participantsStore;
		$this->permissionChecker = $permissionChecker;
	}

	/**
	 * @param ExistingEventRegistration $registration
	 * @param ICampaignsUser $performer
	 * @return StatusValue Good if everything went fine, fatal with errors otherwise. If good, the value shall be
	 *   true if the user was actively registered, and false if they unregistered or had never registered.
	 *   Will be a PermissionStatus for permissions-related errors.
	 */
	public function unregisterIfAllowed(
		ExistingEventRegistration $registration,
		ICampaignsUser $performer
	): StatusValue {
		$permStatus = $this->authorizeUnregistration( $performer );
		if ( !$permStatus->isGood() ) {
			return $permStatus;
		}
		return $this->unregisterUnsafe( $registration, $performer );
	}

	/**
	 * @param ICampaignsUser $performer
	 * @return PermissionStatus
	 */
	private function authorizeUnregistration( ICampaignsUser $performer ): PermissionStatus {
		if ( !$this->permissionChecker->userCanUnregisterForEvents( $performer ) ) {
			return PermissionStatus::newFatal( 'campaignevents-unregister-not-allowed' );
		}
		return PermissionStatus::newGood();
	}

	/**
	 * Checks whether it's possible to cancel a registration for the given event.
	 * @param ExistingEventRegistration $registration
	 * @return bool
	 */
	public static function isUnregistrationAllowedForEvent( ExistingEventRegistration $registration ): bool {
		return self::checkIsUnregistrationAllowed( $registration )->isGood();
	}

	/**
	 * @param ExistingEventRegistration $registration
	 * @return StatusValue
	 */
	private static function checkIsUnregistrationAllowed( ExistingEventRegistration $registration ): StatusValue {
		if ( $registration->getDeletionTimestamp() !== null ) {
			return StatusValue::newFatal( 'campaignevents-unregister-registration-deleted' );
		}
		$endTS = $registration->getEndTimestamp();
		if ( (int)$endTS < (int)MWTimestamp::now( TS_UNIX ) ) {
			return StatusValue::newFatal( 'campaignevents-unregister-event-past' );
		}
		return StatusValue::newGood();
	}

	/**
	 * @param ExistingEventRegistration $registration
	 * @param ICampaignsUser $performer
	 * @return StatusValue
	 */
	public function unregisterUnsafe(
		ExistingEventRegistration $registration,
		ICampaignsUser $performer
	): StatusValue {
		$unregistrationAllowedStatus = self::checkIsUnregistrationAllowed( $registration );
		if ( !$unregistrationAllowedStatus->isGood() ) {
			return $unregistrationAllowedStatus;
		}

		$modified = $this->participantsStore->removeParticipantFromEvent( $registration->getID(), $performer );
		return StatusValue::newGood( $modified );
	}
}
