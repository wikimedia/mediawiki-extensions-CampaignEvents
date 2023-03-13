<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\TrackingTool\Tool;

use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use StatusValue;

/**
 * This is the base class for every tracking tool. Each subclass represents a specific tool, and can potentially be
 * used for multiple instances of that tool. These objects are not value objects; instead, they follow the handler
 * pattern, and are used to perform actions on each tracking tool.
 * Subclasses must NOT be instantiated directly, use TrackingToolRegistry instead.
 * Note that in the future, when more tools are added, these methods may be moved to separate interfaces,
 * depending on the capabilities of each tool.
 */
abstract class TrackingTool {
	/** @var int */
	private $dbID;
	/** @var string */
	private $baseURL;

	/**
	 * @param int $dbID ID that identifies this specific tracking tool in the DB
	 * @param string $baseURL Base URL of this instance
	 * @param array $extra Any additional information needed by this instance.
	 */
	public function __construct( int $dbID, string $baseURL, array $extra ) {
		$this->dbID = $dbID;
		$this->baseURL = $baseURL;
	}

	/**
	 * Returns the ID that should be used to store this specific tracking tool into the database.
	 * @return int
	 */
	public function getDBID(): int {
		return $this->dbID;
	}

	/**
	 * @param EventRegistration $event That the tool will be added to
	 * @param CentralUser[] $organizers
	 * @param string $toolEventID
	 * @return StatusValue
	 */
	abstract public function validateToolAddition(
		EventRegistration $event,
		array $organizers,
		string $toolEventID
	): StatusValue;

	/**
	 * @param ExistingEventRegistration $event That the tool will be removed from
	 * @param string $toolEventID
	 * @return StatusValue
	 */
	abstract public function validateToolRemoval(
		ExistingEventRegistration $event,
		string $toolEventID
	): StatusValue;

	/**
	 * @param ExistingEventRegistration $event
	 * @param string $toolEventID
	 * @return StatusValue
	 */
	abstract public function validateEventDeletion(
		ExistingEventRegistration $event,
		string $toolEventID
	): StatusValue;

	/**
	 * @param ExistingEventRegistration $event
	 * @param string $toolEventID
	 * @param CentralUser $participant
	 * @param bool $private
	 * @return StatusValue
	 */
	abstract public function validateParticipantAdded(
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
	 * @return StatusValue
	 */
	abstract public function validateParticipantsRemoved(
		ExistingEventRegistration $event,
		string $toolEventID,
		?array $participants,
		bool $invertSelection
	): StatusValue;
}
