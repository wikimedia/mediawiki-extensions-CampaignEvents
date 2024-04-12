<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use InvalidArgumentException;
use MediaWiki\Message\Converter;
use MediaWiki\Rest\LocalizedHttpException;
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
		$msgs = $status->getMessages();
		if ( !$msgs ) {
			throw new InvalidArgumentException( "Got status without errors" );
		}
		// TODO Report all errors, not just the first one.
		$converter = new Converter();
		throw new LocalizedHttpException( $converter->convertMessage( $msgs[0] ), $statusCode );
	}
}
