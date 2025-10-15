<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Questions;

/**
 * Value object represting an answer to a participant question.
 */
class Answer {
	public function __construct(
		private readonly int $questionDBID,
		private readonly ?int $option,
		private readonly ?string $text
	) {
	}

	public function getQuestionDBID(): int {
		return $this->questionDBID;
	}

	public function getOption(): ?int {
		return $this->option;
	}

	public function getText(): ?string {
		return $this->text;
	}
}
