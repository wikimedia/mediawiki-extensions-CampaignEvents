<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

use RuntimeException;

class PageNotFoundException extends RuntimeException {
	/**
	 * @param int $namespace
	 * @param string $title
	 * @param string $wikiID
	 */
	public function __construct( int $namespace, string $title, string $wikiID ) {
		parent::__construct( "Page ($namespace,$title) not found on $wikiID" );
	}
}
