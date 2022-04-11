<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Rest;

use Generator;
use MediaWiki\Extension\CampaignEvents\Event\EditEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\EventFactory;
use MediaWiki\Extension\CampaignEvents\Event\InvalidEventDataException;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserBlockChecker;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\Rest\CreateEventRegistrationHandler;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use StatusValue;

/**
 * @group Test
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\CreateEventRegistrationHandler
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\AbstractEventRegistrationHandler
 * @todo We can't test param validation due to T303619
 */
class CreateEventRegistrationHandlerTest extends AbstractEventRegistrationHandlerTestBase {
	use HandlerTestTrait;

	protected const DEFAULT_REQ_DATA = [
		'method' => 'POST',
		'postParams' => parent::DEFAULT_POST_PARAMS
	];

	/**
	 * @param EventFactory|null $eventFactory
	 * @param EditEventCommand|null $editEventCmd
	 * @return CreateEventRegistrationHandler
	 */
	protected function newHandler(
		EventFactory $eventFactory = null,
		EditEventCommand $editEventCmd = null
	): CreateEventRegistrationHandler {
		$handler = new CreateEventRegistrationHandler(
			$eventFactory ?? $this->createMock( EventFactory::class ),
			new PermissionChecker( $this->createMock( UserBlockChecker::class ) ),
			$editEventCmd ?? $this->getMockEditEventCommand()
		);
		$this->setHandlerCSRFSafe( $handler );
		return $handler;
	}

	public function testExecute__successful(): void {
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
