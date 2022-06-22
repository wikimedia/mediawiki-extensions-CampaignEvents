<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

use RuntimeException;

class UnexpectedVirtualNamespaceException extends RuntimeException {
	/**
	 * @param int $ns
	 */
	public function __construct( int $ns ) {
		parent::__construct( "Unexpectedly got a page with virtual namespace `$ns`" );
	}
}
