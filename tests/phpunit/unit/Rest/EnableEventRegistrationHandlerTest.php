<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Rest;

use Generator;
use MediaWiki\Extension\CampaignEvents\Event\EditEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\EventFactory;
use MediaWiki\Extension\CampaignEvents\Event\InvalidEventDataException;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageAuthorLookup;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\Rest\EnableEventRegistrationHandler;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;
use MediaWiki\User\UserFactory;
use MediaWikiUnitTestCase;
use StatusValue;

/**
 * @group Test
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\EnableEventRegistrationHandler
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\AbstractEditEventRegistrationHandler
 */
class EnableEventRegistrationHandlerTest extends MediaWikiUnitTestCase {
	use EditEventRegistrationHandlerTestTrait;

	private function getRequestData(): array {
		return [
			'method' => 'POST',
			'bodyContents' => json_encode( self::$defaultEventParams ),
			'headers' => [
				'Content-Type' => 'application/json',
			]
		];
	}

	/**
	 * @param EventFactory|null $eventFactory
	 * @param EditEventCommand|null $editEventCmd
	 * @param UserFactory|null $userFactory
	 * @return EnableEventRegistrationHandler
	 */
	protected function newHandler(
		EventFactory $eventFactory = null,
		EditEventCommand $editEventCmd = null,
		UserFactory $userFactory = null
	): EnableEventRegistrationHandler {
		return new EnableEventRegistrationHandler(
			$eventFactory ?? $this->createMock( EventFactory::class ),
			new PermissionChecker(
				$this->createMock( OrganizersStore::class ),
				$this->createMock( PageAuthorLookup::class ),
				$this->createMock( CampaignsCentralUserLookup::class )
			),
			$editEventCmd ?? $this->getMockEditEventCommand(),
			$userFactory ?? $this->getUserFactory( true )
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

	public function testExecute__badToken() {
		$handler = $this->newHandler( null, null, $this->getUserFactory( false ) );
		$request = new RequestData( $this->getRequestData() );

		try {
			$this->executeHandler( $handler, $request, [], [], [], [], $this->mockRegisteredUltimateAuthority() );
			$this->fail( 'No exception thrown' );
		} catch ( LocalizedHttpException $e ) {
			$this->assertSame( 400, $e->getCode() );
			$this->assertStringContainsString( 'badtoken', $e->getMessageValue()->getKey() );
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
			$this->executeHandler( $handler, $request, [], [], [], [], $this->mockRegisteredUltimateAuthority() );
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
