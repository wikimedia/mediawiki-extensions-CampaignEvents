<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

use RuntimeException;

/**
 * This exception is thrown when the requested central user exists but is hidden.
 */
class HiddenCentralUserException extends RuntimeException {
	/**
	 * @param int $centralID
	 */
	public function __construct( int $centralID ) {
		parent::__construct( "Central ID $centralID belongs to a hidden user" );
	}
}
