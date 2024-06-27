<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

class UnexpectedVirtualNamespaceException extends InvalidEventPageException {
	/**
	 * @param int $ns
	 */
	public function __construct( int $ns ) {
		parent::__construct( "Unexpectedly got a page with virtual namespace `$ns`" );
	}
}
