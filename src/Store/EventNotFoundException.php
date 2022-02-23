<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Store;

use RuntimeException;

class EventNotFoundException extends RuntimeException {
	/**
	 * @param int $eventID
	 */
	public function __construct( int $eventID ) {
		parent::__construct( "Event $eventID not found" );
	}
}
