<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

class UnexpectedSectionAnchorException extends InvalidEventPageException {
	public function __construct( string $fragment ) {
		parent::__construct( "Unexpectedly got a page with a section fragment: `$fragment`" );
	}
}
