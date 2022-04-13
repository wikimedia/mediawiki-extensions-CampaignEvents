<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use ApiMessage;
use InvalidArgumentException;
use MediaWiki\Rest\LocalizedHttpException;
use StatusValue;
use Wikimedia\Message\MessageValue;

/**
 * Helper to exit in case of a fatal StatusValue.
 */
trait FailStatusUtilTrait {
	/**
	 * @param StatusValue $status
	 * @param int $statusCode
	 * @return never
	 */
	private function exitWithStatus( StatusValue $status, int $statusCode = 400 ): void {
		$errors = $status->getErrors();
		if ( !$errors ) {
			throw new InvalidArgumentException( "Got status without errors" );
		}
		// TODO Report all errors, not just the first one.
		$apiMsg = ApiMessage::create( $errors[0] );
		throw new LocalizedHttpException( new MessageValue( $apiMsg->getKey(), $apiMsg->getParams() ), $statusCode );
	}
}
