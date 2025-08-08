<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\TrackingTool\Tool;

use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\TrackingTool\InvalidToolURLException;
use StatusValue;

/**
 * This is the base class for every tracking tool. Each subclass represents a specific tool, and can potentially be
 * used for multiple instances of that tool. These objects are not value objects; instead, they follow the handler
 * pattern, and are used to perform actions on each tracking tool.
 * Subclasses must NOT be instantiated directly, use TrackingToolRegistry instead.
 *
 * There are exactly two methods defined for each action, one that validates the change and one that executes it (like
 * validateToolRemoval() and removeFromEvent()), in a sort of two-phase commit approach. In particular:
 *  - validation methods are called before any write action occurs, giving tracking tools a chance to validate the
 *    change before any data is committed. Any anticipated error should be reported at this stage.
 *  - action methods are called when data may have already been written. It is still possible to fail at this stage,
 *    in which case the change to the data will be rolled back if possible, but this is not guaranteed and failures at
 *    this stage should be avoided as much as possible. Ideally, only unpredictable failures (e.g., network errors)
 *    should happen here.
 * @see \MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolEventWatcher which uses these methods.
 *
 * Note that in the future, when more tools are added, these methods may be moved to separate interfaces,
 * depending on the capabilities of each tool.
 */
abstract class TrackingTool {
	private int $dbID;
	protected string $baseURL;

	/**
	 * @param int $dbID ID that identifies this specific tracking tool in the DB
	 * @param string $baseURL Base URL of this instance
	 * @param array<string,mixed> $extra Any additional information needed by this instance.
	 */
	public function __construct( int $dbID, string $baseURL, array $extra ) {
		$this->dbID = $dbID;
		$this->baseURL = $baseURL;
	}

	/**
	 * Returns the ID that should be used to store this specific tracking tool into the database.
	 */
	public function getDBID(): int {
		return $this->dbID;
	}

	/**
	 * @param EventRegistration $event That the tool will be added to
	 * @param CentralUser[] $organizers
	 * @param string $toolEventID
	 */
	abstract public function validateToolAddition(
		EventRegistration $event,
		array $organizers,
		string $toolEventID
	): StatusValue;

	/**
	 * @param int $eventID
	 * @param EventRegistration $event That the tool will be added to
	 * @param CentralUser[] $organizers
	 * @param string $toolEventID
	 */
	abstract public function addToNewEvent(
		int $eventID,
		EventRegistration $event,
		array $organizers,
		string $toolEventID
	): StatusValue;

	/**
	 * @param ExistingEventRegistration $event That the tool will be added to
	 * @param CentralUser[] $organizers
	 * @param string $toolEventID
	 */
	abstract public function addToExistingEvent(
		ExistingEventRegistration $event,
		array $organizers,
		string $toolEventID
	): StatusValue;

	/**
	 * @param ExistingEventRegistration $event That the tool will be removed from
	 * @param string $toolEventID
	 */
	abstract public function validateToolRemoval(
		ExistingEventRegistration $event,
		string $toolEventID
	): StatusValue;

	/**
	 * @param ExistingEventRegistration $event That the tool will be removed from
	 * @param string $toolEventID
	 */
	abstract public function removeFromEvent(
		ExistingEventRegistration $event,
		string $toolEventID
	): StatusValue;

	abstract public function validateEventDeletion(
		ExistingEventRegistration $event,
		string $toolEventID
	): StatusValue;

	abstract public function onEventDeleted(
		ExistingEventRegistration $event,
		string $toolEventID
	): StatusValue;

	abstract public function validateParticipantAdded(
		ExistingEventRegistration $event,
		string $toolEventID,
		CentralUser $participant,
		bool $private
	): StatusValue;

	abstract public function addParticipant(
		ExistingEventRegistration $event,
		string $toolEventID,
		CentralUser $participant,
		bool $private
	): StatusValue;

	/**
	 * @param ExistingEventRegistration $event
	 * @param string $toolEventID
	 * @param CentralUser[]|null $participants Array of participants to remove if $invertSelection is false,
	 * or array of participants to keep if $invertSelection is true. Null means remove everyone, regardless of
	 * $invertSelection.
	 * @param bool $invertSelection
	 */
	abstract public function validateParticipantsRemoved(
		ExistingEventRegistration $event,
		string $toolEventID,
		?array $participants,
		bool $invertSelection
	): StatusValue;

	/**
	 * @param ExistingEventRegistration $event
	 * @param string $toolEventID
	 * @param CentralUser[]|null $participants Array of participants to remove if $invertSelection is false,
	 * or array of participants to keep if $invertSelection is true. Null means remove everyone, regardless of
	 * $invertSelection.
	 * @param bool $invertSelection
	 */
	abstract public function removeParticipants(
		ExistingEventRegistration $event,
		string $toolEventID,
		?array $participants,
		bool $invertSelection
	): StatusValue;

	/**
	 * Given the ID of an event in this tool, return the URL of the resource corresponding to the event on the tool
	 * itself.
	 */
	abstract public static function buildToolEventURL( string $baseURL, string $toolEventID ): string;

	/**
	 * Given the URL of an event in this tool, return the corresponding event ID in the tool.
	 *
	 * @throws InvalidToolURLException
	 */
	abstract public static function extractEventIDFromURL( string $baseURL, string $url ): string;
}
