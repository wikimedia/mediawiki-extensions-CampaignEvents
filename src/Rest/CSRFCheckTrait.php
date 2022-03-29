<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use BadMethodCallException;
use MediaWiki\Rest\HttpException;
use MediaWiki\Session\Session;
use RequestContext;

/**
 * Helper for REST handlers that ensures CSRF immunity. This is temporary, as some day MW core will hopefully provide
 * a similar interface.
 */
trait CSRFCheckTrait {
	/** @var Session used in tests */
	private $session;

	/**
	 * @throws HttpException
	 */
	private function assertCSRFSafety(): void {
		$session = $this->session ?? RequestContext::getMain()->getRequest()->getSession();
		if ( !$session->getProvider()->safeAgainstCsrf() ) {
			// NOTE: We don't use a localized exception here in the hope that core will check this for us in the
			// future, and that it'll use a single & standardized translatable error message.
			throw new HttpException(
				'This endpoint must be used with OAuth',
				400
			);
		}
	}

	/**
	 * Test helper.
	 * @param Session $session
	 */
	public function setSession( Session $session ): void {
		if ( !defined( 'MW_PHPUNIT_TEST' ) ) {
			throw new BadMethodCallException( 'Should only be used in tests' );
		}
		$this->session = $session;
	}
}
