<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Rest;

use Generator;
use HashConfig;
use MediaWiki\Extension\CampaignEvents\Event\EditEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\Rest\SetOrganizersHandler;
use MediaWiki\Permissions\PermissionStatus;
use MediaWiki\Rest\HttpException;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;
use MediaWiki\Session\Session;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWikiUnitTestCase;
use StatusValue;

/**
 * @group Test
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\SetOrganizersHandler
 */
class SetOrganizersHandlerTest extends MediaWikiUnitTestCase {
	use CSRFTestHelperTrait;
	use HandlerTestTrait;

	private function newHandler(
		bool $featureEnabled = true,
		EditEventCommand $editEventCommand = null
	): SetOrganizersHandler {
		if ( !$editEventCommand ) {
			$editEventCommand = $this->createMock( EditEventCommand::class );
			$editEventCommand->method( 'doEditIfAllowed' )->willReturn( StatusValue::newGood( 42 ) );
		}
		$centralUserLookup = $this->createMock( CampaignsCentralUserLookup::class );
		$centralUserLookup->method( 'isValidLocalUsername' )->willReturn( true );
		return new SetOrganizersHandler(
			$this->createMock( IEventLookup::class ),
			$editEventCommand,
			$centralUserLookup,
			new HashConfig( [ 'CampaignEventsEnableMultipleOrganizers' => $featureEnabled ] )
		);
	}

	/**
	 * @param string[] $organizers
	 * @return array
	 */
	private function getRequestData( array $organizers ): array {
		return [
			'method' => 'PUT',
			'pathParams' => [ 'id' => 42 ],
			'bodyContents' => json_encode( [ 'organizer_usernames' => $organizers ] ),
			'headers' => [ 'Content-Type' => 'application/json' ],
		];
	}

	public function testRun__featureDisabled() {
		$handler = $this->newHandler( false );
		$this->expectException( HttpException::class );
		$this->expectExceptionMessage( 'This endpoint is not enabled on this wiki' );
		$this->expectExceptionCode( 421 );
		$this->executeHandler( $handler, new RequestData( $this->getRequestData( [] ) ) );
	}

	/**
	 * @dataProvider provideBadTokenSessions
	 */
	public function testExecute__badToken( Session $session, string $excepMsg, ?string $token ) {
		$this->assertCorrectBadTokenBehaviour(
			$this->newHandler(),
			$this->getRequestData( [ 'foo' ] ),
			$session,
			$token,
			$excepMsg
		);
	}

	public function testRun__emptyOrganizerUsernames(): void {
		try {
			$this->executeHandler( $this->newHandler(), new RequestData( $this->getRequestData( [] ) ) );
			$this->fail( 'No exception thrown' );
		} catch ( LocalizedHttpException $e ) {
			$this->assertSame(
				'campaignevents-rest-set-organizers-empty-list',
				$e->getMessageValue()->getKey()
			);
			$this->assertSame( 400, $e->getCode() );
		}
	}

	/**
	 * @param EditEventCommand $editEventCommand
	 * @param string $expectedErrorMsg
	 * @param int $expectedCode
	 * @dataProvider provideCommandErrors
	 */
	public function testRun__commandError(
		EditEventCommand $editEventCommand,
		string $expectedErrorMsg,
		int $expectedCode
	) {
		$handler = $this->newHandler( true, $editEventCommand );
		$performer = $this->mockRegisteredUltimateAuthority();
		$request = new RequestData( $this->getRequestData( [ 'foo' ] ) );

		try {
			$this->executeHandler( $handler, $request, [], [], [], [], $performer );
			$this->fail( 'No exception thrown' );
		} catch ( LocalizedHttpException $e ) {
			$this->assertSame( $expectedErrorMsg, $e->getMessageValue()->getKey() );
			$this->assertSame( $expectedCode, $e->getCode() );
		}
	}

	public function provideCommandErrors(): Generator {
		$validationError = 'some-command-error';
		$failValidationCmd = $this->createMock( EditEventCommand::class );
		$failValidationCmd->method( 'doEditIfAllowed' )->willReturn( StatusValue::newFatal( $validationError ) );
		yield 'Invalid data' => [ $failValidationCmd, $validationError, 400 ];

		$permissionError = 'some-permission-error';
		$notAuthorizedCmd = $this->createMock( EditEventCommand::class );
		$notAuthorizedCmd->method( 'doEditIfAllowed' )->willReturn( PermissionStatus::newFatal( $permissionError ) );
		yield 'Permission error' => [ $notAuthorizedCmd, $permissionError, 403 ];
	}

	public function testRun__successful() {
		$handler = $this->newHandler();
		$request = new RequestData( $this->getRequestData( [ 'foo' ] ) );
		$performer = $this->mockRegisteredUltimateAuthority();
		$resp = $this->executeHandler( $handler, $request, [], [], [], [], $performer );
		$this->assertSame( 204, $resp->getStatusCode() );
	}
}
