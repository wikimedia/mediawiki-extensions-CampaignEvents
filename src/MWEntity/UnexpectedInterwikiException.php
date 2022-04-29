<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

use RuntimeException;

class UnexpectedInterwikiException extends RuntimeException {
	/**
	 * @param string $interwiki
	 */
	public function __construct( string $interwiki ) {
		parent::__construct( "Unexpectedly got a page with interwiki prefix `$interwiki`" );
	}
}
