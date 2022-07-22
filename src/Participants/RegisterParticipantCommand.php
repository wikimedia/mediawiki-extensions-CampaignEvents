<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Participants;

use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsAuthority;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Permissions\PermissionStatus;
use MWTimestamp;
use StatusValue;

class RegisterParticipantCommand {
	public const SERVICE_NAME = 'CampaignEventsRegisterParticipantCommand';

	/** @var ParticipantsStore */
	private $participantsStore;
	/** @var PermissionChecker */
	private $permissionChecker;
	/** @var CampaignsCentralUserLookup */
	private $centralUserLookup;

	/**
	 * @param ParticipantsStore $participantsStore
	 * @param PermissionChecker $permissionChecker
	 * @param CampaignsCentralUserLookup $centralUserLookup
	 */
	public function __construct(
		ParticipantsStore $participantsStore,
		PermissionChecker $permissionChecker,
		CampaignsCentralUserLookup $centralUserLookup
	) {
		$this->participantsStore = $participantsStore;
		$this->permissionChecker = $permissionChecker;
		$this->centralUserLookup = $centralUserLookup;
	}

	/**
	 * @param ExistingEventRegistration $registration
	 * @param ICampaignsAuthority $performer
	 * @return StatusValue Good if everything went fine, fatal with errors otherwise. If good, the value shall be
	 *   true if the user was not already registered (or they deleted their registration), and false if they were
	 *   already actively registered. Will be a PermissionStatus for permissions-related errors.
	 */
	public function registerIfAllowed(
		ExistingEventRegistration $registration,
		ICampaignsAuthority $performer
	): StatusValue {
		$permStatus = $this->authorizeRegistration( $performer );
		if ( !$permStatus->isGood() ) {
			return $permStatus;
		}
		return $this->registerUnsafe( $registration, $performer );
	}

	/**
	 * @param ICampaignsAuthority $performer
	 * @return PermissionStatus
	 */
	private function authorizeRegistration( ICampaignsAuthority $performer ): PermissionStatus {
		if ( !$this->permissionChecker->userCanRegisterForEvents( $performer ) ) {
			return PermissionStatus::newFatal( 'campaignevents-register-not-allowed' );
		}
		return PermissionStatus::newGood();
	}

	/**
	 * Checks whether it's possible to register for the given event.
	 * @param ExistingEventRegistration $registration
	 * @return bool
	 */
	public static function isRegistrationAllowedForEvent( ExistingEventRegistration $registration ): bool {
		return self::checkIsRegistrationAllowed( $registration )->isGood();
	}

	/**
	 * @param ExistingEventRegistration $registration
	 * @return StatusValue
	 */
	private static function checkIsRegistrationAllowed( ExistingEventRegistration $registration ): StatusValue {
		if ( $registration->getDeletionTimestamp() !== null ) {
			return StatusValue::newFatal( 'campaignevents-register-registration-deleted' );
		}
		$endTS = $registration->getEndTimestamp();
		if ( (int)$endTS < (int)MWTimestamp::now( TS_UNIX ) ) {
			return StatusValue::newFatal( 'campaignevents-register-event-past' );
		}
		if ( $registration->getStatus() !== EventRegistration::STATUS_OPEN ) {
			return StatusValue::newFatal( 'campaignevents-register-event-not-open' );
		}
		return StatusValue::newGood();
	}

	/**
	 * @param ExistingEventRegistration $registration
	 * @param ICampaignsAuthority $performer
	 * @return StatusValue
	 */
	public function registerUnsafe(
		ExistingEventRegistration $registration,
		ICampaignsAuthority $performer
	): StatusValue {
		$registrationAllowedStatus = self::checkIsRegistrationAllowed( $registration );
		if ( !$registrationAllowedStatus->isGood() ) {
			return $registrationAllowedStatus;
		}

		$centralUser = $this->centralUserLookup->newFromAuthority( $performer );
		$modified = $this->participantsStore->addParticipantToEvent( $registration->getID(), $centralUser );
		return StatusValue::newGood( $modified );
	}
}
