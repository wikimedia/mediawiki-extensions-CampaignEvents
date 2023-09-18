<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Participants;

use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\EventPage\EventPageCacheUpdater;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsAuthority;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\Notifications\UserNotifier;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\Questions\Answer;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolEventWatcher;
use MediaWiki\Permissions\PermissionStatus;
use MWTimestamp;
use StatusValue;

class RegisterParticipantCommand {
	public const SERVICE_NAME = 'CampaignEventsRegisterParticipantCommand';

	public const CAN_REGISTER = 0;
	public const CANNOT_REGISTER_DELETED = 1;
	public const CANNOT_REGISTER_ENDED = 2;
	public const CANNOT_REGISTER_CLOSED = 3;
	public const REGISTRATION_PRIVATE = true;
	public const REGISTRATION_PUBLIC = false;

	/** @var ParticipantsStore */
	private $participantsStore;
	/** @var PermissionChecker */
	private $permissionChecker;
	/** @var CampaignsCentralUserLookup */
	private $centralUserLookup;
	/** @var UserNotifier */
	private UserNotifier $userNotifier;
	/** @var EventPageCacheUpdater */
	private EventPageCacheUpdater $eventPageCacheUpdater;
	/** @var TrackingToolEventWatcher */
	private TrackingToolEventWatcher $trackingToolEventWatcher;

	/**
	 * @param ParticipantsStore $participantsStore
	 * @param PermissionChecker $permissionChecker
	 * @param CampaignsCentralUserLookup $centralUserLookup
	 * @param UserNotifier $userNotifier
	 * @param EventPageCacheUpdater $eventPageCacheUpdater
	 * @param TrackingToolEventWatcher $trackingToolEventWatcher
	 */
	public function __construct(
		ParticipantsStore $participantsStore,
		PermissionChecker $permissionChecker,
		CampaignsCentralUserLookup $centralUserLookup,
		UserNotifier $userNotifier,
		EventPageCacheUpdater $eventPageCacheUpdater,
		TrackingToolEventWatcher $trackingToolEventWatcher
	) {
		$this->participantsStore = $participantsStore;
		$this->permissionChecker = $permissionChecker;
		$this->centralUserLookup = $centralUserLookup;
		$this->userNotifier = $userNotifier;
		$this->eventPageCacheUpdater = $eventPageCacheUpdater;
		$this->trackingToolEventWatcher = $trackingToolEventWatcher;
	}

	/**
	 * @param ExistingEventRegistration $registration
	 * @param ICampaignsAuthority $performer
	 * @param bool $isPrivate self::REGISTRATION_PUBLIC or self::REGISTRATION_PRIVATE
	 * @param Answer[] $answers
	 * @return StatusValue Good if everything went fine, fatal with errors otherwise. If good, the value shall be
	 *   true if the user was not already registered (or they deleted their registration), and false if they were
	 *   already actively registered. Will be a PermissionStatus for permissions-related errors.
	 */
	public function registerIfAllowed(
		ExistingEventRegistration $registration,
		ICampaignsAuthority $performer,
		bool $isPrivate,
		array $answers
	): StatusValue {
		$permStatus = $this->authorizeRegistration( $performer );
		if ( !$permStatus->isGood() ) {
			return $permStatus;
		}
		return $this->registerUnsafe( $registration, $performer, $isPrivate, $answers );
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
	 * @param bool $isPrivate self::REGISTRATION_PUBLIC or self::REGISTRATION_PRIVATE
	 * @param Answer[] $answers
	 * @return StatusValue
	 */
	public function registerUnsafe(
		ExistingEventRegistration $registration,
		ICampaignsAuthority $performer,
		bool $isPrivate,
		array $answers
	): StatusValue {
		$registrationAllowedVal = self::checkIsRegistrationAllowed( $registration );
		if ( $registrationAllowedVal === self::CANNOT_REGISTER_DELETED ) {
			return StatusValue::newFatal( 'campaignevents-register-registration-deleted' );
		}
		// The other values are checked below after we determined whether this is an edit or a first time
		// registration. Edits should be allowed even if the registration has closed or the event has
		// ended, see T345735.

		try {
			$centralUser = $this->centralUserLookup->newFromAuthority( $performer );
		} catch ( UserNotGlobalException $_ ) {
			return StatusValue::newFatal( 'campaignevents-register-need-central-account' );
		}

		$existingRecord = $this->participantsStore->getEventParticipant(
			$registration->getID(),
			$centralUser,
			true
		);

		if ( !$existingRecord ) {
			if ( $registrationAllowedVal === self::CANNOT_REGISTER_ENDED ) {
				return StatusValue::newFatal( 'campaignevents-register-event-past' );
			}
			if ( $registrationAllowedVal === self::CANNOT_REGISTER_CLOSED ) {
				return StatusValue::newFatal( 'campaignevents-register-event-not-open' );
			}
		}

		if ( $answers && $existingRecord && $existingRecord->getAggregationTimestamp() !== null ) {
			return StatusValue::newFatal( 'campaignevents-register-answers-aggregated-error' );
		}

		$trackingToolValidationStatus = $this->trackingToolEventWatcher->validateParticipantAdded(
			$registration,
			$centralUser,
			$isPrivate
		);
		if ( !$trackingToolValidationStatus->isGood() ) {
			return $trackingToolValidationStatus;
		}

		$modified = $this->participantsStore->addParticipantToEvent(
			$registration->getID(),
			$centralUser,
			$isPrivate,
			$answers
		);

		if ( $modified !== ParticipantsStore::MODIFIED_NOTHING ) {
			if ( $modified & ParticipantsStore::MODIFIED_REGISTRATION ) {
				$this->userNotifier->notifyRegistration( $performer, $registration );
			}
			$this->trackingToolEventWatcher->onParticipantAdded(
				$registration,
				$centralUser,
				$isPrivate
			);

			if ( $isPrivate ) {
				// Only purge the cache if the participant registered privately, assuming that they were previously
				// registered publicly, so as to make sure that their username will not remain visible for too long.
				// Purging the cache in other cases wouldn't hurt, but there wouldn't be a strong reason for doing it.
				$this->eventPageCacheUpdater->purgeEventPageCache( $registration );
			}
		}

		return StatusValue::newGood( $modified !== ParticipantsStore::MODIFIED_NOTHING );
	}
}
