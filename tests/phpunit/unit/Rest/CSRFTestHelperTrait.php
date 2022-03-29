<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Rest;

use InvalidArgumentException;
use MediaWiki\Rest\Handler;
use MediaWiki\Session\Session;
use MediaWiki\Session\SessionProviderInterface;

trait CSRFTestHelperTrait {
	/**
	 * @param Handler $handler
	 */
	private function setHandlerCSRFSafe( Handler $handler ): void {
		if ( !method_exists( $handler, 'setSession' ) ) {
			throw new InvalidArgumentException( "The given handler does not support setting a session" );
		}
		$sessionProvider = $this->createMock( SessionProviderInterface::class );
		$sessionProvider->method( 'safeAgainstCsrf' )->willReturn( true );
		$session = $this->createMock( Session::class );
		$session->method( 'getProvider' )->willReturn( $sessionProvider );
		$handler->setSession( $session );
	}
}
