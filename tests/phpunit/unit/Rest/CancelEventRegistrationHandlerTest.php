<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Rest;

use Generator;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventNotFoundException;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\Participants\UnregisterParticipantCommand;
use MediaWiki\Extension\CampaignEvents\Rest\CancelEventRegistrationHandler;
use MediaWiki\Permissions\PermissionStatus;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;
use MediaWiki\Session\Session;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWikiUnitTestCase;
use StatusValue;

/**
 * @group Test
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\CancelEventRegistrationHandler
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\EventIDParamTrait
 */
class CancelEventRegistrationHandlerTest extends MediaWikiUnitTestCase {
	use HandlerTestTrait;
	use CSRFTestHelperTrait;

	private const DEFAULT_REQ_DATA = [
		'method' => 'DELETE',
		'pathParams' => [ 'id' => 42 ],
	];

	private function newHandler(
		?UnregisterParticipantCommand $unregisterCommand = null,
		?IEventLookup $eventLookup = null
	): CancelEventRegistrationHandler {
		if ( !$unregisterCommand ) {
			$unregisterCommand = $this->createMock( UnregisterParticipantCommand::class );
			$unregisterCommand->method( 'unregisterIfAllowed' )->willReturn( StatusValue::newGood( true ) );
		}
		return new CancelEventRegistrationHandler(
			$eventLookup ?? $this->createMock( IEventLookup::class ),
			$unregisterCommand
		);
	}

	/**
	 * @dataProvider provideBadTokenSessions
	 */
	public function testRun__badToken( Session $session, string $excepMsg, ?string $token ) {
		$this->assertCorrectBadTokenBehaviour(
			$this->newHandler(),
			self::DEFAULT_REQ_DATA,
			$session,
			$token,
			$excepMsg
		);
	}

	public function doTestRunExpectingError(
		int $expectedStatusCode,
		string $expectedErrorKey,
		?UnregisterParticipantCommand $unregisterParticipantCommand = null,
		?IEventLookup $eventLookup = null
	) {
		$handler = $this->newHandler( $unregisterParticipantCommand, $eventLookup );

		try {
			$this->executeHandler( $handler, new RequestData( self::DEFAULT_REQ_DATA ) );
			$this->fail( 'No exception thrown' );
		} catch ( LocalizedHttpException $e ) {
			$this->assertSame( $expectedStatusCode, $e->getCode() );
			$this->assertSame( $expectedErrorKey, $e->getMessageValue()->getKey() );
		}
	}

	public function testRun__eventDoesNotExist() {
		$eventDoesNotExistLookup = $this->createMock( IEventLookup::class );
		$eventDoesNotExistLookup->method( 'getEventByID' )
			->willThrowException( $this->createMock( EventNotFoundException::class ) );
		$this->doTestRunExpectingError(
			404,
			'campaignevents-rest-event-not-found',
			null,
			$eventDoesNotExistLookup
		);
	}

	public function testRun__userCannotUnregister() {
		$permError = 'some-permission-error';
		$commandWithPermError = $this->createMock( UnregisterParticipantCommand::class );
		$commandWithPermError->expects( $this->atLeastOnce() )
			->method( 'unregisterIfAllowed' )
			->willReturn( PermissionStatus::newFatal( $permError ) );
		$this->doTestRunExpectingError(
			403,
			$permError,
			$commandWithPermError
		);
	}

	public function testRun__commandError() {
		$commandError = 'some-error-from-command';
		$commandWithError = $this->createMock( UnregisterParticipantCommand::class );
		$commandWithError->expects( $this->atLeastOnce() )
			->method( 'unregisterIfAllowed' )
			->willReturn( StatusValue::newFatal( $commandError ) );
		$this->doTestRunExpectingError(
			400,
			$commandError,
			$commandWithError
		);
	}

	/**
	 * @dataProvider provideRequestDataSuccessful
	 */
	public function testRun__successful( bool $modified ) {
		$unregisterParticipantCommand = $this->createMock( UnregisterParticipantCommand::class );
		$unregisterParticipantCommand->method( 'unregisterIfAllowed' )->willReturn( StatusValue::newGood( $modified ) );
		$handler = $this->newHandler( $unregisterParticipantCommand );
		$reqData = new RequestData( self::DEFAULT_REQ_DATA );
		$respData = $this->executeHandlerAndGetBodyData( $handler, $reqData );
		$this->assertArrayHasKey( 'modified', $respData );
		$this->assertSame( $modified, $respData['modified'] );
	}

	public static function provideRequestDataSuccessful(): Generator {
		yield 'Modified' => [ true ];
		yield 'Not modified' => [ false ];
	}
}
