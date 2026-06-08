<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Rest;

use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\CampaignEvents\Rest\PatchWorklistPagesHandler;
use MediaWiki\Extension\CampaignEvents\Worklist\WorklistArticleHelper;
use MediaWiki\Permissions\PermissionStatus;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWiki\Title\TitleValue;
use MediaWikiUnitTestCase;
use StatusValue;

/**
 * @group Test
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\PatchWorklistPagesHandler
 */
class PatchWorklistPagesHandlerTest extends MediaWikiUnitTestCase {
	use HandlerTestTrait;
	use CSRFTestHelperTrait;

	private const WIKI = 'enwiki';
	private const TITLE = 'Fermat';

	private function getRequestData( ?array $body = null ): array {
		return [
			'method' => 'PATCH',
			'pathParams' => [ 'title' => 'Event1/Worklist' ],
			'headers' => [ 'Content-Type' => 'application/json' ],
			'bodyContents' => json_encode(
				( $body ?? [ 'add' => [ self::WIKI => [ self::TITLE ] ] ] ) + [ 'token' => 'test-token' ]
			),
		];
	}

	private function newHandler(
		?WorklistArticleHelper $helper = null,
		bool $worklistsEnabled = true
	): PatchWorklistPagesHandler {
		if ( $helper === null ) {
			$helper = $this->createMock( WorklistArticleHelper::class );
			$helper->method( 'applyDelta' )->willReturn( StatusValue::newGood() );
		}
		// The 'title' path param is validated with TitleDef::PARAM_RETURN_OBJECT, so the validator
		// needs a TitleFactory service and hands a LinkTarget to run().
		$mockTitle = $this->createMock( Title::class );
		$mockTitle->method( 'getTitleValue' )->willReturn( new TitleValue( NS_MAIN, 'Event1/Worklist' ) );
		$validatorTitleFactory = $this->createMock( TitleFactory::class );
		$validatorTitleFactory->method( 'newFromText' )->willReturn( $mockTitle );
		$this->setService( 'TitleFactory', $validatorTitleFactory );

		// The handler resolves that LinkTarget back to a PageIdentity before calling the behaviour layer.
		$titleFactory = $this->createMock( TitleFactory::class );
		$titleFactory->method( 'newFromLinkTarget' )->willReturn( $this->createMock( Title::class ) );
		$config = new HashConfig( [ 'CampaignEventsEnableWorklists' => $worklistsEnabled ] );

		return new PatchWorklistPagesHandler( $helper, $titleFactory, $config );
	}

	/**
	 * @dataProvider provideBadTokenSessions
	 */
	public function testRun__badToken( callable $session, string $excepMsg, ?string $token ) {
		$session = $session( $this );
		$this->assertCorrectBadTokenBehaviour(
			$this->newHandler(),
			$this->getRequestData(),
			$session,
			$token,
			$excepMsg
		);
	}

	public function testAdd_returns204(): void {
		$helper = $this->createMock( WorklistArticleHelper::class );
		$helper->expects( $this->once() )
			->method( 'applyDelta' )
			->with( $this->anything(), [ self::WIKI => [ self::TITLE ] ], [] )
			->willReturn( StatusValue::newGood() );

		$response = $this->executeHandler(
			$this->newHandler( $helper ),
			new RequestData( $this->getRequestData() )
		);
		$this->assertSame( 204, $response->getStatusCode() );
	}

	public function testRemove_returns204(): void {
		$helper = $this->createMock( WorklistArticleHelper::class );
		$helper->expects( $this->once() )
			->method( 'applyDelta' )
			->with( $this->anything(), [], [ self::WIKI => [ self::TITLE ] ] )
			->willReturn( StatusValue::newGood() );

		$response = $this->executeHandler(
			$this->newHandler( $helper ),
			new RequestData( $this->getRequestData( [ 'remove' => [ self::WIKI => [ self::TITLE ] ] ] ) )
		);
		$this->assertSame( 204, $response->getStatusCode() );
	}

	public function testInvalidArticle_returns400(): void {
		$helper = $this->createMock( WorklistArticleHelper::class );
		$helper->method( 'applyDelta' )->willReturn(
			StatusValue::newFatal( 'campaignevents-worklist-content-invalid-title', self::WIKI, self::TITLE )
		);

		$this->expectException( LocalizedHttpException::class );
		$this->expectExceptionCode( 400 );
		$this->executeHandler( $this->newHandler( $helper ), new RequestData( $this->getRequestData() ) );
	}

	public function testForbidden_returns403(): void {
		$helper = $this->createMock( WorklistArticleHelper::class );
		$helper->method( 'applyDelta' )->willReturn(
			PermissionStatus::newFatal( 'campaignevents-worklist-edit-permission-denied' )
		);

		$this->expectException( LocalizedHttpException::class );
		$this->expectExceptionCode( 403 );
		$this->executeHandler( $this->newHandler( $helper ), new RequestData( $this->getRequestData() ) );
	}

	public function testFeatureDisabled_returns404(): void {
		$helper = $this->createMock( WorklistArticleHelper::class );
		$helper->expects( $this->never() )->method( 'applyDelta' );

		$this->expectException( LocalizedHttpException::class );
		$this->expectExceptionMessage( 'campaignevents-rest-event-not-found' );
		$this->expectExceptionCode( 404 );
		$this->executeHandler(
			$this->newHandler( $helper, false ),
			new RequestData( $this->getRequestData() )
		);
	}
}
