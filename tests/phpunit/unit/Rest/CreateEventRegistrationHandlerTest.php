<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Rest;

use Generator;
use MediaWiki\Extension\CampaignEvents\Event\EditEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\EventFactory;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\InvalidEventDataException;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserBlockChecker;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\Rest\CreateEventRegistrationHandler;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWikiUnitTestCase;
use StatusValue;

/**
 * @group Test
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\CreateEventRegistrationHandler
 * @todo We can't test param validation due to T303619
 */
class CreateEventRegistrationHandlerTest extends MediaWikiUnitTestCase {
	use HandlerTestTrait;
	use CSRFTestHelperTrait;

	private const DEFAULT_POST_PARAMS = [
		'name' => 'Some event name',
		'event_page' => 'Some event page title',
		'chat_url' => 'https://chaturl.example.org',
		'tracking_tool_name' => 'Tracking tool',
		'tracking_tool_url' => 'https://trackingtool.example.org',
		'start_time' => '20220308120000',
		'end_time' => '20220308150000',
		'type' => EventRegistration::TYPE_GENERIC,
		'online_meeting' => true,
		'physical_meeting' => true,
		'meeting_url' => 'https://meetingurl.example.org',
		'meeting_country' => 'Country',
		'meeting_address' => 'Address',
	];

	private const DEFAULT_REQ_DATA = [
		'method' => 'POST',
		'postParams' => self::DEFAULT_POST_PARAMS
	];

	/**
	 * @param EventFactory|null $eventFactory
	 * @return CreateEventRegistrationHandler
	 */
	private function newHandler(
		EventFactory $eventFactory = null
	): CreateEventRegistrationHandler {
		if ( !$eventFactory ) {
			$event = $this->createMock( EventRegistration::class );
			$event->method( 'getStatus' )->willReturn( EventRegistration::STATUS_OPEN );
			$event->method( 'getType' )->willReturn( EventRegistration::TYPE_GENERIC );
			$eventFactory = $this->createMock( EventFactory::class );
			$eventFactory->method( 'newEvent' )->willReturn( $event );
		}

		$editEventCmd = $this->createMock( EditEventCommand::class );
		$editEventCmd->method( 'doEditIfAllowed' )->willReturn( StatusValue::newGood( 42 ) );

		$handler = new CreateEventRegistrationHandler(
			$eventFactory,
			$permchecker ?? new PermissionChecker( $this->createMock( UserBlockChecker::class ) ),
			$editEventCmd
		);
		$this->setHandlerCSRFSafe( $handler );
		return $handler;
	}

	public function testExecute__successful() {
		$handler = $this->newHandler();
		$request = new RequestData( self::DEFAULT_REQ_DATA );
		$respData = $this->executeHandlerAndGetBodyData(
			$handler,
			$request,
			[],
			[],
			self::DEFAULT_POST_PARAMS,
			[],
			$this->mockRegisteredUltimateAuthority()
		);
		$this->assertArrayHasKey( 'id', $respData );
		$this->assertIsInt( $respData['id'] );
	}

	public function testExecute__unauthorized() {
		$performer = $this->mockAnonNullAuthority();
		$handler = $this->newHandler();
		$request = new RequestData( self::DEFAULT_REQ_DATA );

		try {
			$this->executeHandler( $handler, $request, [], [], self::DEFAULT_POST_PARAMS, [], $performer );
			$this->fail( 'No exception thrown' );
		} catch ( LocalizedHttpException $e ) {
			$this->assertSame( 403, $e->getCode() );
			$this->assertSame( 'campaignevents-rest-createevent-permission-denied', $e->getMessageValue()->getKey() );
		}
	}

	/**
	 * @param EventFactory $eventFactory
	 * @param string $expectedMsgKey
	 * @dataProvider provideExecuteDataForValidationTest
	 */
	public function testExecute__validation( EventFactory $eventFactory, string $expectedMsgKey ) {
		$handler = $this->newHandler( $eventFactory );
		$request = new RequestData( self::DEFAULT_REQ_DATA );

		try {
			$this->executeHandler(
				$handler,
				$request,
				[],
				[],
				self::DEFAULT_POST_PARAMS,
				[],
				$this->mockRegisteredUltimateAuthority()
			);
			$this->fail( 'No exception thrown' );
		} catch ( LocalizedHttpException $e ) {
			$this->assertSame( 400, $e->getCode() );
			$this->assertSame( $expectedMsgKey, $e->getMessageValue()->getKey() );
		}
	}

	public function provideExecuteDataForValidationTest(): Generator {
		$singleErrorMsgKey = 'some-error-key';
		$singleErrorStatus = StatusValue::newFatal( $singleErrorMsgKey );
		$singleErrorEventFactory = $this->createMock( EventFactory::class );
		$singleErrorEventFactory->method( 'newEvent' )
			->willThrowException( new InvalidEventDataException( $singleErrorStatus ) );
		yield 'Single error' => [ $singleErrorEventFactory, $singleErrorMsgKey ];

		$twoErrorKeys = [ 'first-error-key', 'second-error-key' ];
		$twoErrorsStatus = StatusValue::newGood();
		foreach ( $twoErrorKeys as $errKey ) {
			$twoErrorsStatus->fatal( $errKey );
		}
		$twoErrorsEventFactory = $this->createMock( EventFactory::class );
		$twoErrorsEventFactory->method( 'newEvent' )
			->willThrowException( new InvalidEventDataException( $twoErrorsStatus ) );
		yield 'Two errors' => [ $twoErrorsEventFactory, reset( $twoErrorKeys ) ];
	}
}
