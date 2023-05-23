<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\TrackingTool;

use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use StatusValue;

/**
 * This class watches changes to an event (e.g., creation, changes to the participants list) and informs any
 * attached tracking tools of those changes.
 * Note that tracking tools are checked sequentially, and we abort on the first failure. That's because sending
 * updates to a tracking tool might be an expensive operation (e.g., an HTTP request), and we don't want to do it
 * if the overall result is already known to be a failure.
 */
class TrackingToolEventWatcher {
	public const SERVICE_NAME = 'CampaignEventsTrackingToolEventWatcher';

	/** @var TrackingToolRegistry */
	private $trackingToolRegistry;

	/**
	 * @param TrackingToolRegistry $trackingToolRegistry
	 */
	public function __construct( TrackingToolRegistry $trackingToolRegistry ) {
		$this->trackingToolRegistry = $trackingToolRegistry;
	}

	/**
	 * @param EventRegistration $event
	 * @param CentralUser[] $organizers
	 * @return StatusValue
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
	 * @param ExistingEventRegistration $oldVersion
	 * @param EventRegistration $newVersion
	 * @param CentralUser[] $organizers
	 * @return StatusValue
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
	 * Given two version of an event registration, returns an array with tools that were, respectively, removed and
	 * added between the two version.
	 *
	 * @param ExistingEventRegistration $oldVersion
	 * @param EventRegistration $newVersion
	 * @return TrackingToolAssociation[][]
	 * @phan-return array{0:TrackingToolAssociation[],1:TrackingToolAssociation[]}
	 */
	private function splitToolsForEventUpdate(
		ExistingEventRegistration $oldVersion,
		EventRegistration $newVersion
	): array {
		$oldTools = $oldVersion->getTrackingTools();
		$newTools = $newVersion->getTrackingTools();

		$oldToolsByID = [];
		foreach ( $oldTools as $oldToolAssoc ) {
			$toolID = $oldToolAssoc->getToolID();
			$oldToolsByID[$toolID] ??= [];
			$oldToolsByID[$toolID][] = $oldToolAssoc->getToolEventID();
		}
		$newToolsByID = [];
		foreach ( $newTools as $newToolAssoc ) {
			$toolID = $newToolAssoc->getToolID();
			$newToolsByID[$toolID] ??= [];
			$newToolsByID[$toolID][] = $newToolAssoc->getToolEventID();
		}

		$removedTools = [];
		foreach ( $oldTools as $oldToolAssoc ) {
			$toolID = $oldToolAssoc->getToolID();
			if (
				!isset( $newToolsByID[$toolID] ) ||
				!in_array( $oldToolAssoc->getToolEventID(), $newToolsByID[$toolID], true )
			) {
				$removedTools[] = $oldToolAssoc;
			}
		}

		$addedTools = [];
		foreach ( $newTools as $newToolAssoc ) {
			$toolID = $newToolAssoc->getToolID();
			if (
				!isset( $oldToolsByID[$toolID] ) ||
				!in_array( $newToolAssoc->getToolEventID(), $oldToolsByID[$toolID], true )
			) {
				$addedTools[] = $newToolAssoc;
			}
		}

		return [ $removedTools, $addedTools ];
	}

	/**
	 * @param ExistingEventRegistration $event
	 * @return StatusValue
	 */
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
	 * @param CentralUser $participant
	 * @param bool $private
	 * @return StatusValue
	 */
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
	 * @param CentralUser[]|null $participants Array of participants to remove if $invertSelection is false,
	 * or array of participants to keep if $invertSelection is true. Null means remove everyone, regardless of
	 * $invertSelection.
	 * @param bool $invertSelection
	 * @return StatusValue
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
}
