<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Event;

use InvalidArgumentException;
use StatusValue;

class InvalidEventDataException extends InvalidArgumentException {
	public function __construct(
		private readonly StatusValue $status,
	) {
		parent::__construct( 'Invalid event data' );
	}

	public function getStatus(): StatusValue {
		return $this->status;
	}
}
