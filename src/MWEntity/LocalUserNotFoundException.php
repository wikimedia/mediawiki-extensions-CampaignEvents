<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

use RuntimeException;

/**
 * This exception is thrown when there's no local user corresponding to the given central user ID.
 */
class LocalUserNotFoundException extends RuntimeException {
	/**
	 * @param int $centralID
	 */
	public function __construct( int $centralID ) {
		parent::__construct( "No user with central ID $centralID found" );
	}
}
