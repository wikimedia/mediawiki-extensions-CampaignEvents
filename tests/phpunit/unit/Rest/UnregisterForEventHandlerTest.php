<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Rest;

use Generator;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Rest\UnregisterForEventHandler;
use MediaWiki\Extension\CampaignEvents\Store\EventNotFoundException;
use MediaWiki\Extension\CampaignEvents\Store\IEventLookup;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWikiUnitTestCase;
use MWTimestamp;

/**
 * @group Test
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\UnregisterForEventHandler
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\ParticipantRegistrationHandlerBase
 */
class UnregisterForEventHandlerTest extends MediaWikiUnitTestCase {
	use HandlerTestTrait;
	use CSRFTestHelperTrait;

	private const DEFAULT_REQ_DATA = [
		'method' => 'PUT',
		'pathParams' => [ 'id' => 42 ],
		'headers' => [ 'Content-Type' => 'application/json' ],
	];

	private const FAKE_TIME = 1646000000;

	/**
	 * @inheritDoc
	 */
	protected function setUp(): void {
		parent::setUp();
		MWTimestamp::setFakeTime( self::FAKE_TIME );
	}

	private function newHandler(
		ParticipantsStore $participantsStore = null,
		IEventLookup $eventLookup = null
	): UnregisterForEventHandler {
		if ( !$eventLookup ) {
			$eventLookup = $this->createMock( IEventLookup::class );
			$event = $this->createMock( ExistingEventRegistration::class );
			$event->method( 'getStatus' )->willReturn( EventRegistration::STATUS_OPEN );
			$event->method( 'getEndTimestamp' )->willReturn( (string)( self::FAKE_TIME + 1 ) );
			$eventLookup->method( 'getEventByID' )->willReturn( $event );
		}
		$handler = new UnregisterForEventHandler(
			$eventLookup,
			$participantsStore ?? $this->createMock( ParticipantsStore::class )
		);
		$this->setHandlerCSRFSafe( $handler );
		return $handler;
	}

	/**
	 * @param int $expectedStatusCode
	 * @param string $expectedErrorKey
	 * @param IEventLookup $eventLookup
	 * @dataProvider provideRequestDataWithErrors
	 */
	public function testRun( int $expectedStatusCode, string $expectedErrorKey, IEventLookup $eventLookup ) {
		$handler = $this->newHandler( null, $eventLookup );
		$performer = $this->mockRegisteredUltimateAuthority();

		try {
			$this->executeHandler( $handler, new RequestData( self::DEFAULT_REQ_DATA ), [], [], [], [], $performer );
			$this->fail( 'No exception thrown' );
		} catch ( LocalizedHttpException $e ) {
			$this->assertSame( $expectedStatusCode, $e->getCode() );
			$this->assertSame( $expectedErrorKey, $e->getMessageValue()->getKey() );
		}
	}

	/**
	 * @return Generator
	 */
	public function provideRequestDataWithErrors(): Generator {
		$eventDoesNotExistLookup = $this->createMock( IEventLookup::class );
		$eventDoesNotExistLookup->method( 'getEventByID' )
			->willThrowException( $this->createMock( EventNotFoundException::class ) );
		yield 'Event does not exist' => [
			404,
			'campaignevents-rest-register-event-not-found',
			$eventDoesNotExistLookup
		];

		$pastEvent = $this->createMock( ExistingEventRegistration::class );
		$pastEvent->method( 'getEndTimestamp' )->willReturn( (string)( self::FAKE_TIME - 1 ) );
		$pastEvent->method( 'getStatus' )->willReturn( EventRegistration::STATUS_OPEN );
		$pastEventLookup = $this->createMock( IEventLookup::class );
		$pastEventLookup->method( 'getEventByID' )->willReturn( $pastEvent );
		yield 'Past event' => [
			400,
			'campaignevents-rest-register-event-past',
			$pastEventLookup
		];
	}

	public function testCanUnregisterFromClosedEvent() {
		$closedEvent = $this->createMock( ExistingEventRegistration::class );
		$closedEvent->method( 'getStatus' )->willReturn( EventRegistration::STATUS_CLOSED );
		$closedEvent->method( 'getEndTimestamp' )->willReturn( (string)( self::FAKE_TIME + 1 ) );
		$closedEventLookup = $this->createMock( IEventLookup::class );
		$closedEventLookup->method( 'getEventByID' )->willReturn( $closedEvent );
		$handler = $this->newHandler( null, $closedEventLookup );
		$reqData = new RequestData( self::DEFAULT_REQ_DATA );
		$performer = $this->mockRegisteredUltimateAuthority();
		$respData = $this->executeHandlerAndGetBodyData( $handler, $reqData, [], [], [], [], $performer );
		$this->assertArrayHasKey( 'modified', $respData );
	}

	/**
	 * @param ParticipantsStore $participantsStore
	 * @param bool $expectedModified
	 * @dataProvider provideRequestDataSuccessful
	 */
	public function testRun__successful( ParticipantsStore $participantsStore, bool $expectedModified ) {
		$handler = $this->newHandler( $participantsStore );
		$reqData = new RequestData( self::DEFAULT_REQ_DATA );
		$performer = $this->mockRegisteredUltimateAuthority();
		$respData = $this->executeHandlerAndGetBodyData( $handler, $reqData, [], [], [], [], $performer );
		$this->assertArrayHasKey( 'modified', $respData );
		$this->assertSame( $expectedModified, $respData['modified'] );
	}

	public function provideRequestDataSuccessful(): Generator {
		$modPartStore = $this->createMock( ParticipantsStore::class );
		$modPartStore->method( 'removeParticipantFromEvent' )->willReturn( true );
		yield 'Modified' => [ $modPartStore, true ];
		$unmodPartStore = $this->createMock( ParticipantsStore::class );
		$unmodPartStore->method( 'removeParticipantFromEvent' )->willReturn( false );
		yield 'Not modified' => [ $unmodPartStore, false ];
	}
}
