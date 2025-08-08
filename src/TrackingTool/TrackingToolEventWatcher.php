<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\TrackingTool;

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use Psr\Log\LoggerInterface;
use StatusValue;

/**
 * This class watches changes to an event (e.g., creation, changes to the participants list) and informs any
 * attached tracking tools of those changes. validate*() methods are called before the action occurs, whereas on*()
 * methods are called once the action has occurred. on*() methods might be executed asynchronously, and no information
 * about the success/failure of the job can be returned to the caller. See the documentation of the
 * {@see \MediaWiki\Extension\CampaignEvents\TrackingTool\Tool\TrackingTool} class for more details.
 *
 * Note that tracking tools are checked sequentially, and we abort on the first failure. That's because sending
 * updates to a tracking tool might be an expensive operation (e.g., an HTTP request), and we don't want to do it
 * if the overall result is already known to be a failure.
 *
 * For methods called upon changes to the list of participants, note that the validation phase and the commit phase
 * might send different information. For instance, this can happen if there's a concurrent update between the validation
 * and the commit. The only guarantee is that the commit phase will always send the most up-to-date information.
 *
 * @todo For the future, and particolarly when we add support for multiple tracking tools, we should probably use
 * jobs instead of DeferredUpdates for asynchronous updates. Jobs are allowed to take longer and can be retried if they
 * fail.
 */
class TrackingToolEventWatcher {
	public const SERVICE_NAME = 'CampaignEventsTrackingToolEventWatcher';

	private TrackingToolRegistry $trackingToolRegistry;
	private TrackingToolUpdater $trackingToolUpdater;
	private LoggerInterface $logger;

	public function __construct(
		TrackingToolRegistry $trackingToolRegistry,
		TrackingToolUpdater $trackingToolUpdater,
		LoggerInterface $logger
	) {
		$this->trackingToolRegistry = $trackingToolRegistry;
		$this->trackingToolUpdater = $trackingToolUpdater;
		$this->logger = $logger;
	}

	/**
	 * @param EventRegistration $event
	 * @param CentralUser[] $organizers
	 */
	public function validateEventCreation( EventRegistration $event, array $organizers ): StatusValue {
		foreach ( $event->getTrackingTools() as $toolAssociation ) {
			$tool = $this->trackingToolRegistry->newFromDBID( $toolAssociation->getToolID() );
			$status = $tool->validateToolAddition( $event, $organizers, $toolAssociation->getToolEventID() );
			if ( !$status->isGood() ) {
				return $status;
			}
		}
		return StatusValue::newGood();
	}

	/**
	 * @param int $eventID ID assigned to the newly created event
	 * @param EventRegistration $event
	 * @param CentralUser[] $organizers
	 * @return StatusValue Good if all tools were synced successfully. Regardless of whether the status is good, the
	 * status' value shall be an array of TrackingToolAssociation objects for tools that were synced successfully, so
	 * that the database record of the registration can be updated if necessary.
	 *
	 * @note The caller is responsible for updating the database with the new tools.
	 */
	public function onEventCreated( int $eventID, EventRegistration $event, array $organizers ): StatusValue {
		$ret = StatusValue::newGood();
		$newTools = [];
		foreach ( $event->getTrackingTools() as $toolAssociation ) {
			$tool = $this->trackingToolRegistry->newFromDBID( $toolAssociation->getToolID() );
			$status = $tool->addToNewEvent( $eventID, $event, $organizers, $toolAssociation->getToolEventID() );
			if ( $status->isGood() ) {
				$newTools[] = $toolAssociation->asUpdatedWith(
					TrackingToolAssociation::SYNC_STATUS_SYNCED,
					wfTimestamp()
				);
			} else {
				$ret->merge( $status );
				$this->logToolFailure( 'event creation', $event, $toolAssociation, $status );
			}
		}

		$ret->value = $newTools;
		return $ret;
	}

	/**
	 * @param ExistingEventRegistration $oldVersion
	 * @param EventRegistration $newVersion
	 * @param CentralUser[] $organizers
	 */
	public function validateEventUpdate(
		ExistingEventRegistration $oldVersion,
		EventRegistration $newVersion,
		array $organizers
	): StatusValue {
		[ $removedTools, $addedTools ] = $this->splitToolsForEventUpdate( $oldVersion, $newVersion );

		foreach ( $removedTools as $removedToolAssoc ) {
			$tool = $this->trackingToolRegistry->newFromDBID( $removedToolAssoc->getToolID() );
			$status = $tool->validateToolRemoval( $oldVersion, $removedToolAssoc->getToolEventID() );
			if ( !$status->isGood() ) {
				return $status;
			}
		}

		foreach ( $addedTools as $addedToolAssoc ) {
			$tool = $this->trackingToolRegistry->newFromDBID( $addedToolAssoc->getToolID() );
			$status = $tool->validateToolAddition( $oldVersion, $organizers, $addedToolAssoc->getToolEventID() );
			if ( !$status->isGood() ) {
				return $status;
			}
		}

		return StatusValue::newGood();
	}

	/**
	 * @param ExistingEventRegistration $oldVersion
	 * @param EventRegistration $newVersion
	 * @param CentralUser[] $organizers
	 * @return StatusValue Good if all tools were synced successfully. Regardless of whether the status is good, the
	 * status' value shall be an array of TrackingToolAssociation objects for tools that are now successully synced with
	 * the event, which includes both newly-synced tools and previously-synced tools that we were not able to remove.
	 * These are provided so that the database record of the registration can be updated if necessary.
	 *
	 * @note The caller is responsible for updating the database with the new tools.
	 */
	public function onEventUpdated(
		ExistingEventRegistration $oldVersion,
		EventRegistration $newVersion,
		array $organizers
	): StatusValue {
		[ $removedTools, $addedTools, $unchangedTools ] = $this->splitToolsForEventUpdate( $oldVersion, $newVersion );

		$ret = StatusValue::newGood();
		$newTools = $unchangedTools;
		foreach ( $removedTools as $removedToolAssoc ) {
			$tool = $this->trackingToolRegistry->newFromDBID( $removedToolAssoc->getToolID() );
			$status = $tool->removeFromEvent( $oldVersion, $removedToolAssoc->getToolEventID() );
			if ( !$status->isGood() ) {
				$ret->merge( $status );
				$this->logToolFailure( 'event update (tool removal)', $oldVersion, $removedToolAssoc, $status );
				// Preserve the existing sync status and last sync timestamp
				$newTools[] = $removedToolAssoc;
			}
		}

		if ( !$ret->isGood() ) {
			// TODO: Remove this when adding support for multiple tracking tools. A failed removal means that the event
			// might end up having 2 tools even if we support only adding one.
			$ret->value = $newTools;
			return $ret;
		}

		foreach ( $addedTools as $addedToolAssoc ) {
			$tool = $this->trackingToolRegistry->newFromDBID( $addedToolAssoc->getToolID() );
			$status = $tool->addToExistingEvent( $oldVersion, $organizers, $addedToolAssoc->getToolEventID() );
			if ( $status->isGood() ) {
				$newTools[] = $addedToolAssoc->asUpdatedWith(
					TrackingToolAssociation::SYNC_STATUS_SYNCED,
					wfTimestamp()
				);
			} else {
				$ret->merge( $status );
				$this->logToolFailure( 'event update (new tool)', $newVersion, $addedToolAssoc, $status );
			}
		}

		$ret->value = $newTools;
		return $ret;
	}

	/**
	 * Given two version of an event registration, returns an array with tools that were, respectively, removed,
	 * added, and unchanged between the two version.
	 *
	 * @return TrackingToolAssociation[][]
	 * @phan-return array{0:TrackingToolAssociation[],1:TrackingToolAssociation[],2:TrackingToolAssociation[]}
	 */
	private function splitToolsForEventUpdate(
		ExistingEventRegistration $oldVersion,
		EventRegistration $newVersion
	): array {
		$oldTools = $oldVersion->getTrackingTools();
		$newTools = $newVersion->getTrackingTools();

		// Build fast-lookup maps to compute differences more easily.
		$oldToolsMap = [];
		foreach ( $oldTools as $oldToolAssoc ) {
			$key = $oldToolAssoc->getToolID() . '|' . $oldToolAssoc->getToolEventID();
			$oldToolsMap[$key] = $oldToolAssoc;
		}
		$newToolsMap = [];
		foreach ( $newTools as $newToolAssoc ) {
			$key = $newToolAssoc->getToolID() . '|' . $newToolAssoc->getToolEventID();
			$newToolsMap[$key] = $newToolAssoc;
		}

		$removedTools = array_values( array_diff_key( $oldToolsMap, $newToolsMap ) );
		$addedTools = array_values( array_diff_key( $newToolsMap, $oldToolsMap ) );
		$unchangedTools = array_values( array_intersect_key( $oldToolsMap, $newToolsMap ) );
		return [ $removedTools, $addedTools, $unchangedTools ];
	}

	public function validateEventDeletion( ExistingEventRegistration $event ): StatusValue {
		foreach ( $event->getTrackingTools() as $toolAssociation ) {
			$tool = $this->trackingToolRegistry->newFromDBID( $toolAssociation->getToolID() );
			$status = $tool->validateEventDeletion( $event, $toolAssociation->getToolEventID() );
			if ( !$status->isGood() ) {
				return $status;
			}
		}
		return StatusValue::newGood();
	}

	/**
	 * @param ExistingEventRegistration $event
	 *
	 * @note This method is also responsible for updating the database.
	 */
	public function onEventDeleted( ExistingEventRegistration $event ): void {
		DeferredUpdates::addCallableUpdate( function () use ( $event ): void {
			foreach ( $event->getTrackingTools() as $toolAssociation ) {
				$toolID = $toolAssociation->getToolID();
				$toolEventID = $toolAssociation->getToolEventID();
				$tool = $this->trackingToolRegistry->newFromDBID( $toolID );
				$status = $tool->onEventDeleted( $event, $toolEventID );
				if ( $status->isGood() ) {
					$this->trackingToolUpdater->updateToolSyncStatus(
						$event->getID(),
						$toolID,
						$toolEventID,
						TrackingToolAssociation::SYNC_STATUS_UNKNOWN
					);
				} else {
					$this->logToolFailure( 'event deletion', $event, $toolAssociation, $status );
				}
			}
		} );
	}

	public function validateParticipantAdded(
		ExistingEventRegistration $event,
		CentralUser $participant,
		bool $private
	): StatusValue {
		foreach ( $event->getTrackingTools() as $toolAssociation ) {
			$tool = $this->trackingToolRegistry->newFromDBID( $toolAssociation->getToolID() );
			$status = $tool->validateParticipantAdded(
				$event,
				$toolAssociation->getToolEventID(),
				$participant,
				$private
			);
			if ( !$status->isGood() ) {
				return $status;
			}
		}
		return StatusValue::newGood();
	}

	/**
	 * @param ExistingEventRegistration $event
	 * @param CentralUser $participant
	 * @param bool $private
	 *
	 * @note This method is also responsible for updating the database.
	 */
	public function onParticipantAdded(
		ExistingEventRegistration $event,
		CentralUser $participant,
		bool $private
	): void {
		DeferredUpdates::addCallableUpdate( function () use ( $event, $participant, $private ): void {
			foreach ( $event->getTrackingTools() as $toolAssociation ) {
				$toolID = $toolAssociation->getToolID();
				$toolEventID = $toolAssociation->getToolEventID();
				$tool = $this->trackingToolRegistry->newFromDBID( $toolID );
				$status = $tool->addParticipant( $event, $toolEventID, $participant, $private );
				if ( $status->isGood() ) {
					$newSyncStatus = TrackingToolAssociation::SYNC_STATUS_SYNCED;
				} else {
					$newSyncStatus = TrackingToolAssociation::SYNC_STATUS_FAILED;
					$this->logToolFailure( 'added participant', $event, $toolAssociation, $status );
				}
				$this->trackingToolUpdater->updateToolSyncStatus(
					$event->getID(),
					$toolID,
					$toolEventID,
					$newSyncStatus
				);
			}
		} );
	}

	/**
	 * @param ExistingEventRegistration $event
	 * @param CentralUser[]|null $participants Array of participants to remove if $invertSelection is false,
	 * or array of participants to keep if $invertSelection is true. Null means remove everyone, regardless of
	 * $invertSelection.
	 * @param bool $invertSelection
	 */
	public function validateParticipantsRemoved(
		ExistingEventRegistration $event,
		?array $participants,
		bool $invertSelection
	): StatusValue {
		foreach ( $event->getTrackingTools() as $toolAssociation ) {
			$tool = $this->trackingToolRegistry->newFromDBID( $toolAssociation->getToolID() );
			$status = $tool->validateParticipantsRemoved(
				$event,
				$toolAssociation->getToolEventID(),
				$participants,
				$invertSelection
			);
			if ( !$status->isGood() ) {
				return $status;
			}
		}
		return StatusValue::newGood();
	}

	/**
	 * @param ExistingEventRegistration $event
	 * @param CentralUser[]|null $participants Array of participants to remove if $invertSelection is false,
	 * or array of participants to keep if $invertSelection is true. Null means remove everyone, regardless of
	 * $invertSelection.
	 * @param bool $invertSelection
	 */
	public function onParticipantsRemoved(
		ExistingEventRegistration $event,
		?array $participants,
		bool $invertSelection
	): void {
		DeferredUpdates::addCallableUpdate( function () use ( $event, $participants, $invertSelection ): void {
			foreach ( $event->getTrackingTools() as $toolAssociation ) {
				$toolID = $toolAssociation->getToolID();
				$toolEventID = $toolAssociation->getToolEventID();
				$tool = $this->trackingToolRegistry->newFromDBID( $toolID );
				$status = $tool->removeParticipants( $event, $toolEventID, $participants, $invertSelection );
				if ( $status->isGood() ) {
					$newSyncStatus = TrackingToolAssociation::SYNC_STATUS_SYNCED;
				} else {
					$newSyncStatus = TrackingToolAssociation::SYNC_STATUS_FAILED;
					$this->logToolFailure( 'removed participants', $event, $toolAssociation, $status );
				}
				$this->trackingToolUpdater->updateToolSyncStatus(
					$event->getID(),
					$toolID,
					$toolEventID,
					$newSyncStatus
				);
			}
		} );
	}

	/**
	 * Logs a tracking tool failure in the MW log, so that failures can be monitored and possibly acted upon.
	 */
	private function logToolFailure(
		string $operation,
		EventRegistration $event,
		TrackingToolAssociation $toolAssoc,
		StatusValue $status
	): void {
		$this->logger->error(
			'Tracking tool update failed for: {operation}. Event {event_id} with tool {tool_id}: {error_status}',
			[
				'operation' => $operation,
				'event_id' => $event->getID(),
				'tool_id' => $toolAssoc->getToolID(),
				'tool_event_id' => $toolAssoc->getToolEventID(),
				'error_status' => $status,
			]
		);
	}
}
