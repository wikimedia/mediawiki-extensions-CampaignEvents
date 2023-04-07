<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\TrackingTool;

/**
 * Value object that represent the association of a tracking tool to an event. This is tool-agnostic.
 */
class TrackingToolAssociation {
	/** @var int */
	private int $toolID;
	/** @var string */
	private string $toolEventID;

	/**
	 * @param int $toolID
	 * @param string $toolEventID
	 */
	public function __construct( int $toolID, string $toolEventID ) {
		$this->toolID = $toolID;
		$this->toolEventID = $toolEventID;
	}

	/**
	 * @return int
	 */
	public function getToolID(): int {
		return $this->toolID;
	}

	/**
	 * @return string
	 */
	public function getToolEventID(): string {
		return $this->toolEventID;
	}
}
