<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Questions;

/**
 * Value object represting an answer to a participant question.
 */
class Answer {
	/** @var int */
	private int $questionDBID;
	/** @var int|null */
	private ?int $option;
	/** @var string|null */
	private ?string $text;

	/**
	 * @param int $questionDBID
	 * @param int|null $option
	 * @param string|null $text
	 */
	public function __construct( int $questionDBID, ?int $option, ?string $text ) {
		$this->questionDBID = $questionDBID;
		$this->option = $option;
		$this->text = $text;
	}

	/**
	 * @return int
	 */
	public function getQuestionDBID(): int {
		return $this->questionDBID;
	}

	/**
	 * @return int|null
	 */
	public function getOption(): ?int {
		return $this->option;
	}

	/**
	 * @return string|null
	 */
	public function getText(): ?string {
		return $this->text;
	}
}
