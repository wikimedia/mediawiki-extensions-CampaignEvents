<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\TrackingTool;

/**
 * Value object that represent the association of a tracking tool to an event. This is tool-agnostic.
 */
class TrackingToolAssociation {
	/**
	 * Constants that represent the status of this association:
	 *  - UNKNOWN: unknown status, possibly because no attempts were already made to sync the tool
	 *  - SYNCED: the last synchronization completed successfully
	 *  - FAILED: the last synchronization failed
	 */
	public const SYNC_STATUS_UNKNOWN = 0;
	public const SYNC_STATUS_SYNCED = 1;
	public const SYNC_STATUS_FAILED = 2;

	/** @var int */
	private int $toolID;
	/** @var string */
	private string $toolEventID;
	/** @var int */
	private int $syncStatus;
	/** @var string|null */
	private ?string $lastSyncTimestamp;

	/**
	 * @param int $toolID
	 * @param string $toolEventID
	 * @param int $syncStatus One of the self::SYNC_STATUS_* constants
	 * @param string|null $lastSyncTimestamp UNIX timestamp
	 */
	public function __construct( int $toolID, string $toolEventID, int $syncStatus, ?string $lastSyncTimestamp ) {
		$this->toolID = $toolID;
		$this->toolEventID = $toolEventID;
		$this->syncStatus = $syncStatus;
		$this->lastSyncTimestamp = $lastSyncTimestamp;
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

	/**
	 * @return int
	 */
	public function getSyncStatus(): int {
		return $this->syncStatus;
	}

	/**
	 * @return string|null
	 */
	public function getLastSyncTimestamp(): ?string {
		return $this->lastSyncTimestamp;
	}
}
