<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\TrackingTool;

use Exception;

class InvalidToolURLException extends Exception {
	public function __construct(
		private readonly string $baseURL,
		string $message,
	) {
		parent::__construct( $message );
	}

	public function getExpectedBaseURL(): string {
		return $this->baseURL;
	}
}
