<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Participants;

use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\EventPage\EventPageCacheUpdater;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsAuthority;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolEventWatcher;
use MediaWiki\Permissions\PermissionStatus;
use StatusValue;
use UnexpectedValueException;

class UnregisterParticipantCommand {
	public const SERVICE_NAME = 'CampaignEventsUnregisterParticipantCommand';

	public const CAN_UNREGISTER = 0;
	public const CANNOT_UNREGISTER_DELETED = 1;
	public const DO_NOT_INVERT_USERS = false;
	public const INVERT_USERS = true;

	private ParticipantsStore $participantsStore;
	private PermissionChecker $permissionChecker;
	private CampaignsCentralUserLookup $centralUserLookup;
	private EventPageCacheUpdater $eventPageCacheUpdater;
	private TrackingToolEventWatcher $trackingToolEventWatcher;

	/**
	 * @param ParticipantsStore $participantsStore
	 * @param PermissionChecker $permissionChecker
	 * @param CampaignsCentralUserLookup $centralUserLookup
	 * @param EventPageCacheUpdater $eventPageCacheUpdater
	 * @param TrackingToolEventWatcher $trackingToolEventWatcher
	 */
	public function __construct(
		ParticipantsStore $participantsStore,
		PermissionChecker $permissionChecker,
		CampaignsCentralUserLookup $centralUserLookup,
		EventPageCacheUpdater $eventPageCacheUpdater,
		TrackingToolEventWatcher $trackingToolEventWatcher
	) {
		$this->participantsStore = $participantsStore;
		$this->permissionChecker = $permissionChecker;
		$this->centralUserLookup = $centralUserLookup;
		$this->eventPageCacheUpdater = $eventPageCacheUpdater;
		$this->trackingToolEventWatcher = $trackingToolEventWatcher;
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
		if ( !$this->permissionChecker->userCanCancelRegistration( $performer ) ) {
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
			if ( $unregistrationAllowedVal === self::CANNOT_UNREGISTER_DELETED ) {
				return StatusValue::newFatal( 'campaignevents-unregister-registration-deleted' );
			}
			throw new UnexpectedValueException( "Unexpected val $unregistrationAllowedVal" );
		}

		try {
			$centralUser = $this->centralUserLookup->newFromAuthority( $performer );
		} catch ( UserNotGlobalException $_ ) {
			return StatusValue::newFatal( 'campaignevents-unregister-need-central-account' );
		}

		$trackingToolValidationStatus = $this->trackingToolEventWatcher->validateParticipantsRemoved(
			$registration,
			[ $centralUser ],
			false
		);
		if ( !$trackingToolValidationStatus->isGood() ) {
			return $trackingToolValidationStatus;
		}

		$modified = $this->participantsStore->removeParticipantFromEvent( $registration->getID(), $centralUser );

		if ( $modified ) {
			$this->trackingToolEventWatcher->onParticipantsRemoved(
				$registration,
				[ $centralUser ],
				false
			);
			$this->eventPageCacheUpdater->purgeEventPageCache( $registration );
		}

		return StatusValue::newGood( $modified );
	}

	/**
	 * @param ExistingEventRegistration $registration
	 * @param CentralUser[]|null $users Array of users, if null remove all
	 * @param ICampaignsAuthority $performer
	 * @param bool $invertUsers self::DO_NOT_INVERT_USERS or self::INVERT_USERS
	 * @return StatusValue The StatusValue's "value" property is an array with two keys, `public` and `private`, that
	 * respectively contain the number of public and private participants removed.
	 */
	public function removeParticipantsIfAllowed(
		ExistingEventRegistration $registration,
		?array $users,
		ICampaignsAuthority $performer,
		bool $invertUsers
	): StatusValue {
		$permStatus = $this->authorizeRemoveParticipants( $registration, $performer );
		if ( !$permStatus->isGood() ) {
			return $permStatus;
		}

		return $this->removeParticipantsUnsafe( $registration, $users, $invertUsers );
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
		if ( !$this->permissionChecker->userCanRemoveParticipants( $performer, $registration ) ) {
			return PermissionStatus::newFatal( 'campaignevents-unregister-participants-permission-denied' );
		}
		return PermissionStatus::newGood();
	}

	/**
	 * @param ExistingEventRegistration $registration
	 * @param CentralUser[]|null $users Array of users, if null remove all
	 * @param bool $invertUsers self::DO_NOT_INVERT_USERS or self::INVERT_USERS
	 * @return StatusValue The StatusValue's "value" property is an array with two keys, `public` and `private`, that
	 *  respectively contain the number of public and private participants removed.
	 */
	public function removeParticipantsUnsafe(
		ExistingEventRegistration $registration,
		?array $users,
		bool $invertUsers
	): StatusValue {
		$unregistrationAllowedVal = self::checkIsUnregistrationAllowed( $registration );

		if ( $unregistrationAllowedVal === self::CANNOT_UNREGISTER_DELETED ) {
			return StatusValue::newFatal( 'campaignevents-unregister-participants-registration-deleted' );
		}
		if ( $unregistrationAllowedVal !== self::CAN_UNREGISTER ) {
			throw new UnexpectedValueException( "Unexpected val $unregistrationAllowedVal" );
		}

		$trackingToolValidationStatus = $this->trackingToolEventWatcher->validateParticipantsRemoved(
			$registration,
			$users,
			$invertUsers
		);
		if ( !$trackingToolValidationStatus->isGood() ) {
			return $trackingToolValidationStatus;
		}

		$eventID = $registration->getID();
		$removedParticipants = $this->participantsStore->removeParticipantsFromEvent(
			$eventID,
			$users,
			$invertUsers
		);

		if ( $removedParticipants['public'] + $removedParticipants['private'] > 0 ) {
			$this->trackingToolEventWatcher->onParticipantsRemoved(
				$registration,
				$users,
				$invertUsers
			);
			$this->eventPageCacheUpdater->purgeEventPageCache( $registration );
		}

		return StatusValue::newGood( $removedParticipants );
	}
}
