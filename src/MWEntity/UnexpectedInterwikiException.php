<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

class UnexpectedInterwikiException extends InvalidEventPageException {
	/**
	 * @param string $interwiki
	 */
	public function __construct( string $interwiki ) {
		parent::__construct( "Unexpectedly got a page with interwiki prefix `$interwiki`" );
	}
}
