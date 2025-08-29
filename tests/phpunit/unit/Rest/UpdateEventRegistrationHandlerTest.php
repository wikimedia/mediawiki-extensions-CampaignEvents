<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Rest;

use Generator;
use MediaWiki\Config\HashConfig;
use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Extension\CampaignEvents\Event\EditEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\EventFactory;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\EventTypesRegistry;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\InvalidEventDataException;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventNotFoundException;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWPageProxy;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWPermissionsLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageAuthorLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\WikiLookup;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\Questions\EventQuestionsRegistry;
use MediaWiki\Extension\CampaignEvents\Rest\UpdateEventRegistrationHandler;
use MediaWiki\Extension\CampaignEvents\Topics\ITopicRegistry;
use MediaWiki\Permissions\PermissionStatus;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;
use MediaWikiUnitTestCase;
use MockTitleTrait;
use StatusValue;

/**
 * @group Test
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\UpdateEventRegistrationHandler
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\AbstractEditEventRegistrationHandler
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\EventIDParamTrait
 */
class UpdateEventRegistrationHandlerTest extends MediaWikiUnitTestCase {
	use EditEventRegistrationHandlerTestTrait;
	use MockTitleTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->setService(
			'TitleFactory',
			$this->makeMockTitleFactory()
		);
	}

	private function getRequestData( ?array $params = null ): array {
		$params ??= self::$defaultEventParams;
		$bodyParams = $params + [ 'status' => EventRegistration::STATUS_OPEN ];
		return [
			'method' => 'PUT',
			'pathParams' => [ 'id' => 1 ],
			'bodyContents' => json_encode( $bodyParams ),
			'headers' => [
				'Content-Type' => 'application/json',
			]
		];
	}

	protected function newHandler(
		?EventFactory $eventFactory = null,
		?EditEventCommand $editEventCmd = null,
		?IEventLookup $eventLookup = null,
		?WikiLookup $wikiLookup = null
	): UpdateEventRegistrationHandler {
		if ( !$eventLookup ) {
			// Ensure that the wiki ID of the event page is not null, otherwise it will be passed to
			// MessageValue::param and will fail the type assertion in ScalarParam.
			$eventPage = $this->createMock( MWPageProxy::class );
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
				$this->createMock( MWPermissionsLookup::class )
			),
			$editEventCmd ?? $this->getMockEditEventCommand(),
			$this->createMock( OrganizersStore::class ),
			$this->createMock( CampaignsCentralUserLookup::class ),
			$this->createMock( EventQuestionsRegistry::class ),
			$wikiLookup ?? $this->createMock( WikiLookup::class ),
			$this->createMock( ITopicRegistry::class ),
			new EventTypesRegistry(),
			$eventLookup,
			new HashConfig( [
				'CampaignEventsCountrySchemaMigrationStage' => MIGRATION_NEW,
				'CampaignEventsEnableContributionTracking' => false,
			] ),
			$this->getCountryProvider(),
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
	public function testExecute__badToken( callable $session, string $excepMsg, ?string $token ) {
		$session = $session( $this );
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
	 * @dataProvider provideExecuteDataForValidationTest
	 */
	public function testExecute__validation( StatusValue $errorStatus, string $expectedMsgKey ) {
		$eventFactory = $this->createMock( EventFactory::class );
		$eventFactory->method( 'newEvent' )
			->willThrowException( new InvalidEventDataException( $errorStatus ) );

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

	public static function provideExecuteDataForValidationTest(): Generator {
		$singleErrorMsgKey = 'some-error-key';
		yield 'Single error' => [ StatusValue::newFatal( $singleErrorMsgKey ), $singleErrorMsgKey ];

		$twoErrorKeys = [ 'first-error-key', 'second-error-key' ];
		$twoErrorsStatus = StatusValue::newGood();
		foreach ( $twoErrorKeys as $errKey ) {
			$twoErrorsStatus->fatal( $errKey );
		}
		yield 'Two errors' => [ $twoErrorsStatus, reset( $twoErrorKeys ) ];
	}

	public function testExecute__foreignPage(): void {
		$eventPage = $this->createMock( MWPageProxy::class );
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

	public function testExecute__EditEventCmdErrors(): void {
		$expectedError = 'some-command-error';
		$editEventCmd = $this->createMock( EditEventCommand::class );
		$editEventCmd->method( 'doEditIfAllowed' )->willReturn( StatusValue::newFatal( $expectedError ) );
		$handler = $this->newHandler( null, $editEventCmd );
		$request = new RequestData( $this->getRequestData() );

		try {
			$this->executeHandler( $handler, $request );
			$this->fail( 'No exception thrown' );
		} catch ( LocalizedHttpException $e ) {
			$this->assertSame( $expectedError, $e->getMessageValue()->getKey() );
			$this->assertSame( 400, $e->getCode() );
		}
	}

	/**
	 * @dataProvider provideAllWikis
	 */
	public function testExecute__allWikis( array $allWikis, array $requestWikis ) {
		$wikiLookup = $this->createMock( WikiLookup::class );
		$wikiLookup->method( 'getAllWikis' )->willReturn( $allWikis );

		$eventFactory = $this->createMock( EventFactory::class );
		$eventFactory->expects( $this->once() )->method( 'newEvent' )
			->willReturnCallback( function ( $id, $page, $status, $tz, $start, $end, $types, $wikis ) {
				$this->assertSame( EventRegistration::ALL_WIKIS, $wikis );
				return $this->createMock( EventRegistration::class );
			} );

		$handler = $this->newHandler( $eventFactory, null, null, $wikiLookup );

		$reqParams = [ 'wikis' => $requestWikis ] + self::$defaultEventParams;
		$request = new RequestData( $this->getRequestData( $reqParams ) );

		$resp = $this->executeHandler( $handler, $request, [], [], [], [], $this->mockRegisteredUltimateAuthority() );
		$this->assertSame( 204, $resp->getStatusCode() );
	}

	public static function provideAllWikis(): Generator {
		$allWikis = [ 'aawiki', 'bbwiki' ];
		yield 'Same order' => [ $allWikis, $allWikis ];
		yield 'Flipped order' => [ $allWikis, array_reverse( $allWikis ) ];
	}
}
