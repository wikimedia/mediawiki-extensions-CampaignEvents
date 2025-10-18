<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Questions;

use Exception;

class InvalidAnswerDataException extends Exception {
	public function __construct(
		private readonly string $questionName,
	) {
		parent::__construct( "Invalid answer for question $questionName" );
	}

	/**
	 * Returns the name of the question corresponding to an invalid answer. This might be an HTMLForm `name` attribute,
	 * or some other kind of name, depending on the context.
	 */
	public function getQuestionName(): string {
		return $this->questionName;
	}
}
