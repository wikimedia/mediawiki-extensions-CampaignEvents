<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\EventContribution;

/**
 * Data transfer object for event contribution summary metrics.
 */
class EventContributionSummary {
	private int $participantsCount;
	private int $wikisEditedCount;
	private int $articlesCreatedCount;
	private int $articlesEditedCount;
	private int $bytesAdded;
	private int $bytesRemoved;
	private int $linksAdded;
	private int $linksRemoved;

	public function __construct(
		int $participantsCount,
		int $wikisEditedCount,
		int $articlesCreatedCount,
		int $articlesEditedCount,
		int $bytesAdded,
		int $bytesRemoved,
		int $linksAdded,
		int $linksRemoved
	) {
		$this->participantsCount = $participantsCount;
		$this->wikisEditedCount = $wikisEditedCount;
		$this->articlesCreatedCount = $articlesCreatedCount;
		$this->articlesEditedCount = $articlesEditedCount;
		$this->bytesAdded = $bytesAdded;
		$this->bytesRemoved = $bytesRemoved;
		$this->linksAdded = $linksAdded;
		$this->linksRemoved = $linksRemoved;
	}

	public function getParticipantsCount(): int {
		return $this->participantsCount;
	}

	public function getWikisEditedCount(): int {
		return $this->wikisEditedCount;
	}

	public function getArticlesCreatedCount(): int {
		return $this->articlesCreatedCount;
	}

	public function getArticlesEditedCount(): int {
		return $this->articlesEditedCount;
	}

	public function getBytesAdded(): int {
		return $this->bytesAdded;
	}

	public function getBytesRemoved(): int {
		return $this->bytesRemoved;
	}

	public function getLinksAdded(): int {
		return $this->linksAdded;
	}

	public function getLinksRemoved(): int {
		return $this->linksRemoved;
	}
}
