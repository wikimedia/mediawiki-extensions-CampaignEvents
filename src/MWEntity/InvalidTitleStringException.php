<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

class InvalidTitleStringException extends InvalidEventPageException {
	/**
	 * @param string $titleString
	 * @param string $errorMsgKey
	 * @param list<mixed> $errorMsgParams
	 */
	public function __construct(
		string $titleString,
		private readonly string $errorMsgKey,
		private readonly array $errorMsgParams,
	) {
		parent::__construct( "Invalid title string: `$titleString`. Details msg key: $errorMsgKey" );
	}

	public function getErrorMsgKey(): string {
		return $this->errorMsgKey;
	}

	/**
	 * @return list<mixed>
	 */
	public function getErrorMsgParams(): array {
		return $this->errorMsgParams;
	}
}
