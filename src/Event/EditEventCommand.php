<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Event;

use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventStore;
use MediaWiki\Extension\CampaignEvents\EventPage\EventPageCacheUpdater;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUserNotFoundException;
use MediaWiki\Extension\CampaignEvents\MWEntity\HiddenCentralUserException;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsAuthority;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Organizers\Roles;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\Questions\EventAggregatedAnswersStore;
use MediaWiki\Extension\CampaignEvents\Questions\ParticipantAnswersStore;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolEventWatcher;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolUpdater;
use MediaWiki\Message\Message;
use MediaWiki\Permissions\PermissionStatus;
use MediaWiki\Utils\MWTimestamp;
use Psr\Log\LoggerInterface;
use RuntimeException;
use StatusValue;
use Wikimedia\ScopedCallback;

/**
 * Command object used for creation and editing of event registrations.
 * @todo The logic for adding organizers might perhaps be moved to a separate command.
 */
class EditEventCommand {
	public const SERVICE_NAME = 'CampaignEventsEditEventCommand';

	public const MAX_ORGANIZERS_PER_EVENT = 10;

	private IEventStore $eventStore;
	private IEventLookup $eventLookup;
	private OrganizersStore $organizerStore;
	private PermissionChecker $permissionChecker;
	private CampaignsCentralUserLookup $centralUserLookup;
	private EventPageCacheUpdater $eventPageCacheUpdater;
	private TrackingToolEventWatcher $trackingToolEventWatcher;
	private TrackingToolUpdater $trackingToolUpdater;
	private LoggerInterface $logger;
	private ParticipantAnswersStore $answersStore;
	private EventAggregatedAnswersStore $aggregatedAnswersStore;
	private PageEventLookup $pageEventLookup;

	/**
	 * @param IEventStore $eventStore
	 * @param IEventLookup $eventLookup
	 * @param OrganizersStore $organizersStore
	 * @param PermissionChecker $permissionChecker
	 * @param CampaignsCentralUserLookup $centralUserLookup
	 * @param EventPageCacheUpdater $eventPageCacheUpdater
	 * @param TrackingToolEventWatcher $trackingToolEventWatcher
	 * @param TrackingToolUpdater $trackingToolUpdater
	 * @param LoggerInterface $logger
	 * @param ParticipantAnswersStore $answersStore
	 * @param EventAggregatedAnswersStore $aggregatedAnswersStore
	 * @param PageEventLookup $pageEventLookup
	 */
	public function __construct(
		IEventStore $eventStore,
		IEventLookup $eventLookup,
		OrganizersStore $organizersStore,
		PermissionChecker $permissionChecker,
		CampaignsCentralUserLookup $centralUserLookup,
		EventPageCacheUpdater $eventPageCacheUpdater,
		TrackingToolEventWatcher $trackingToolEventWatcher,
		TrackingToolUpdater $trackingToolUpdater,
		LoggerInterface $logger,
		ParticipantAnswersStore $answersStore,
		EventAggregatedAnswersStore $aggregatedAnswersStore,
		PageEventLookup $pageEventLookup
	) {
		$this->eventStore = $eventStore;
		$this->eventLookup = $eventLookup;
		$this->organizerStore = $organizersStore;
		$this->permissionChecker = $permissionChecker;
		$this->centralUserLookup = $centralUserLookup;
		$this->eventPageCacheUpdater = $eventPageCacheUpdater;
		$this->trackingToolEventWatcher = $trackingToolEventWatcher;
		$this->trackingToolUpdater = $trackingToolUpdater;
		$this->logger = $logger;
		$this->answersStore = $answersStore;
		$this->aggregatedAnswersStore = $aggregatedAnswersStore;
		$this->pageEventLookup = $pageEventLookup;
	}

	/**
	 * @param EventRegistration $registration
	 * @param ICampaignsAuthority $performer
	 * @param string[] $organizerUsernames These must be local usernames
	 * @return StatusValue If good, the value shall be the ID of the event. Will be a PermissionStatus for
	 *   permissions-related errors. This can be a fatal status, or a non-fatal status with warnings.
	 */
	public function doEditIfAllowed(
		EventRegistration $registration,
		ICampaignsAuthority $performer,
		array $organizerUsernames
	): StatusValue {
		$permStatus = $this->authorizeEdit( $registration, $performer );
		if ( !$permStatus->isGood() ) {
			return $permStatus;
		}
		return $this->doEditUnsafe( $registration, $performer, $organizerUsernames );
	}

	/**
	 * @param EventRegistration $registration
	 * @param ICampaignsAuthority $performer
	 * @return PermissionStatus
	 */
	private function authorizeEdit(
		EventRegistration $registration,
		ICampaignsAuthority $performer
	): PermissionStatus {
		$registrationID = $registration->getID();
		$isCreation = $registrationID === null;
		$eventPage = $registration->getPage();
		if ( $isCreation && !$this->permissionChecker->userCanEnableRegistration( $performer, $eventPage ) ) {
			return PermissionStatus::newFatal( 'campaignevents-enable-registration-not-allowed-page' );
		} elseif ( !$isCreation && !$this->permissionChecker->userCanEditRegistration(
			$performer,
			$this->eventLookup->getEventByID( (int)$registrationID ) ) ) {
			return PermissionStatus::newFatal( 'campaignevents-edit-not-allowed-registration' );
		}
		return PermissionStatus::newGood();
	}

	/**
	 * @param EventRegistration $registration
	 * @param ICampaignsAuthority $performer
	 * @param string[] $organizerUsernames These must be local usernames
	 * @return StatusValue If good, the value shall be the ID of the event. Else this can be a fatal status, or a
	 *   non-fatal status with warnings.
	 */
	public function doEditUnsafe(
		EventRegistration $registration,
		ICampaignsAuthority $performer,
		array $organizerUsernames
	): StatusValue {
		$existingRegistrationForPage = $this->pageEventLookup->getRegistrationForPage( $registration->getPage() );
		if ( $existingRegistrationForPage ) {
			if ( $existingRegistrationForPage->getID() !== $registration->getID() ) {
				$msg = $existingRegistrationForPage->getDeletionTimestamp() !== null
					? 'campaignevents-error-page-already-registered-deleted'
					: 'campaignevents-error-page-already-registered';
				return StatusValue::newFatal( $msg );
			}
			if ( $existingRegistrationForPage->getDeletionTimestamp() !== null ) {
				return StatusValue::newFatal( 'campaignevents-edit-registration-deleted' );
			}
		}

		try {
			$performerCentralUser = $this->centralUserLookup->newFromAuthority( $performer );
		} catch ( UserNotGlobalException $_ ) {
			return StatusValue::newFatal( 'campaignevents-edit-need-central-account' );
		}

		$organizerValidationStatus = $this->validateOrganizers( $organizerUsernames );
		if ( !$organizerValidationStatus->isGood() ) {
			return $organizerValidationStatus;
		}
		$organizerCentralUserIDs = $organizerValidationStatus->getValue();

		$registrationID = $registration->getID();
		if ( $registrationID ) {
			$checkOrganizerNotRemovingCreatorStatus = $this->checkOrganizerNotRemovingTheCreator(
				$organizerCentralUserIDs,
				$registrationID,
				$performerCentralUser
			);

			if ( !$checkOrganizerNotRemovingCreatorStatus->isGood() ) {
				return $checkOrganizerNotRemovingCreatorStatus;
			}
		} elseif ( !in_array( $performerCentralUser->getCentralID(), $organizerCentralUserIDs, true ) ) {
			return StatusValue::newFatal( 'campaignevents-edit-no-creator' );
		}

		$organizerCentralUsers = array_map( static function ( int $centralID ): CentralUser {
			return new CentralUser( $centralID );
		}, $organizerCentralUserIDs );
		if ( $registrationID ) {
			$previousVersion = $this->eventLookup->getEventByID( $registrationID );
			if ( !$this->checkCanEditEventDates( $registration, $previousVersion ) ) {
				return StatusValue::newFatal( 'campaignevents-event-dates-cannot-be-changed' );
			}
			$trackingToolValidationStatus = $this->trackingToolEventWatcher->validateEventUpdate(
				$previousVersion,
				$registration,
				$organizerCentralUsers
			);
		} else {
			$previousVersion = null;
			$trackingToolValidationStatus = $this->trackingToolEventWatcher->validateEventCreation(
				$registration,
				$organizerCentralUsers
			);
		}
		if ( !$trackingToolValidationStatus->isGood() ) {
			return $trackingToolValidationStatus;
		}

		$newEventID = $this->eventStore->saveRegistration( $registration );
		$this->addOrganizers( $registrationID === null, $newEventID, $organizerCentralUserIDs, $performerCentralUser );
		$toolStatus = $this->updateTrackingTools(
			$newEventID,
			$previousVersion,
			$registration,
			$organizerCentralUsers
		);

		$this->eventPageCacheUpdater->purgeEventPageCache( $registration );

		$ret = StatusValue::newGood( $newEventID );
		if ( !$toolStatus->isGood() ) {
			foreach ( $toolStatus->getMessages( 'error' ) as $msg ) {
				$ret->warning( $msg );
			}
		}
		return $ret;
	}

	/**
	 * @param int $eventID
	 * @param ExistingEventRegistration|null $previousVersion
	 * @param EventRegistration $newVersion
	 * @param CentralUser[] $organizers
	 * @return StatusValue
	 */
	private function updateTrackingTools(
		int $eventID,
		?ExistingEventRegistration $previousVersion,
		EventRegistration $newVersion,
		array $organizers
	): StatusValue {
		// Use a RAII callback to log failures at this stage that could leave the database in an inconsistent state
		// but could not be logged elsewhere, e.g. due to timeouts.
		// @codeCoverageIgnoreStart - testing code run in __destruct is hard and unreliable.
		$failureLogger = new ScopedCallback( function () use ( $eventID ) {
			$this->logger->error(
				'Post-sync update failed for tracking tools, event {event_id}.',
				[
					'event_id' => $eventID,
				]
			);
		} );
		// @codeCoverageIgnoreEnd

		if ( $previousVersion ) {
			$trackingToolStatus = $this->trackingToolEventWatcher->onEventUpdated(
				$previousVersion,
				$newVersion,
				$organizers
			);
		} else {
			$trackingToolStatus = $this->trackingToolEventWatcher->onEventCreated(
				$eventID,
				$newVersion,
				$organizers
			);
		}

		// Update the tracking tools stored in the DB. This has two purpose:
		//  - Updates the sync status and TS for tools that are now successfully connecyed
		//  - Removes any tools that we could not sync, and adds back any tools that could not be removed
		// Note that we can't do this in reverse, i.e. connecting the tools first, then saving the event with only
		// tools whose sync succeeded, because we might not have an event ID yet. Also, for that we would
		// need an atomic section to encapsulate the event update and the tool change, but we can't easily open it
		// from here.
		// XXX However, we might be able to save the event without tools first, and then add the tools later once
		// they were connected, with a separate query.
		$newTools = $trackingToolStatus->getValue();
		$this->trackingToolUpdater->replaceEventTools( $eventID, $newTools );
		ScopedCallback::cancel( $failureLogger );
		return $trackingToolStatus;
	}

	/**
	 * @param bool $isCreation
	 * @param int $eventID
	 * @param array $organizerCentralIDs
	 * @param CentralUser $performer
	 */
	private function addOrganizers(
		bool $isCreation,
		int $eventID,
		array $organizerCentralIDs,
		CentralUser $performer
	): void {
		if ( !$isCreation ) {
			$eventCreator = $this->organizerStore->getEventCreator(
				$eventID,
				OrganizersStore::GET_CREATOR_INCLUDE_DELETED
			);
			if ( !$eventCreator ) {
				throw new RuntimeException( "Existing event without a creator" );
			}
			$eventCreatorID = $eventCreator->getUser()->getCentralID();
		} else {
			$eventCreatorID = $performer->getCentralID();
		}
		$organizersAndRoles = [];
		foreach ( $organizerCentralIDs as $organizerCentralUserID ) {
			$organizersAndRoles[$organizerCentralUserID] = $organizerCentralUserID === $eventCreatorID
				? [ Roles::ROLE_CREATOR ]
				: [ Roles::ROLE_ORGANIZER ];
		}
		if ( !$isCreation ) {
			$this->organizerStore->removeOrganizersFromEventExcept( $eventID, $organizerCentralIDs );
		}
		$this->organizerStore->addOrganizersToEvent( $eventID, $organizersAndRoles );
	}

	/**
	 * @param string[] $organizerUsernames
	 * @return StatusValue Fatal with an error, or a good Status whose value is a list of central IDs for the given
	 * local usernames. If fatal, the status' value *may* be a list of invalid organizer usernames.
	 */
	public function validateOrganizers( array $organizerUsernames ): StatusValue {
		if ( count( $organizerUsernames ) < 1 ) {
			return StatusValue::newFatal(
				'campaignevents-edit-no-organizers'
			);
		}

		if ( count( $organizerUsernames ) > self::MAX_ORGANIZERS_PER_EVENT ) {
			return StatusValue::newFatal(
				'campaignevents-edit-too-many-organizers',
				self::MAX_ORGANIZERS_PER_EVENT
			);
		}

		$invalidOrganizers = [];
		foreach ( $organizerUsernames as $username ) {
			if ( !$this->permissionChecker->userCanOrganizeEvents( $username ) ) {
				$invalidOrganizers[] = $username;
			}
		}

		if ( $invalidOrganizers ) {
			$ret = StatusValue::newFatal(
				'campaignevents-edit-organizers-not-allowed',
				Message::numParam( count( $invalidOrganizers ) ),
				Message::listParam( $invalidOrganizers )
			);
			$ret->value = $invalidOrganizers;
			return $ret;
		}

		$centralIDsStatus = $this->organizerNamesToCentralIDs( $organizerUsernames );
		if ( !$centralIDsStatus->isGood() ) {
			return $centralIDsStatus;
		}

		return StatusValue::newGood( $centralIDsStatus->getValue() );
	}

	/**
	 * @param string[] $localUsernames
	 * @return StatusValue Fatal with an error, or a good Status whose value is a list of central IDs for the given
	 * local usernames.
	 */
	private function organizerNamesToCentralIDs( array $localUsernames ): StatusValue {
		$organizerCentralUserIDs = [];
		$organizersWithoutGlobalAccount = [];
		foreach ( $localUsernames as $organizerUserName ) {
			if ( !$this->centralUserLookup->isValidLocalUsername( $organizerUserName ) ) {
				return StatusValue::newFatal(
					'campaignevents-edit-invalid-username',
					$organizerUserName
				);
			}
			try {
				$organizerCentralUserIDs[] = $this->centralUserLookup
					->newFromLocalUsername( $organizerUserName )->getCentralID();
			} catch ( UserNotGlobalException $_ ) {
				$organizersWithoutGlobalAccount[] = $organizerUserName;
			}
		}

		if ( $organizersWithoutGlobalAccount ) {
			return StatusValue::newFatal(
				'campaignevents-edit-organizer-need-central-account',
				Message::numParam( count( $organizersWithoutGlobalAccount ) ),
				Message::listParam( $organizersWithoutGlobalAccount )
			);
		}
		return StatusValue::newGood( $organizerCentralUserIDs );
	}

	/**
	 * @param int[] $organizerCentralUserIDs
	 * @param int $eventID
	 * @param CentralUser $performer
	 * @return StatusValue
	 */
	private function checkOrganizerNotRemovingTheCreator(
		array $organizerCentralUserIDs,
		int $eventID,
		CentralUser $performer
	): StatusValue {
		$eventCreator = $this->organizerStore->getEventCreator(
			$eventID,
			OrganizersStore::GET_CREATOR_EXCLUDE_DELETED
		);

		if ( !$eventCreator ) {
			// If there is no event creator it means that the event creator removed themself
			return StatusValue::newGood();
		}

		try {
			$eventCreatorUsername = $this->centralUserLookup->getUserName( $eventCreator->getUser() );
		} catch ( CentralUserNotFoundException | HiddenCentralUserException $_ ) {
			// Allow the removal of deleted/suppressed organizers, since they're not shown in the editing interface
			return StatusValue::newGood();
		}

		$creatorGlobalUserID = $eventCreator->getUser()->getCentralID();
		if (
			$performer->getCentralID() !== $creatorGlobalUserID &&
			!in_array( $creatorGlobalUserID, $organizerCentralUserIDs, true )
		) {
			return StatusValue::newFatal(
				'campaignevents-edit-removed-creator',
				Message::plaintextParam( $eventCreatorUsername )
			);
		}

		return StatusValue::newGood();
	}

	/**
	 * @param EventRegistration $registration
	 * @param ExistingEventRegistration $previousVersion
	 * @return bool
	 */
	private function checkCanEditEventDates(
		EventRegistration $registration,
		ExistingEventRegistration $previousVersion
	): bool {
		$givenUnixTimestamp = wfTimestamp( TS_UNIX, $registration->getEndUTCTimestamp() );
		$currentUnixTimestamp = MWTimestamp::now( TS_UNIX );
		// if there are answers for this event and end date is past
		// then the organizer can not edit the event dates and they should be disabled
		if (
			$givenUnixTimestamp > $currentUnixTimestamp &&
			$previousVersion->isPast() &&
			$this->eventHasAnswersOrAggregates( $previousVersion->getID() )
		) {
			return false;
		}
		return true;
	}

	/**
	 * @param int $registrationID
	 * @return bool
	 */
	public function eventHasAnswersOrAggregates( int $registrationID ): bool {
		return $this->answersStore->eventHasAnswers( $registrationID ) ||
			$this->aggregatedAnswersStore->eventHasAggregates( $registrationID );
	}
}
