<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Rest;

use Generator;
use MediaWiki\Extension\CampaignEvents\Event\DeleteEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Rest\DeleteEventRegistrationHandler;
use MediaWiki\Extension\CampaignEvents\Store\EventNotFoundException;
use MediaWiki\Extension\CampaignEvents\Store\IEventLookup;
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
		'headers' => [ 'Content-Type' => 'application/json' ],
	];

	/**
	 * @param IEventLookup|null $eventLookup
	 * @param DeleteEventCommand|null $deleteEventCommand
	 * @return DeleteEventRegistrationHandler
	 */
	private function newHandler(
		IEventLookup $eventLookup = null,
		DeleteEventCommand $deleteEventCommand = null
	): DeleteEventRegistrationHandler {
		if ( !$deleteEventCommand ) {
			$deleteEventCommand = $this->createMock( DeleteEventCommand::class );
			$deleteEventCommand->method( 'deleteIfAllowed' )->willReturn( StatusValue::newGood() );
		}
		$handler = new DeleteEventRegistrationHandler(
			$eventLookup ?? $this->createMock( IEventLookup::class ),
			$deleteEventCommand
		);
		$this->setHandlerCSRFSafe( $handler );
		return $handler;
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

	/**
	 * @param string $expectedMsgKey
	 * @param int $expectedCode
	 * @param IEventLookup|null $eventLookup
	 * @dataProvider provideErrorData
	 */
	public function testExecute__error(
		string $expectedMsgKey,
		int $expectedCode,
		IEventLookup $eventLookup = null
	) {
		$performer = $this->mockAnonNullAuthority();
		$handler = $this->newHandler( $eventLookup );
		$request = new RequestData( self::DEFAULT_REQ_DATA );

		try {
			$this->executeHandler( $handler, $request, [], [], [], [], $performer );
			$this->fail( 'No exception thrown' );
		} catch ( LocalizedHttpException $e ) {
			$this->assertSame( $expectedCode, $e->getCode() );
			$this->assertSame( $expectedMsgKey, $e->getMessageValue()->getKey() );
		}
	}

	public function provideErrorData(): Generator {
		$lookupNotFound = $this->createMock( IEventLookup::class );
		$lookupNotFound->method( 'getEventById' )
			->willThrowException( $this->createMock( EventNotFoundException::class ) );
		yield 'Event not found' => [ 'campaignevents-rest-event-not-found', 404, $lookupNotFound ];

		$deletedRegistration = $this->createMock( ExistingEventRegistration::class );
		$deletedRegistration->expects( $this->atLeastOnce() )
			->method( 'getDeletionTimestamp' )
			->willReturn( '12345678' );
		$lookupDeleted = $this->createMock( IEventLookup::class );
		$lookupDeleted->expects( $this->once() )->method( 'getEventById' )->willReturn( $deletedRegistration );
		yield 'Event already deleted' => [ 'campaignevents-rest-delete-already-deleted', 404, $lookupDeleted ];
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
