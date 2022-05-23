<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

use RuntimeException;

class InvalidTitleStringException extends RuntimeException {
	/** @var string */
	private $errorMsgKey;
	/** @var array */
	private $errorMsgParams;

	/**
	 * @param string $titleString
	 * @param string $errorMsgKey
	 * @param array $errorMsgParams
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
	 * @return array
	 */
	public function getErrorMsgParams(): array {
		return $this->errorMsgParams;
	}
}
