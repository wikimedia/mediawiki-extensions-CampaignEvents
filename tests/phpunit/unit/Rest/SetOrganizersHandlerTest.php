<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Rest;

use MediaWiki\Extension\CampaignEvents\Event\EditEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWPageProxy;
use MediaWiki\Extension\CampaignEvents\Rest\SetOrganizersHandler;
use MediaWiki\Permissions\PermissionStatus;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWiki\Title\TitleParser;
use MediaWiki\User\UserIdentityLookup;
use MediaWikiUnitTestCase;
use MockTitleTrait;
use StatusValue;

/**
 * @group Test
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\SetOrganizersHandler
 */
class SetOrganizersHandlerTest extends MediaWikiUnitTestCase {
	use CSRFTestHelperTrait;
	use HandlerTestTrait;
	use MockTitleTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->setService(
			'TitleParser',
			$this->createNoOpMock( TitleParser::class )
		);
		$this->setService(
			'UserNameUtils',
			$this->getDummyUserNameUtils()
		);
		$this->setService(
			'UserIdentityLookup',
			$this->createMock( UserIdentityLookup::class )
		);
	}

	/**
	 * @param IEventLookup|null $eventLookup
	 * @param EditEventCommand|null $editEventCommand
	 * @param MWPageProxy|null $page
	 * @return SetOrganizersHandler
	 */
	private function newHandler(
		?IEventLookup $eventLookup = null,
		?EditEventCommand $editEventCommand = null,
		?MWPageProxy $page = null
	): SetOrganizersHandler {
		if ( !$page ) {
			$page = $this->createMock( MWPageProxy::class );
			$page->method( 'getWikiId' )->willReturn( false );
		}
		if ( !$eventLookup ) {
			$eventRegistration = $this->createMock( ExistingEventRegistration::class );
			$eventRegistration->method( 'getPage' )->willReturn( $page );

			$eventLookup = $this->createMock( IEventLookup::class );
			$eventLookup->method( 'getEventByID' )->willReturn( $eventRegistration );
		}
		if ( !$editEventCommand ) {
			$editEventCommand = $this->createMock( EditEventCommand::class );
			$editEventCommand->method( 'doEditIfAllowed' )->willReturn( StatusValue::newGood( 42 ) );
		}
		return new SetOrganizersHandler(
			$eventLookup,
			$editEventCommand,
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

	/**
	 * @dataProvider provideBadTokenSessions
	 */
	public function testExecute__badToken( callable $session, string $excepMsg, ?string $token ) {
		$session = $session( $this );
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

	public function doTestRunCommandError(
		StatusValue $commandStatus,
		string $expectedErrorMsg,
		int $expectedCode
	) {
		$editEventCommand = $this->createMock( EditEventCommand::class );
		$editEventCommand->method( 'doEditIfAllowed' )->willReturn( $commandStatus );
		$handler = $this->newHandler( null, $editEventCommand );
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

	public function testRun__genericCommandError() {
		$validationError = 'some-command-error';
		$commandStatus = StatusValue::newFatal( $validationError );
		$this->doTestRunCommandError( $commandStatus, $validationError, 400 );
	}

	public function testRun__permissionError() {
		$permissionError = 'some-permission-error';
		$commandStatus = PermissionStatus::newFatal( $permissionError );
		$this->doTestRunCommandError( $commandStatus, $permissionError, 403 );
	}

	public function testRun__successful() {
		$handler = $this->newHandler();
		$request = new RequestData( $this->getRequestData( [ 'foo' ] ) );
		$performer = $this->mockRegisteredUltimateAuthority();
		$resp = $this->executeHandler( $handler, $request, [], [], [], [], $performer );
		$this->assertSame( 204, $resp->getStatusCode() );
	}

	public function testRun__nonLocalWikiError() {
		$page = $this->createMock( MWPageProxy::class );
		$page->method( 'getWikiId' )->willReturn( 'anotherwiki' );
		$request = new RequestData( $this->getRequestData( [ 'foo' ] ) );
		$performer = $this->mockRegisteredUltimateAuthority();
		try {
			$handler = $this->newHandler( null, null, $page );
			$this->executeHandler( $handler, $request, [], [], [], [], $performer );
			$this->fail( 'No exception thrown' );
		} catch ( LocalizedHttpException $e ) {
			$this->assertSame( 400, $e->getCode() );
			$this->assertSame(
				'campaignevents-rest-set-organizers-nonlocal-error-message',
				$e->getMessageValue()->getKey()
			);
		}
	}
}
