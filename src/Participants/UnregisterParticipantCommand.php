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

class UnregisterParticipantCommand {
	public const SERVICE_NAME = 'CampaignEventsUnregisterParticipantCommand';

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
	 * Checks whether it's possible to cancel a registration for the given event.
	 * @param ExistingEventRegistration $registration
	 * @return bool
	 */
	public static function isUnregistrationAllowedForEvent( ExistingEventRegistration $registration ): bool {
		return self::checkIsUnregistrationAllowed(
			$registration,
			'campaignevents-unregister-registration-deleted',
			'campaignevents-unregister-event-past'
		)->isGood();
	}

	/**
	 * @param ExistingEventRegistration $registration
	 * @param string $deletedRegistrationMessage
	 * @param string $pastRegistrationMessage
	 * @return StatusValue
	 */
	private static function checkIsUnregistrationAllowed(
		ExistingEventRegistration $registration,
		string $deletedRegistrationMessage,
		string $pastRegistrationMessage
	): StatusValue {
		if ( $registration->getDeletionTimestamp() !== null ) {
			return StatusValue::newFatal( $deletedRegistrationMessage );
		}
		$endTS = $registration->getEndTimestamp();
		if ( (int)$endTS < (int)MWTimestamp::now( TS_UNIX ) ) {
			return StatusValue::newFatal( $pastRegistrationMessage );
		}
		return StatusValue::newGood();
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
		$unregistrationAllowedStatus = self::checkIsUnregistrationAllowed(
			$registration,
			'campaignevents-unregister-registration-deleted',
			'campaignevents-unregister-event-past'
		);
		if ( !$unregistrationAllowedStatus->isGood() ) {
			return $unregistrationAllowedStatus;
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
		$unregistrationAllowedStatus = self::checkIsUnregistrationAllowed(
			$registration,
			'campaignevents-unregister-participants-registration-deleted',
			'campaignevents-unregister-participants-past-registration'
		);
		if ( !$unregistrationAllowedStatus->isGood() ) {
			return $unregistrationAllowedStatus;
		}

		$eventID = $registration->getID();
		$removedParticipants = $this->participantsStore->removeParticipantsFromEvent( $eventID, $users );

		return StatusValue::newGood( $removedParticipants );
	}
}
