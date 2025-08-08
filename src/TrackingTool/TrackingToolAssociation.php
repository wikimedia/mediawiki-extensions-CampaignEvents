<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\TrackingTool;

/**
 * Value object that represent the association of a tracking tool to an event. This is tool-agnostic.
 */
class TrackingToolAssociation {
	/**
	 * Constants that represent the status of this association:
	 *  - UNKNOWN: unknown status, possibly because no attempts were already made to sync the tool, or the event was
	 *      deleted
	 *  - SYNCED: the last synchronization completed successfully
	 *  - FAILED: the last synchronization failed
	 */
	public const SYNC_STATUS_UNKNOWN = 0;
	public const SYNC_STATUS_SYNCED = 1;
	public const SYNC_STATUS_FAILED = 2;

	private int $toolID;
	private string $toolEventID;
	private int $syncStatus;
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

	public function getToolID(): int {
		return $this->toolID;
	}

	public function getToolEventID(): string {
		return $this->toolEventID;
	}

	public function getSyncStatus(): int {
		return $this->syncStatus;
	}

	/**
	 * @return string|null UNIX timestamp or null
	 */
	public function getLastSyncTimestamp(): ?string {
		return $this->lastSyncTimestamp;
	}

	/**
	 * Returns a copy of $this updated with the given sync status and TS.
	 *
	 * @return self
	 */
	public function asUpdatedWith( int $newStatus, ?string $newTS ): self {
		return new self(
			$this->toolID,
			$this->toolEventID,
			$newStatus,
			$newTS
		);
	}
}
