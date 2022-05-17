<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Participants;

use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsUser;
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

	/**
	 * @param ParticipantsStore $participantsStore
	 * @param PermissionChecker $permissionChecker
	 */
	public function __construct(
		ParticipantsStore $participantsStore,
		PermissionChecker $permissionChecker
	) {
		$this->participantsStore = $participantsStore;
		$this->permissionChecker = $permissionChecker;
	}

	/**
	 * @param ExistingEventRegistration $registration
	 * @param ICampaignsUser $performer
	 * @return StatusValue Good if everything went fine, fatal with errors otherwise. If good, the value shall be
	 *   true if the user was not already registered (or they deleted their registration), and false if they were
	 *   already actively registered. Will be a PermissionStatus for permissions-related errors.
	 */
	public function registerIfAllowed(
		ExistingEventRegistration $registration,
		ICampaignsUser $performer
	): StatusValue {
		$permStatus = $this->authorizeRegistration( $performer );
		if ( !$permStatus->isGood() ) {
			return $permStatus;
		}
		return $this->registerUnsafe( $registration, $performer );
	}

	/**
	 * @param ICampaignsUser $performer
	 * @return PermissionStatus
	 */
	private function authorizeRegistration( ICampaignsUser $performer ): PermissionStatus {
		if ( !$this->permissionChecker->userCanRegisterForEvents( $performer ) ) {
			return PermissionStatus::newFatal( 'campaignevents-register-not-allowed' );
		}
		return PermissionStatus::newGood();
	}

	/**
	 * @param ExistingEventRegistration $registration
	 * @param ICampaignsUser $performer
	 * @return StatusValue
	 */
	public function registerUnsafe( ExistingEventRegistration $registration, ICampaignsUser $performer ): StatusValue {
		$endTS = $registration->getEndTimestamp();
		if ( (int)$endTS < (int)MWTimestamp::now( TS_UNIX ) ) {
			return StatusValue::newFatal( 'campaignevents-register-event-past' );
		}
		if ( $registration->getStatus() !== EventRegistration::STATUS_OPEN ) {
			return StatusValue::newFatal( 'campaignevents-register-event-not-open' );
		}

		$modified = $this->participantsStore->addParticipantToEvent( $registration->getID(), $performer );
		return StatusValue::newGood( $modified );
	}
}
