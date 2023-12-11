<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Rest;

use Generator;
use MediaWiki\Rest\Handler;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;
use MediaWiki\Session\Session;
use MediaWiki\Session\SessionId;
use MediaWiki\Session\SessionProviderInterface;
use MediaWiki\User\User;
use Wikimedia\Message\DataMessageValue;

/**
 * Helper trait that can be used to test REST handlers requiring a CSRF token.
 */
trait CSRFTestHelperTrait {
	/**
	 * @param Handler $handler
	 * @param array $requestData
	 * @param Session $session
	 * @param string|null $token
	 * @param string $expectedExceptionMsg
	 */
	private function assertCorrectBadTokenBehaviour(
		Handler $handler,
		array $requestData,
		Session $session,
		?string $token,
		string $expectedExceptionMsg
	): void {
		if ( !isset( $requestData['bodyContents'] ) ) {
			$requestData['bodyContents'] = json_encode( [ 'token' => $token ] );
			$requestData['headers'] = [
				'Content-Type' => 'application/json',
			];
		} else {
			$requestData['bodyContents'] = json_encode(
				[ 'token' => $token ] + json_decode( $requestData['bodyContents'], true )
			);
		}

		try {
			$this->executeHandler( $handler, new RequestData( $requestData ), [], [], [], [], null, $session );
			$this->fail( 'No exception thrown' );
		} catch ( LocalizedHttpException $e ) {
			$this->assertIsBadTokenException( $e, $expectedExceptionMsg );
		}
	}

	/**
	 * @param LocalizedHttpException $e
	 * @param string $excepMsg
	 */
	private function assertIsBadTokenException( LocalizedHttpException $e, string $excepMsg ) {
		$excepMessageValue = $e->getMessageValue();
		$this->assertInstanceOf( DataMessageValue::class, $excepMessageValue );
		$this->assertSame( 'rest-badtoken', $excepMessageValue->getCode() );
		$this->assertSame( $excepMsg, $excepMessageValue->getKey() );
		$this->assertSame( 403, $e->getCode() );
	}

	/**
	 * Data provider that can be used to test the handler behaviour when the token is bad or missing.
	 * @return Generator For each test case, the first argument will be the Session object to use when instantiating
	 * the handler; the second argument is the expected exception message; the third argument is the token to add to
	 * the request body.
	 */
	public function provideBadTokenSessions(): Generator {
		$anonUser = $this->createMock( User::class );
		$anonUser->method( 'isAnon' )->willReturn( true );
		$anonSessionProvider = $this->createMock( SessionProviderInterface::class );
		$anonSessionProvider->method( 'safeAgainstCsrf' )->willReturn( false );

		$anonSession = $this->createMock( Session::class );
		$anonSession->method( 'getSessionId' )->willReturn( new SessionId( 'test' ) );
		$anonSession->method( 'getProvider' )->willReturn( $anonSessionProvider );
		$anonSession->expects( $this->atLeastOnce() )->method( 'getUser' )->willReturn( $anonUser );
		$anonSession->expects( $this->atLeastOnce() )->method( 'isPersistent' )->willReturn( false );
		yield 'Anon' => [ $anonSession, 'rest-badtoken-nosession', 'some-token' ];

		$loggedInUser = $this->createMock( User::class );
		$loggedInUser->method( 'isAnon' )->willReturn( false );

		$missingTokenSession = $this->getSession( false );
		$missingTokenSession->expects( $this->atLeastOnce() )->method( 'getUser' )->willReturn( $loggedInUser );
		yield 'Missing token' => [ $missingTokenSession, 'rest-badtoken-missing', null ];

		$mismatchingTokenSession = $this->getSession( false );
		$mismatchingTokenSession->expects( $this->atLeastOnce() )->method( 'getUser' )->willReturn( $loggedInUser );
		yield 'Mismatching token' => [ $mismatchingTokenSession, 'rest-badtoken', 'some-token' ];
	}
}
