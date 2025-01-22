<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

use RuntimeException;

/**
 * This exception is thrown when a local account does not correspond to any global account.
 */
class UserNotGlobalException extends RuntimeException {
	public function __construct( int $localID ) {
		parent::__construct( "User with local ID $localID does not have a global account." );
	}
}
