<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Questions;

/**
 * Value object represting an answer to a participant question.
 */
class Answer {
	private int $questionDBID;
	private ?int $option;
	private ?string $text;

	public function __construct( int $questionDBID, ?int $option, ?string $text ) {
		$this->questionDBID = $questionDBID;
		$this->option = $option;
		$this->text = $text;
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
