<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Rest;

use Generator;
use MediaWiki\Extension\CampaignEvents\Event\EditEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\EventFactory;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\EventTypesRegistry;
use MediaWiki\Extension\CampaignEvents\Event\InvalidEventDataException;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWPermissionsLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageAuthorLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\WikiLookup;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\Questions\EventQuestionsRegistry;
use MediaWiki\Extension\CampaignEvents\Rest\EnableEventRegistrationHandler;
use MediaWiki\Extension\CampaignEvents\Topics\ITopicRegistry;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;
use MediaWikiUnitTestCase;
use MockTitleTrait;
use StatusValue;
use Wikimedia\Message\IMessageFormatterFactory;

/**
 * @group Test
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\EnableEventRegistrationHandler
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\AbstractEditEventRegistrationHandler
 */
class EnableEventRegistrationHandlerTest extends MediaWikiUnitTestCase {
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
		return [
			'method' => 'POST',
			'bodyContents' => json_encode( $params ?? self::$defaultEventParams ),
			'headers' => [
				'Content-Type' => 'application/json',
			]
		];
	}

	protected function newHandler(
		?EventFactory $eventFactory = null,
		?EditEventCommand $editEventCmd = null,
		?WikiLookup $wikiLookup = null
	): EnableEventRegistrationHandler {
		return new EnableEventRegistrationHandler(
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
			new EventTypesRegistry( $this->createMock( IMessageFormatterFactory::class ) ),
		);
	}

	public function testExecute__successful(): void {
		$handler = $this->newHandler();
		$request = new RequestData( $this->getRequestData() );
		$resp = $this->executeHandler(
			$handler,
			$request,
			[],
			[],
			[],
			[],
			$this->mockRegisteredUltimateAuthority()
		);
		$this->assertSame( 201, $resp->getStatusCode() );
		$this->assertStringContainsString(
			'/campaignevents/v0/event_registration/',
			$resp->getHeaderLine( 'Location' )
		);
		$respData = json_decode( $resp->getBody()->__toString(), true );
		$this->assertArrayHasKey( 'id', $respData );
		$this->assertIsInt( $respData['id'] );
	}

	public function testExecute__unauthorized() {
		$performer = $this->mockAnonNullAuthority();
		$handler = $this->newHandler();
		$request = new RequestData( $this->getRequestData() );

		try {
			$this->executeHandler( $handler, $request, [], [], [], [], $performer );
			$this->fail( 'No exception thrown' );
		} catch ( LocalizedHttpException $e ) {
			$this->assertSame( 403, $e->getCode() );
			$this->assertSame(
				'campaignevents-rest-enable-registration-permission-denied',
				$e->getMessageValue()->getKey()
			);
		}
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
			$this->executeHandler( $handler, $request, [], [], [], [], $this->mockRegisteredUltimateAuthority() );
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

	public function testExecute__EditEventCmdErrors(): void {
		$expectedError = 'some-command-error';
		$editEventCmd = $this->createMock( EditEventCommand::class );
		$editEventCmd->method( 'doEditIfAllowed' )->willReturn( StatusValue::newFatal( $expectedError ) );
		$handler = $this->newHandler( null, $editEventCmd );
		$request = new RequestData( $this->getRequestData() );

		try {
			$this->executeHandler( $handler, $request, [], [], [], [], $this->mockRegisteredUltimateAuthority() );
			$this->fail( 'No exception thrown' );
		} catch ( LocalizedHttpException $e ) {
			$this->assertSame( 400, $e->getCode() );
			$this->assertSame( $expectedError, $e->getMessageValue()->getKey() );
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

		$handler = $this->newHandler( $eventFactory, null, $wikiLookup );

		$reqParams = [ 'wikis' => $requestWikis ] + self::$defaultEventParams;
		$request = new RequestData( $this->getRequestData( $reqParams ) );

		$resp = $this->executeHandler( $handler, $request, [], [], [], [], $this->mockRegisteredUltimateAuthority() );
		$this->assertSame( 201, $resp->getStatusCode() );
	}

	public static function provideAllWikis(): Generator {
		$allWikis = [ 'aawiki', 'bbwiki' ];
		yield 'Same order' => [ $allWikis, $allWikis ];
		yield 'Flipped order' => [ $allWikis, array_reverse( $allWikis ) ];
	}
}
