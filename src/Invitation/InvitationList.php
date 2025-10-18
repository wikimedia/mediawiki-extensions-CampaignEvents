<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Invitation;

use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;

class InvitationList {
	public const STATUS_PENDING = 1;
	public const STATUS_READY = 2;

	public function __construct(
		private readonly int $listID,
		private readonly string $name,
		private readonly ?int $eventID,
		private readonly int $status,
		private readonly CentralUser $creator,
		private readonly string $wiki,
		private readonly string $creationTime,
	) {
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
