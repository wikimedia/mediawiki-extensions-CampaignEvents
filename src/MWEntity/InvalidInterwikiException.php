<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

use RuntimeException;

class InvalidInterwikiException extends RuntimeException {
	/**
	 * @param string $interwiki
	 */
	public function __construct( string $interwiki ) {
		parent::__construct( "Invalid interwiki: `$interwiki`" );
	}
}
