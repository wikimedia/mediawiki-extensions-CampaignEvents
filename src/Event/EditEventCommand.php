<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Event;

use MediaWiki\Extension\CampaignEvents\Event\Store\EventNotFoundException;
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
use MediaWiki\Permissions\PermissionStatus;
use Message;
use RuntimeException;
use StatusValue;

/**
 * Command object used for creation and editing of event registrations.
 * @todo The logic for adding organizers might perhaps be moved to a separate command.
 */
class EditEventCommand {
	public const SERVICE_NAME = 'CampaignEventsEditEventCommand';

	public const MAX_ORGANIZERS_PER_EVENT = 10;

	/** @var IEventStore */
	private $eventStore;
	/** @var IEventLookup */
	private $eventLookup;
	/** @var OrganizersStore */
	private $organizerStore;
	/** @var PermissionChecker */
	private $permissionChecker;
	/** @var CampaignsCentralUserLookup */
	private $centralUserLookup;
	/** @var EventPageCacheUpdater */
	private EventPageCacheUpdater $eventPageCacheUpdater;

	/**
	 * @param IEventStore $eventStore
	 * @param IEventLookup $eventLookup
	 * @param OrganizersStore $organizersStore
	 * @param PermissionChecker $permissionChecker
	 * @param CampaignsCentralUserLookup $centralUserLookup
	 * @param EventPageCacheUpdater $eventPageCacheUpdater
	 */
	public function __construct(
		IEventStore $eventStore,
		IEventLookup $eventLookup,
		OrganizersStore $organizersStore,
		PermissionChecker $permissionChecker,
		CampaignsCentralUserLookup $centralUserLookup,
		EventPageCacheUpdater $eventPageCacheUpdater
	) {
		$this->eventStore = $eventStore;
		$this->eventLookup = $eventLookup;
		$this->organizerStore = $organizersStore;
		$this->permissionChecker = $permissionChecker;
		$this->centralUserLookup = $centralUserLookup;
		$this->eventPageCacheUpdater = $eventPageCacheUpdater;
	}

	/**
	 * @param EventRegistration $registration
	 * @param ICampaignsAuthority $performer
	 * @param string[] $organizerUsernames These must be local usernames
	 * @return StatusValue If good, the value shall be the ID of the event. Will be a PermissionStatus for
	 *   permissions-related errors.
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
		} elseif ( !$isCreation && !$this->permissionChecker->userCanEditRegistration( $performer, $registrationID ) ) {
			// @phan-suppress-previous-line PhanTypeMismatchArgumentNullable
			return PermissionStatus::newFatal( 'campaignevents-edit-not-allowed-registration' );
		}
		return PermissionStatus::newGood();
	}

	/**
	 * @param EventRegistration $registration
	 * @param ICampaignsAuthority $performer
	 * @param string[] $organizerUsernames These must be local usernames
	 * @return StatusValue If good, the value shall be the ID of the event.
	 */
	public function doEditUnsafe(
		EventRegistration $registration,
		ICampaignsAuthority $performer,
		array $organizerUsernames
	): StatusValue {
		try {
			$existingRegistrationForPage = $this->eventLookup->getEventByPage( $registration->getPage() );
			if ( $existingRegistrationForPage->getID() !== $registration->getID() ) {
				$msg = $existingRegistrationForPage->getDeletionTimestamp() !== null
					? 'campaignevents-error-page-already-registered-deleted'
					: 'campaignevents-error-page-already-registered';
				return StatusValue::newFatal( $msg );
			}
			if ( $existingRegistrationForPage->getDeletionTimestamp() !== null ) {
				return StatusValue::newFatal( 'campaignevents-edit-registration-deleted' );
			}
		} catch ( EventNotFoundException $_ ) {
			// The page has no associated registration, and we're creating one now. No problem.
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

		$saveStatus = $this->eventStore->saveRegistration( $registration );
		if ( !$saveStatus->isGood() ) {
			return $saveStatus;
		}
		$this->eventPageCacheUpdater->purgeEventPageCache( $registration );
		$newEventID = $saveStatus->getValue();
		$this->addOrganizers( $registrationID === null, $newEventID, $organizerCentralUserIDs, $performerCentralUser );

		return StatusValue::newGood( $newEventID );
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
}
