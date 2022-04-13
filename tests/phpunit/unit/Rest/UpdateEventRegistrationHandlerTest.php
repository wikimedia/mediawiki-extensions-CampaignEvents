<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Rest;

use Generator;
use MediaWiki\Extension\CampaignEvents\Event\EditEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\EventFactory;
use MediaWiki\Extension\CampaignEvents\Event\InvalidEventDataException;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventNotFoundException;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserBlockChecker;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\Rest\UpdateEventRegistrationHandler;
use MediaWiki\Permissions\PermissionStatus;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;
use MediaWikiUnitTestCase;
use StatusValue;

/**
 * @group Test
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\UpdateEventRegistrationHandler
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\AbstractEditEventRegistrationHandler
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\EventIDParamTrait
 */
class UpdateEventRegistrationHandlerTest extends MediaWikiUnitTestCase {
	use EditEventRegistrationHandlerTestTrait;

	private function getRequestData(): array {
		return [
			'method' => 'PUT',
			'pathParams' => [ 'id' => 1 ],
			'bodyContents' => json_encode( self::$defaultEventParams ),
			'headers' => [
				'Content-Type' => 'application/json',
			]
		];
	}

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
		$request = new RequestData( $this->getRequestData() );
		$respData = $this->executeHandler(
			$handler,
			$request,
			[],
			[],
			[],
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
		$request = new RequestData( $this->getRequestData() );
		try {
			$this->executeHandler( $handler, $request );
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
		$request = new RequestData( $this->getRequestData() );

		try {
			$this->executeHandler( $handler, $request, [], [], [], [], $authority );
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

		$request = new RequestData( $this->getRequestData() );

		try {
			$this->executeHandler(
				$handler,
				$request,
				[],
				[],
				[],
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
