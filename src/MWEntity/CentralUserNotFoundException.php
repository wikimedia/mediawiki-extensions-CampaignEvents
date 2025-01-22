<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

use RuntimeException;

/**
 * This exception is thrown when there's no global user corresponding to the given user ID.
 */
class CentralUserNotFoundException extends RuntimeException {
	public function __construct( int $centralID ) {
		parent::__construct( "Central ID $centralID does not belong to any user" );
	}
}
