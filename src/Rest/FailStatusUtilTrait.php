<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use ApiMessage;
use InvalidArgumentException;
use MediaWiki\Message\Converter;
use MediaWiki\Rest\LocalizedHttpException;
use Message;
use StatusValue;

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
		$errorMsg = Message::newFromSpecifier( ApiMessage::create( $errors[0] ) );
		$converter = new Converter();
		throw new LocalizedHttpException( $converter->convertMessage( $errorMsg ), $statusCode );
	}
}
