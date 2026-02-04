<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\EventContribution;

/**
 * Data transfer object for event contribution summary metrics.
 */
readonly class EventContributionSummary {
	public function __construct(
		private int $participantsCount,
		private int $wikisEditedCount,
		private int $articlesCreatedCount,
		private int $articlesEditedCount,
		private int $bytesAdded,
		private int $bytesRemoved,
		private int $linksAdded,
		private int $linksRemoved,
		private int $editCount,
	) {
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

	public function getEditCount(): int {
		return $this->editCount;
	}
}
