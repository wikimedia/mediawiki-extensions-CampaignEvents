<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

use RuntimeException;

class UserNotFoundException extends RuntimeException {
	/**
	 * @param int $centralID
	 */
	public function __construct( int $centralID ) {
		parent::__construct( "No user with central ID $centralID found" );
	}
}
