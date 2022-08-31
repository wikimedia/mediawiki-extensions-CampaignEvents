<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Participants;

use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsAuthority;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Permissions\PermissionStatus;
use MWTimestamp;
use StatusValue;
use UnexpectedValueException;

class UnregisterParticipantCommand {
	public const SERVICE_NAME = 'CampaignEventsUnregisterParticipantCommand';

	public const CAN_UNREGISTER = 0;
	public const CANNOT_UNREGISTER_DELETED = 1;
	public const CANNOT_UNREGISTER_ENDED = 2;

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
	 *   true if the user was actively registered, and false if they unregistered or had never registered.
	 *   Will be a PermissionStatus for permissions-related errors.
	 */
	public function unregisterIfAllowed(
		ExistingEventRegistration $registration,
		ICampaignsAuthority $performer
	): StatusValue {
		$permStatus = $this->authorizeUnregistration( $performer );
		if ( !$permStatus->isGood() ) {
			return $permStatus;
		}
		return $this->unregisterUnsafe( $registration, $performer );
	}

	/**
	 * @param ICampaignsAuthority $performer
	 * @return PermissionStatus
	 */
	private function authorizeUnregistration( ICampaignsAuthority $performer ): PermissionStatus {
		if ( !$this->permissionChecker->userCanUnregisterForEvents( $performer ) ) {
			return PermissionStatus::newFatal( 'campaignevents-unregister-not-allowed' );
		}
		return PermissionStatus::newGood();
	}

	/**
	 * @param ExistingEventRegistration $registration
	 * @return int self::CAN_UNREGISTER or one of the self::CANNOT_UNREGISTER_* contants.
	 */
	public static function checkIsUnregistrationAllowed( ExistingEventRegistration $registration ): int {
		if ( $registration->getDeletionTimestamp() !== null ) {
			return self::CANNOT_UNREGISTER_DELETED;
		}
		$endTSUnix = wfTimestamp( TS_UNIX, $registration->getEndTimestamp() );
		if ( (int)$endTSUnix < (int)MWTimestamp::now( TS_UNIX ) ) {
			return self::CANNOT_UNREGISTER_ENDED;
		}
		return self::CAN_UNREGISTER;
	}

	/**
	 * @param ExistingEventRegistration $registration
	 * @param ICampaignsAuthority $performer
	 * @return StatusValue
	 */
	public function unregisterUnsafe(
		ExistingEventRegistration $registration,
		ICampaignsAuthority $performer
	): StatusValue {
		$unregistrationAllowedVal = self::checkIsUnregistrationAllowed( $registration );
		if ( $unregistrationAllowedVal !== self::CAN_UNREGISTER ) {
			switch ( $unregistrationAllowedVal ) {
				case self::CANNOT_UNREGISTER_DELETED:
					$msg = 'campaignevents-unregister-registration-deleted';
					break;
				case self::CANNOT_UNREGISTER_ENDED:
					$msg = 'campaignevents-unregister-event-past';
					break;
				default:
					throw new UnexpectedValueException( "Unexpected val $unregistrationAllowedVal" );
			}
			return StatusValue::newFatal( $msg );
		}

		try {
			$centralUser = $this->centralUserLookup->newFromAuthority( $performer );
		} catch ( UserNotGlobalException $_ ) {
			return StatusValue::newFatal( 'campaignevents-unregister-need-central-account' );
		}
		$modified = $this->participantsStore->removeParticipantFromEvent( $registration->getID(), $centralUser );
		return StatusValue::newGood( $modified );
	}

	/**
	 * @param ExistingEventRegistration $registration
	 * @param CentralUser[]|null $users Array of users, if null remove all
	 * @param ICampaignsAuthority $performer
	 * @return StatusValue The StatusValue's "value" property has the number of participants removed
	 */
	public function removeParticipantsIfAllowed(
		ExistingEventRegistration $registration,
		?array $users,
		ICampaignsAuthority $performer
	): StatusValue {
		$permStatus = $this->authorizeRemoveParticipants( $registration, $performer );
		if ( !$permStatus->isGood() ) {
			return $permStatus;
		}

		return $this->removeParticipantsUnsafe( $registration, $users );
	}

	/**
	 * @param ExistingEventRegistration $registration
	 * @param ICampaignsAuthority $performer
	 * @return PermissionStatus
	 */
	private function authorizeRemoveParticipants(
		ExistingEventRegistration $registration,
		ICampaignsAuthority $performer
	): PermissionStatus {
		$registrationID = $registration->getID();
		if ( !$this->permissionChecker->userCanRemoveParticipants( $performer, $registrationID ) ) {
			return PermissionStatus::newFatal( 'campaignevents-unregister-participants-permission-denied' );
		}
		return PermissionStatus::newGood();
	}

	/**
	 * @param ExistingEventRegistration $registration
	 * @param CentralUser[]|null $users Array of users, if null remove all
	 * @return StatusValue The StatusValue's "value" property has the number of participants removed
	 */
	public function removeParticipantsUnsafe(
		ExistingEventRegistration $registration,
		?array $users
	): StatusValue {
		$unregistrationAllowedVal = self::checkIsUnregistrationAllowed( $registration );
		if ( $unregistrationAllowedVal !== self::CAN_UNREGISTER ) {
			switch ( $unregistrationAllowedVal ) {
				case self::CANNOT_UNREGISTER_DELETED:
					$msg = 'campaignevents-unregister-participants-registration-deleted';
					break;
				case self::CANNOT_UNREGISTER_ENDED:
					$msg = 'campaignevents-unregister-participants-past-registration';
					break;
				default:
					throw new UnexpectedValueException( "Unexpected val $unregistrationAllowedVal" );
			}
			return StatusValue::newFatal( $msg );
		}

		$eventID = $registration->getID();
		$removedParticipants = $this->participantsStore->removeParticipantsFromEvent( $eventID, $users );

		return StatusValue::newGood( $removedParticipants );
	}
}
