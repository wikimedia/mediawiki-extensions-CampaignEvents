<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\TrackingTool\Tool;

/**
 * This is the base class for every tracking tool. Each subclass can potentially be used for
 * multiple instances of the same tool. These objects are not meant as value object, but rather as
 * command-style objects that make it possible to perform some operations on a tracking tool instance,
 * for a specific event.
 * Subclasses must NOT be instantiated directly, use TrackingToolRegistry instead.
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

	// TODO Here will be abstract methods like onEventUpdate(), onParticipantsUpdate(), getEventURL()...
}
