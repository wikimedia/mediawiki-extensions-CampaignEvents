<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Rest;

use Generator;
use HashConfig;
use MediaWiki\Extension\CampaignEvents\Messaging\CampaignsUserMailer;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\Rest\EmailUsersHandler;
use MediaWiki\Rest\HttpException;
use MediaWiki\Rest\RequestData;
use MediaWiki\Session\Session;
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
		'invert_users' => "false"
	];

	private function newHandler(
		PermissionChecker $permissionsChecker = null,
		CampaignsUserMailer $campaignsUserMailer = null,
		ParticipantsStore $participantsStore = null,
		bool $featureEnabled = false
	): EmailUsersHandler {
		return new EmailUsersHandler(
			$permissionsChecker ?? $this->createMock( PermissionChecker::class ),
			$campaignsUserMailer ?? $this->createMock( CampaignsUserMailer::class ),
			$participantsStore ?? $this->createMock( ParticipantsStore::class ),
			new HashConfig( [ 'CampaignEventsEnableEmail' => $featureEnabled ] )
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

	public function testRun__featureDisabled() {
		$handler = $this->newHandler();
		$this->expectException( HttpException::class );
		$this->expectExceptionMessage( 'This endpoint is not enabled on this wiki' );
		$this->expectExceptionCode( 421 );
		$this->executeHandler( $handler, new RequestData(
			$this->getRequestData( self::REQ_DATA )
			)
		);
	}

	/**
	 * @dataProvider provideBadTokenSessions
	 */
	public function testExecute__badToken( Session $session, string $exceptionMsg, ?string $token ) {
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
		$handler = $this->newHandler( $permissionCheckerMock, null, null, true );
		$request = new RequestData(
			$this->getRequestData( self::REQ_DATA )
		);
		$performer = $this->mockRegisteredUltimateAuthority();
		$respData = $this->executeHandlerAndGetBodyData( $handler, $request, [], [], [], [], $performer );
		$this->assertSame( $expectedResp, $respData );
	}

	public function provideRunData(): Generator {
		yield 'No participants selected' => [ [ 'sent' => 0 ] ];
	}
}
