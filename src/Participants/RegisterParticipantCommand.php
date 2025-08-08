<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Participants;

use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\EventPage\EventPageCacheUpdater;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\Notifications\UserNotifier;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\Questions\Answer;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolEventWatcher;
use MediaWiki\Permissions\Authority;
use MediaWiki\Permissions\PermissionStatus;
use StatusValue;

class RegisterParticipantCommand {
	public const SERVICE_NAME = 'CampaignEventsRegisterParticipantCommand';

	public const CAN_REGISTER = 0;
	public const CANNOT_REGISTER_DELETED = 1;
	public const CANNOT_REGISTER_ENDED = 2;
	public const CANNOT_REGISTER_CLOSED = 3;

	public const REGISTRATION_PRIVATE = true;
	public const REGISTRATION_PUBLIC = false;

	// Whether the user is registering for the first time, or updating their registration information.
	public const REGISTRATION_NEW = 'new';
	public const REGISTRATION_EDIT = 'edit';

	private ParticipantsStore $participantsStore;
	private PermissionChecker $permissionChecker;
	private CampaignsCentralUserLookup $centralUserLookup;
	private UserNotifier $userNotifier;
	private EventPageCacheUpdater $eventPageCacheUpdater;
	private TrackingToolEventWatcher $trackingToolEventWatcher;

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
	 * @param Authority $performer
	 * @param bool $isPrivate self::REGISTRATION_PUBLIC or self::REGISTRATION_PRIVATE
	 * @param Answer[] $answers
	 * @return StatusValue Good if everything went fine, fatal with errors otherwise. If good, the value shall be
	 *   true if the user was not already registered (or they deleted their registration), and false if they were
	 *   already actively registered. Will be a PermissionStatus for permissions-related errors.
	 */
	public function registerIfAllowed(
		ExistingEventRegistration $registration,
		Authority $performer,
		bool $isPrivate,
		array $answers
	): StatusValue {
		$permStatus = $this->authorizeRegistration( $performer, $registration );
		if ( !$permStatus->isGood() ) {
			return $permStatus;
		}
		return $this->registerUnsafe( $registration, $performer, $isPrivate, $answers );
	}

	private function authorizeRegistration(
		Authority $performer,
		ExistingEventRegistration $registration
	): PermissionStatus {
		if ( !$this->permissionChecker->userCanRegisterForEvent( $performer, $registration ) ) {
			return PermissionStatus::newFatal( 'campaignevents-register-not-allowed' );
		}
		return PermissionStatus::newGood();
	}

	/**
	 * @param ExistingEventRegistration $registration
	 * @param string $registrationType {@see self::REGISTRATION_NEW} or {@see self::REGISTRATION_EDIT}.
	 * @return StatusValue return statusvalue with any relevant error message and self::CAN_REGISTER
	 * or one of the self::CANNOT_REGISTER_* constants in the value property
	 */
	public static function checkIsRegistrationAllowed(
		ExistingEventRegistration $registration,
		string $registrationType
	): StatusValue {
		if ( $registration->getDeletionTimestamp() !== null ) {
			return StatusValue::newFatal( 'campaignevents-register-registration-deleted' )
				->setResult( false, self::CANNOT_REGISTER_DELETED );
		}
		if ( $registrationType === self::REGISTRATION_NEW ) {
			// Edits should be allowed even if the registration has closed or the event has
			// ended, see T345735.
			if ( $registration->isPast() ) {
				return StatusValue::newFatal( 'campaignevents-register-event-past' )
					->setResult( false, self::CANNOT_REGISTER_ENDED );
			}
			if ( $registration->getStatus() !== EventRegistration::STATUS_OPEN ) {
				return StatusValue::newFatal( 'campaignevents-register-event-not-open' )
					->setResult( false, self::CANNOT_REGISTER_CLOSED );
			}
		}
		return StatusValue::newGood( self::CAN_REGISTER );
	}

	/**
	 * @param ExistingEventRegistration $registration
	 * @param Authority $performer
	 * @param bool $isPrivate self::REGISTRATION_PUBLIC or self::REGISTRATION_PRIVATE
	 * @param Answer[] $answers
	 * @return StatusValue
	 */
	public function registerUnsafe(
		ExistingEventRegistration $registration,
		Authority $performer,
		bool $isPrivate,
		array $answers
	): StatusValue {
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
		$registrationType = $existingRecord !== null ? self::REGISTRATION_EDIT : self::REGISTRATION_NEW;

		$registrationAllowedVal = self::checkIsRegistrationAllowed( $registration, $registrationType );
		if ( !$registrationAllowedVal->isGood() ) {
			return $registrationAllowedVal;
		}
		if ( $answers && $this->participantsStore->userHasAggregatedAnswers( $registration->getID(), $centralUser ) ) {
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
