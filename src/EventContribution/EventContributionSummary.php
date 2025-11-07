<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\EventContribution;

/**
 * Data transfer object for event contribution summary metrics.
 */
class EventContributionSummary {
	public function __construct(
		private readonly int $participantsCount,
		private readonly int $wikisEditedCount,
		private readonly int $articlesCreatedCount,
		private readonly int $articlesEditedCount,
		private readonly int $bytesAdded,
		private readonly int $bytesRemoved,
		private readonly int $linksAdded,
		private readonly int $linksRemoved,
		private readonly int $editCount
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
