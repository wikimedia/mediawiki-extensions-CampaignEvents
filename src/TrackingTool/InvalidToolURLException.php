<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\TrackingTool;

use Exception;

class InvalidToolURLException extends Exception {
	private string $baseURL;

	public function __construct( string $baseURL, string $message ) {
		parent::__construct( $message );
		$this->baseURL = $baseURL;
	}

	public function getExpectedBaseURL(): string {
		return $this->baseURL;
	}
}
