<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

class InvalidTitleStringException extends InvalidEventPageException {
	private string $errorMsgKey;
	/** @var list<mixed> */
	private array $errorMsgParams;

	/**
	 * @param string $titleString
	 * @param string $errorMsgKey
	 * @param list<mixed> $errorMsgParams
	 */
	public function __construct( string $titleString, string $errorMsgKey, array $errorMsgParams ) {
		parent::__construct( "Invalid title string: `$titleString`. Details msg key: $errorMsgKey" );
		$this->errorMsgKey = $errorMsgKey;
		$this->errorMsgParams = $errorMsgParams;
	}

	/**
	 * @return string
	 */
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
