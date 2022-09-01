<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Participants;

use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsAuthority;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Permissions\PermissionStatus;
use MWTimestamp;
use StatusValue;
use UnexpectedValueException;

class RegisterParticipantCommand {
	public const SERVICE_NAME = 'CampaignEventsRegisterParticipantCommand';

	public const CAN_REGISTER = 0;
	public const CANNOT_REGISTER_DELETED = 1;
	public const CANNOT_REGISTER_ENDED = 2;
	public const CANNOT_REGISTER_CLOSED = 3;

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
	 * @param ExistingEventRegistration $registration
	 * @return int self::CAN_REGISTER or one of the self::CANNOT_REGISTER_* constants
	 */
	public static function checkIsRegistrationAllowed( ExistingEventRegistration $registration ): int {
		if ( $registration->getDeletionTimestamp() !== null ) {
			return self::CANNOT_REGISTER_DELETED;
		}
		$endTSUnix = wfTimestamp( TS_UNIX, $registration->getEndUTCTimestamp() );
		if ( (int)$endTSUnix < (int)MWTimestamp::now( TS_UNIX ) ) {
			return self::CANNOT_REGISTER_ENDED;
		}
		if ( $registration->getStatus() !== EventRegistration::STATUS_OPEN ) {
			return self::CANNOT_REGISTER_CLOSED;
		}
		return self::CAN_REGISTER;
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
		$registrationAllowedVal = self::checkIsRegistrationAllowed( $registration );
		if ( $registrationAllowedVal !== self::CAN_REGISTER ) {
			switch ( $registrationAllowedVal ) {
				case self::CANNOT_REGISTER_DELETED:
					$msg = 'campaignevents-register-registration-deleted';
					break;
				case self::CANNOT_REGISTER_ENDED:
					$msg = 'campaignevents-register-event-past';
					break;
				case self::CANNOT_REGISTER_CLOSED:
					$msg = 'campaignevents-register-event-not-open';
					break;
				default:
					throw new UnexpectedValueException( "Unexpected val $registrationAllowedVal" );
			}
			return StatusValue::newFatal( $msg );
		}

		try {
			$centralUser = $this->centralUserLookup->newFromAuthority( $performer );
		} catch ( UserNotGlobalException $_ ) {
			return StatusValue::newFatal( 'campaignevents-register-need-central-account' );
		}
		$modified = $this->participantsStore->addParticipantToEvent( $registration->getID(), $centralUser );
		return StatusValue::newGood( $modified );
	}
}
