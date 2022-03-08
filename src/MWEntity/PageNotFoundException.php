<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

use MediaWiki\DAO\WikiAwareEntity;
use RuntimeException;

class PageNotFoundException extends RuntimeException {
	/**
	 * @param int $namespace
	 * @param string $title
	 * @param string|false $wikiID
	 */
	public function __construct( int $namespace, string $title, $wikiID ) {
		$wikiDesc = $wikiID === WikiAwareEntity::LOCAL ? 'the local wiki' : $wikiID;
		parent::__construct( "Page ($namespace,$title) not found on $wikiDesc" );
	}
}
