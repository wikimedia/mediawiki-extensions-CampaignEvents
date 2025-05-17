<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Rest;

use Generator;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\Messaging\CampaignsUserMailer;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWPageProxy;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\Rest\EmailUsersHandler;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWikiUnitTestCase;

/**
 * @group Test
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\EmailUsersHandler
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\EventIDParamTrait
 */
class EmailUsersHandlerTest extends MediaWikiUnitTestCase {
	use HandlerTestTrait;
	use CSRFTestHelperTrait;

	private const REQ_DATA = [
		"message" => "Test this with a long message",
		"subject" => "Test",
		'invert_users' => false
	];

	private function newHandler(
		?PermissionChecker $permissionsChecker = null,
		?CampaignsUserMailer $campaignsUserMailer = null,
		?ParticipantsStore $participantsStore = null,
		?IEventLookup $eventLookup = null,
		?MWPageProxy $page = null
	): EmailUsersHandler {
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
		return new EmailUsersHandler(
			$permissionsChecker ?? $this->createMock( PermissionChecker::class ),
			$campaignsUserMailer ?? $this->createMock( CampaignsUserMailer::class ),
			$participantsStore ?? $this->createMock( ParticipantsStore::class ),
				$eventLookup,
		);
	}

	/**
	 * @param string[] $data
	 * @return array
	 */
	private function getRequestData( array $data ): array {
		return [
			'method' => 'POST',
			'pathParams' => [ 'id' => 7 ],
			'bodyContents' => json_encode( $data ),
			'headers' => [ 'Content-Type' => 'application/json' ],
		];
	}

	/**
	 * @dataProvider provideBadTokenSessions
	 */
	public function testExecute__badToken( callable $session, string $exceptionMsg, ?string $token ) {
		$session = $session( $this );
		$this->assertCorrectBadTokenBehaviour(
			$this->newHandler(),
			$this->getRequestData( self::REQ_DATA ),
			$session,
			$token,
			$exceptionMsg
		);
	}

	/**
	 * @dataProvider provideRunData
	 */
	public function testRun_success( array $expectedResp ) {
		$permissionCheckerMock = $this->createMock( PermissionChecker::class );
		$permissionCheckerMock->method( "userCanEmailParticipants" )->willReturn( true );
		$handler = $this->newHandler( $permissionCheckerMock );
		$request = new RequestData(
			$this->getRequestData( self::REQ_DATA )
		);
		$performer = $this->mockRegisteredUltimateAuthority();
		$respData = $this->executeHandlerAndGetBodyData( $handler, $request, [], [], [], [], $performer );
		$this->assertSame( $expectedResp, $respData );
	}

	public static function provideRunData(): Generator {
		yield 'No participants selected' => [ [ 'sent' => 0 ] ];
	}

	public function testRun__nonLocalWikiError() {
		$permissionCheckerMock = $this->createMock( PermissionChecker::class );
		$permissionCheckerMock->method( "userCanEmailParticipants" )->willReturn( true );
		$page = $this->createMock( MWPageProxy::class );
		$page->method( 'getWikiId' )->willReturn( 'anotherwiki' );
		$request = new RequestData(
			$this->getRequestData( self::REQ_DATA )
		);
		$performer = $this->mockRegisteredUltimateAuthority();
		try {
			$handler = $this->newHandler( $permissionCheckerMock, null, null, null, $page );
			$this->executeHandlerAndGetBodyData( $handler, $request, [], [], [], [], $performer );
			$this->fail( 'No exception thrown' );
		} catch ( LocalizedHttpException $e ) {
			$this->assertSame( 400, $e->getCode() );
			$this->assertSame(
				'campaignevents-rest-email-participants-nonlocal-error-message',
				$e->getMessageValue()->getKey()
			);
		}
	}
}
