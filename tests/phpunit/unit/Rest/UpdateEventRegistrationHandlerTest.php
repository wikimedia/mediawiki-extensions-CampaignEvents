<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Rest;

use Generator;
use MediaWiki\Extension\CampaignEvents\Event\EditEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\EventFactory;
use MediaWiki\Extension\CampaignEvents\Event\InvalidEventDataException;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserBlockChecker;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\Rest\UpdateEventRegistrationHandler;
use MediaWiki\Extension\CampaignEvents\Store\EventNotFoundException;
use MediaWiki\Extension\CampaignEvents\Store\IEventLookup;
use MediaWiki\Permissions\PermissionStatus;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use StatusValue;

/**
 * @group Test
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\UpdateEventRegistrationHandler
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\AbstractEditEventRegistrationHandler
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\EventIDParamTrait
 * @todo We can't test param validation due to T303619
 */
class UpdateEventRegistrationHandlerTest extends EditEventRegistrationHandlerTestBase {
	use HandlerTestTrait;

	protected const DEFAULT_POST_PARAMS = [ 'id' => 1 ] + parent::DEFAULT_POST_PARAMS;

	protected const DEFAULT_REQ_DATA = [
		'method' => 'POST',
		'postParams' => self::DEFAULT_POST_PARAMS
	];

	/**
	 * @param EventFactory|null $eventFactory
	 * @param EditEventCommand|null $editEventCmd
	 * @param IEventLookup|null $eventLookup
	 * @return UpdateEventRegistrationHandler
	 */
	protected function newHandler(
		EventFactory $eventFactory = null,
		EditEventCommand $editEventCmd = null,
		IEventLookup $eventLookup = null
	): UpdateEventRegistrationHandler {
		$handler = new UpdateEventRegistrationHandler(
			$eventFactory ?? $this->createMock( EventFactory::class ),
			new PermissionChecker(
				$this->createMock( UserBlockChecker::class ),
				$this->createMock( OrganizersStore::class )
			),
			$editEventCmd ?? $this->getMockEditEventCommand(),
			$eventLookup ?? $this->createMock( IEventLookup::class )
		);
		$this->setHandlerCSRFSafe( $handler );
		return $handler;
	}

	public function testExecute__successful(): void {
		$handler = $this->newHandler();
		$request = new RequestData( self::DEFAULT_REQ_DATA );
		$respData = $this->executeHandler(
			$handler,
			$request,
			[],
			[],
			self::DEFAULT_POST_PARAMS,
			[],
			$this->mockRegisteredUltimateAuthority()
		);

		$this->assertSame( 204, $respData->getStatusCode() );
	}

	public function testExecute__nonexistingEvent(): void {
		$eventLookup = $this->createMock( IEventLookup::class );
		$eventLookup->method( 'getEventByID' )
			->willThrowException( $this->createMock( EventNotFoundException::class ) );
		$handler = $this->newHandler( null, null, $eventLookup );
		$request = new RequestData( self::DEFAULT_REQ_DATA );
		try {
			$this->executeHandler( $handler, $request, [], [], self::DEFAULT_POST_PARAMS );
			$this->fail( 'No exception thrown' );
		} catch ( LocalizedHttpException $e ) {
			$this->assertSame( 404, $e->getCode() );
			$this->assertSame( 'campaignevents-rest-event-not-found', $e->getMessageValue()->getKey() );
		}
	}

	public function testExecute__editRegistrationPermissions() {
		$authority = $this->mockRegisteredUltimateAuthority();
		$editEventCmd = $this->createMock( EditEventCommand::class );
		$editEventCmd->method( 'doEditIfAllowed' )->willReturn(
			PermissionStatus::newFatal( 'campaignevents-edit-not-allowed-page' )
		);
		$handler = $this->newHandler( null, $editEventCmd );
		$request = new RequestData( self::DEFAULT_REQ_DATA );

		try {
			$this->executeHandler( $handler, $request, [], [], self::DEFAULT_POST_PARAMS, [], $authority );
			$this->fail( 'No exception thrown' );
		} catch ( LocalizedHttpException $e ) {
			$this->assertSame( 403, $e->getCode() );
			$this->assertSame( 'campaignevents-edit-not-allowed-page', $e->getMessageValue()->getKey() );
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
