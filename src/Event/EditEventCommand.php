<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Event;

use MediaWiki\Extension\CampaignEvents\Event\Store\EventNotFoundException;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventStore;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
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

	/**
	 * @param IEventStore $eventStore
	 * @param IEventLookup $eventLookup
	 * @param OrganizersStore $organizersStore
	 * @param PermissionChecker $permissionChecker
	 * @param CampaignsCentralUserLookup $centralUserLookup
	 */
	public function __construct(
		IEventStore $eventStore,
		IEventLookup $eventLookup,
		OrganizersStore $organizersStore,
		PermissionChecker $permissionChecker,
		CampaignsCentralUserLookup $centralUserLookup
	) {
		$this->eventStore = $eventStore;
		$this->eventLookup = $eventLookup;
		$this->organizerStore = $organizersStore;
		$this->permissionChecker = $permissionChecker;
		$this->centralUserLookup = $centralUserLookup;
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
			if (
				$this->isOrganizerRemovingTheCreator( $organizerCentralUserIDs, $registrationID, $performerCentralUser )
			) {
				return StatusValue::newFatal( 'campaignevents-edit-removed-creator' );
			}
		} elseif ( !in_array( $performerCentralUser->getCentralID(), $organizerCentralUserIDs, true ) ) {
			return StatusValue::newFatal( 'campaignevents-edit-no-creator' );
		}

		$saveStatus = $this->eventStore->saveRegistration( $registration );
		if ( !$saveStatus->isGood() ) {
			return $saveStatus;
		}
		$newEventID = $saveStatus->getValue();

		if ( $registrationID ) {
			$eventCreator = $this->organizerStore->getEventCreator(
				$registrationID,
				OrganizersStore::GET_CREATOR_INCLUDE_DELETED
			);
			if ( !$eventCreator ) {
				throw new RuntimeException( "Existing event without a creator" );
			}
			$eventCreatorID = $eventCreator->getUser()->getCentralID();
		} else {
			$eventCreatorID = $performerCentralUser->getCentralID();
		}
		$organizersAndRoles = [];
		foreach ( $organizerCentralUserIDs as $organizerCentralUserID ) {
			$organizersAndRoles[$organizerCentralUserID] = $organizerCentralUserID === $eventCreatorID
				? [ Roles::ROLE_CREATOR ]
				: [ Roles::ROLE_ORGANIZER ];
		}
		if ( $registrationID ) {
			$this->organizerStore->removeOrganizersFromEventExcept( $registrationID, $organizerCentralUserIDs );
		}
		$this->organizerStore->addOrganizersToEvent( $newEventID, $organizersAndRoles );
		return StatusValue::newGood( $newEventID );
	}

	/**
	 * @param string[] $organizerUsernames
	 * @return StatusValue Fatal with an error, or a good Status whose value is a list of central IDs for the given
	 * local usernames.
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
			return StatusValue::newFatal(
				'campaignevents-edit-organizers-not-allowed',
				Message::numParam( count( $invalidOrganizers ) ),
				Message::listParam( $invalidOrganizers )
			);
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
	 * @return bool
	 */
	private function isOrganizerRemovingTheCreator(
		array $organizerCentralUserIDs,
		int $eventID,
		CentralUser $performer
	): bool {
		$eventCreator = $this->organizerStore->getEventCreator(
			$eventID,
			OrganizersStore::GET_CREATOR_EXCLUDE_DELETED
		);

		if ( !$eventCreator ) {
			return false;
		}

		$creatorGlobalUserID = $eventCreator->getUser()->getCentralID();

		if (
			$performer->getCentralID() !== $creatorGlobalUserID &&
			!in_array( $creatorGlobalUserID, $organizerCentralUserIDs, true )
		) {
			return true;
		}
		return false;
	}
}
