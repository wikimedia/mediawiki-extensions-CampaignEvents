<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Rest;

use Generator;
use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Extension\CampaignEvents\Event\EditEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\EventFactory;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\InvalidEventDataException;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventNotFoundException;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsPage;
use MediaWiki\Extension\CampaignEvents\MWEntity\IPermissionsLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageAuthorLookup;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\Rest\UpdateEventRegistrationHandler;
use MediaWiki\Permissions\PermissionStatus;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;
use MediaWiki\Session\Session;
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
		$bodyParams = self::$defaultEventParams + [ 'status' => EventRegistration::STATUS_OPEN ];
		return [
			'method' => 'PUT',
			'pathParams' => [ 'id' => 1 ],
			'bodyContents' => json_encode( $bodyParams ),
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
		if ( !$eventLookup ) {
			// Ensure that the wiki ID of the event page is not null, otherwise it will be passed to
			// MessageValue::param and will fail the type assertion in ScalarParam.
			$eventPage = $this->createMock( ICampaignsPage::class );
			$eventPage->method( 'getWikiId' )->willReturn( WikiAwareEntity::LOCAL );
			$event = $this->createMock( ExistingEventRegistration::class );
			$event->method( 'getPage' )->willReturn( $eventPage );
			$eventLookup = $this->createMock( IEventLookup::class );
			$eventLookup->method( 'getEventByID' )->willReturn( $event );
		}
		return new UpdateEventRegistrationHandler(
			$eventFactory ?? $this->createMock( EventFactory::class ),
			new PermissionChecker(
				$this->createMock( OrganizersStore::class ),
				$this->createMock( PageAuthorLookup::class ),
				$this->createMock( CampaignsCentralUserLookup::class ),
				$this->createMock( IPermissionsLookup::class )
			),
			$editEventCmd ?? $this->getMockEditEventCommand(),
			$eventLookup
		);
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

	/**
	 * @dataProvider provideBadTokenSessions
	 */
	public function testExecute__badToken( Session $session, string $excepMsg, ?string $token ) {
		$this->assertCorrectBadTokenBehaviour(
			$this->newHandler(),
			$this->getRequestData(),
			$session,
			$token,
			$excepMsg
		);
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

	public function testExecute__foreignPage(): void {
		$eventPage = $this->createMock( ICampaignsPage::class );
		$eventPage->method( 'getWikiId' )->willReturn( 'some_other_wiki' );
		$event = $this->createMock( ExistingEventRegistration::class );
		$event->method( 'getPage' )->willReturn( $eventPage );
		$eventLookup = $this->createMock( IEventLookup::class );
		$eventLookup->method( 'getEventByID' )->willReturn( $event );
		$handler = $this->newHandler( null, null, $eventLookup );
		$request = new RequestData( $this->getRequestData() );
		try {
			$this->executeHandler( $handler, $request );
			$this->fail( 'No exception thrown' );
		} catch ( LocalizedHttpException $e ) {
			$this->assertSame( 'campaignevents-rest-edit-page-nonlocal', $e->getMessageValue()->getKey() );
			$this->assertSame( 400, $e->getCode() );
		}
	}
}
