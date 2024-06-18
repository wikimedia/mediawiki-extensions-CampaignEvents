<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Invitation;

use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;

class InvitationList {
	public const STATUS_PENDING = 1;
	public const STATUS_READY = 2;

	private int $listID;
	private string $name;
	private ?int $eventID;
	private int $status;
	private CentralUser $creator;
	private string $wiki;
	private string $creationTime;

	public function __construct(
		int $listID,
		string $name,
		?int $eventID,
		int $status,
		CentralUser $creator,
		string $wiki,
		string $creationTime
	) {
		$this->listID = $listID;
		$this->name = $name;
		$this->eventID = $eventID;
		$this->status = $status;
		$this->creator = $creator;
		$this->wiki = $wiki;
		$this->creationTime = $creationTime;
	}

	public function getListID(): int {
		return $this->listID;
	}

	public function getName(): string {
		return $this->name;
	}

	public function getEventID(): ?int {
		return $this->eventID;
	}

	public function getStatus(): int {
		return $this->status;
	}

	public function getCreator(): CentralUser {
		return $this->creator;
	}

	public function getWiki(): string {
		return $this->wiki;
	}

	/**
	 * @return string Creation timestamp in the MW format.
	 */
	public function getCreationTime(): string {
		return wfTimestamp( TS_MW, $this->creationTime );
	}
}
