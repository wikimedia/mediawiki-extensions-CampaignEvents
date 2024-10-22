<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

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
		$msgs = $status->getMessages();
		if ( !$msgs ) {
			throw new InvalidArgumentException( "Got status without errors" );
		}
		// TODO Report all errors, not just the first one.
		throw new LocalizedHttpException( MessageValue::newFromSpecifier( $msgs[0] ), $statusCode );
	}
}
