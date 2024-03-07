<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Event;

use InvalidArgumentException;
use StatusValue;

class InvalidEventDataException extends InvalidArgumentException {
	private StatusValue $status;

	/**
	 * @param StatusValue $status
	 */
	public function __construct( StatusValue $status ) {
		parent::__construct( 'Invalid event data' );
		$this->status = $status;
	}

	/**
	 * @return StatusValue
	 */
	public function getStatus(): StatusValue {
		return $this->status;
	}
}
