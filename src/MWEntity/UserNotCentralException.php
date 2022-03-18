<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

use RuntimeException;

class UserNotCentralException extends RuntimeException {
	/**
	 * @param string $userName
	 */
	public function __construct( string $userName ) {
		parent::__construct( "User $userName does not have a central ID" );
	}
}
