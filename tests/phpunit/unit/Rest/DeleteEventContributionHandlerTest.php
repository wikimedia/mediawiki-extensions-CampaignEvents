<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Rest;

use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContribution;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionStore;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\Rest\DeleteEventContributionHandler;
use MediaWiki\Rest\HttpException;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWikiUnitTestCase;

/**
 * @group Test
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\DeleteEventContributionHandler
 */
class DeleteEventContributionHandlerTest extends MediaWikiUnitTestCase {
	use HandlerTestTrait;
	use CSRFTestHelperTrait;

	private function getRequestData(): array {
		return [
			'method' => 'DELETE',
			'pathParams' => [ 'id' => 1 ],
			'headers' => [ 'Content-Type' => 'application/json' ],
			'bodyContents' => json_encode( [ 'token' => 'test-token' ] )
		];
	}

	private function newHandler(
		?EventContributionStore $store = null,
		?PermissionChecker $perm = null,
		?IEventLookup $lookup = null,
		?CampaignsCentralUserLookup $central = null,
		?HashConfig $config = null
	): DeleteEventContributionHandler {
		$store ??= $this->createMock( EventContributionStore::class );
		$perm ??= $this->createMock( PermissionChecker::class );
		$lookup ??= $this->createMock( IEventLookup::class );
		$central ??= $this->createMock( CampaignsCentralUserLookup::class );
		$config ??= new HashConfig( [ 'CampaignEventsEnableContributionTracking' => true ] );

		return new DeleteEventContributionHandler( $store, $perm, $lookup, $central, $config );
	}

	/**
	 * @dataProvider provideBadTokenSessions
	 */
	public function testRun__badToken( callable $session, string $excepMsg, ?string $token ) {
		$session = $session( $this );
		$handler = $this->newHandler();

		$req = $this->getRequestData();
		$body = $token === null ? [] : [ 'token' => $token ];
		$req['bodyContents'] = json_encode( $body );

		$this->assertCorrectBadTokenBehaviour(
			$handler,
			$req,
			$session,
			$token,
			$excepMsg
		);
	}

	public function testFeatureDisabled_returns400() {
		$config = new HashConfig( [ 'CampaignEventsEnableContributionTracking' => false ] );
		$handler = $this->newHandler( config: $config );

		$this->expectException( HttpException::class );
		$this->expectExceptionMessage( 'This feature is not enabled on this wiki' );
		$this->expectExceptionCode( 400 );

		$this->executeHandler( $handler, new RequestData( $this->getRequestData() ) );
	}

	public function testContributionNotFound_returns404() {
		$store = $this->createMock( EventContributionStore::class );
		$store->method( 'getByID' )->willReturn( null );

		$handler = $this->newHandler( $store );

		$this->expectException( LocalizedHttpException::class );
		$this->expectExceptionMessage( 'campaignevents-rest-contribution-not-found' );
		$this->expectExceptionCode( 404 );

		$this->executeHandler( $handler, new RequestData( $this->getRequestData() ) );
	}

	public function testPermissionDenied_returns403() {
		$contrib = $this->createMock( EventContribution::class );
		$contrib->method( 'getEventID' )->willReturn( 123 );
		$contrib->method( 'getUserID' )->willReturn( 456 );

		$store = $this->createMock( EventContributionStore::class );
		$store->method( 'getByID' )->willReturn( $contrib );

		$event = $this->createMock( ExistingEventRegistration::class );
		$lookup = $this->createMock( IEventLookup::class );
		$lookup->method( 'getEventByID' )->willReturn( $event );

		$perm = $this->createMock( PermissionChecker::class );
		$perm->method( 'userCanDeleteContribution' )
			->willReturn( false );

		$centralUser = $this->createMock( \MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser::class );
		$centralUser->method( 'getCentralID' )->willReturn( 789 );

		$central = $this->createMock( CampaignsCentralUserLookup::class );
		$central->method( 'newFromAuthority' )->willReturn( $centralUser );

		$handler = $this->newHandler( $store, $perm, $lookup, $central );

		$this->expectException( LocalizedHttpException::class );
		$this->expectExceptionMessage( 'campaignevents-rest-delete-contribution-permission-denied' );
		$this->expectExceptionCode( 403 );

		$this->executeHandler( $handler, new RequestData( $this->getRequestData() ) );
	}

	public function testSuccess_returns204() {
		$contrib = $this->createMock( EventContribution::class );
		$contrib->method( 'getEventID' )->willReturn( 123 );

		$store = $this->createMock( EventContributionStore::class );
		$store->method( 'getByID' )->willReturn( $contrib );
		$store->expects( $this->once() )->method( 'deleteByID' )->with( 1 );

		$event = $this->createMock( ExistingEventRegistration::class );
		$lookup = $this->createMock( IEventLookup::class );
		$lookup->method( 'getEventByID' )->willReturn( $event );

		$perm = $this->createMock( PermissionChecker::class );
		$perm->method( 'userCanDeleteContribution' )
			->willReturn( true );

		$handler = $this->newHandler( $store, $perm, $lookup );

		$response = $this->executeHandler( $handler, new RequestData( $this->getRequestData() ) );
		$this->assertSame( 204, $response->getStatusCode() );
	}
}
