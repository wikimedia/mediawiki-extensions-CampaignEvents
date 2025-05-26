<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Rest;

use MediaWiki\Extension\CampaignEvents\Event\DeleteEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventNotFoundException;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWPageProxy;
use MediaWiki\Extension\CampaignEvents\Rest\DeleteEventRegistrationHandler;
use MediaWiki\Permissions\PermissionStatus;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWikiUnitTestCase;
use StatusValue;

/**
 * @group Test
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\DeleteEventRegistrationHandler
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\EventIDParamTrait
 */
class DeleteEventRegistrationHandlerTest extends MediaWikiUnitTestCase {
	use HandlerTestTrait;
	use CSRFTestHelperTrait;

	private const DEFAULT_REQ_DATA = [
		'method' => 'DELETE',
		'pathParams' => [ 'id' => 42 ],
	];

	private function newHandler(
		?IEventLookup $eventLookup = null,
		?DeleteEventCommand $deleteEventCommand = null,
		?MWPageProxy $page = null
	): DeleteEventRegistrationHandler {
		if ( !$page ) {
			$page = $this->createMock( MWPageProxy::class );
			$page->method( 'getWikiId' )->willReturn( false );
		}

		if ( !$eventLookup ) {
			$eventRegistration = $this->createMock( ExistingEventRegistration::class );
			$eventRegistration->method( 'getPage' )->willReturn( $page );

			$eventLookup = $this->createMock( IEventLookup::class );
			$eventLookup->method( 'getEventByID' )->willReturn( $eventRegistration );
		}

		if ( !$deleteEventCommand ) {
			$deleteEventCommand = $this->createMock( DeleteEventCommand::class );
			$deleteEventCommand->method( 'deleteIfAllowed' )->willReturn( StatusValue::newGood() );
		}
		return new DeleteEventRegistrationHandler(
			$eventLookup,
			$deleteEventCommand
		);
	}

	public function testExecute__successful() {
		$handler = $this->newHandler();
		$request = new RequestData( self::DEFAULT_REQ_DATA );
		$response = $this->executeHandler(
			$handler,
			$request,
			[],
			[],
			[],
			[],
			$this->mockRegisteredUltimateAuthority()
		);
		$this->assertSame( 204, $response->getStatusCode() );
	}

	public function doTestExecuteExpectingError(
		string $expectedMsgKey,
		int $expectedCode,
		?IEventLookup $eventLookup = null,
		?MWPageProxy $page = null
	) {
		$performer = $this->mockAnonNullAuthority();
		$handler = $this->newHandler( $eventLookup, null, $page );
		$request = new RequestData( self::DEFAULT_REQ_DATA );

		try {
			$this->executeHandler( $handler, $request, [], [], [], [], $performer );
			$this->fail( 'No exception thrown' );
		} catch ( LocalizedHttpException $e ) {
			$this->assertSame( $expectedCode, $e->getCode() );
			$this->assertSame( $expectedMsgKey, $e->getMessageValue()->getKey() );
		}
	}

	public function testExecute__eventNotFound() {
		$lookupNotFound = $this->createMock( IEventLookup::class );
		$lookupNotFound->method( 'getEventById' )
			->willThrowException( $this->createMock( EventNotFoundException::class ) );
		$this->doTestExecuteExpectingError( 'campaignevents-rest-event-not-found', 404, $lookupNotFound );
	}

	public function testExecute__eventAlreadyDeleted() {
		$deletedRegistration = $this->createMock( ExistingEventRegistration::class );
		$deletedRegistration->expects( $this->atLeastOnce() )
			->method( 'getDeletionTimestamp' )
			->willReturn( '12345678' );
		$lookupDeleted = $this->createMock( IEventLookup::class );
		$lookupDeleted->expects( $this->once() )->method( 'getEventById' )->willReturn( $deletedRegistration );
		$this->doTestExecuteExpectingError( 'campaignevents-rest-delete-already-deleted', 404, $lookupDeleted );
	}

	public function testExecute__nonLocalEvent() {
		$page = $this->createMock( MWPageProxy::class );
		$page->method( 'getWikiId' )->willReturn( 'anotherwiki' );
		$nonLocalErrorMessage = 'campaignevents-rest-delete-event-nonlocal-error-message';
		$this->doTestExecuteExpectingError(
			$nonLocalErrorMessage,
			400,
			null,
			$page
		);
	}

	/**
	 * @dataProvider provideBadTokenSessions
	 */
	public function testExecute__badToken( callable $session, string $excepMsg, ?string $token ) {
		$session = $session( $this );
		$this->assertCorrectBadTokenBehaviour(
			$this->newHandler(),
			self::DEFAULT_REQ_DATA,
			$session,
			$token,
			$excepMsg
		);
	}

	public function testExecute__permissionError() {
		$performer = $this->mockAnonNullAuthority();
		$deleteEventCommand = $this->createMock( DeleteEventCommand::class );
		$deleteEventCommand->expects( $this->once() )
			->method( 'deleteIfAllowed' )
			->willReturn( PermissionStatus::newFatal( 'foo' ) );
		$handler = $this->newHandler( null, $deleteEventCommand );
		$request = new RequestData( self::DEFAULT_REQ_DATA );

		try {
			$this->executeHandler( $handler, $request, [], [], [], [], $performer );
			$this->fail( 'No exception thrown' );
		} catch ( LocalizedHttpException $e ) {
			$this->assertSame( 403, $e->getCode() );
		}
	}
}
