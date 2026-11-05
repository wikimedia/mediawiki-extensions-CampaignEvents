<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Rest;

use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\CampaignEvents\MWEntity\WikiLookup;
use MediaWiki\Extension\CampaignEvents\Rest\GetPagesQualityHandler;
use MediaWiki\Extension\CampaignEvents\Worklist\ArticleQualityLookup;
use MediaWiki\MainConfigNames;
use MediaWiki\Permissions\Authority;
use MediaWiki\Rest\HttpException;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWiki\Tests\Unit\Permissions\MockAuthorityTrait;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;

/**
 * @group Test
 * @group Database
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\GetPagesQualityHandler
 */
class GetPagesQualityHandlerTest extends MediaWikiIntegrationTestCase {
	use HandlerTestTrait;
	use MockAuthorityTrait;

	/** A wiki that is not the local wiki. The endpoint answers for any wiki that CampaignEvents knows. */
	private const WIKI = 'awiki';

	private static function reqData( array $titles, string $wiki = self::WIKI ): RequestData {
		return new RequestData( [
			'queryParams' => [ 'wiki' => $wiki, 'titles' => implode( '|', $titles ) ],
		] );
	}

	private static function scoreOf( float $score, array $elements = [] ): array {
		return [ 'score' => $score, 'label' => 'C', 'elements' => $elements ];
	}

	private function namedAuthority(): Authority {
		return $this->mockRegisteredUltimateAuthority();
	}

	/**
	 * A quality lookup where only the model call is replaced. Thus the response comes from the
	 * real code.
	 *
	 * @param array $quality What the model reports, keyed by prefixed text
	 */
	private function newQualityLookup( array $quality ): ArticleQualityLookup {
		$qualityLookup = $this->getMockBuilder( ArticleQualityLookup::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'getQualityForArticles' ] )
			->getMock();
		$qualityLookup->method( 'getQualityForArticles' )->willReturnCallback(
			static function ( string $wiki, array $titles ) use ( $quality ): array {
				return array_intersect_key( $quality, array_fill_keys( $titles, true ) );
			}
		);
		return $qualityLookup;
	}

	/**
	 * A user factory whose user is rate limited or not.
	 */
	private function newUserFactory( bool $limited = false ): UserFactory {
		$user = $this->createMock( User::class );
		$user->method( 'pingLimiter' )->willReturn( $limited );
		$userFactory = $this->createMock( UserFactory::class );
		$userFactory->method( 'newFromAuthority' )->willReturn( $user );
		return $userFactory;
	}

	private function newHandler(
		ArticleQualityLookup $qualityLookup,
		UserFactory $userFactory,
		bool $enabled = true
	): GetPagesQualityHandler {
		$wikiLookup = $this->createMock( WikiLookup::class );
		$wikiLookup->method( 'getAllWikis' )->willReturn( [ WikiMap::getCurrentWikiId(), self::WIKI ] );

		return new GetPagesQualityHandler(
			new HashConfig( [ 'CampaignEventsEnableReadingListsCardElements' => $enabled ] ),
			$qualityLookup,
			$wikiLookup,
			$userFactory
		);
	}

	public function testRun__returnsScoreLabelAndElements(): void {
		$handler = $this->newHandler(
			$this->newQualityLookup( [ 'Beavers' => self::scoreOf( 0.42, [ 'refs' => 0.3 ] ) ] ),
			$this->newUserFactory()
		);

		$respData = $this->executeHandlerAndGetBodyData(
			$handler,
			self::reqData( [ 'Beavers' ] ),
			authority: $this->namedAuthority()
		);

		$this->assertSame(
			[ [
				'wiki' => self::WIKI,
				'title' => 'Beavers',
				'score' => 0.42,
				'label' => 'C',
				'elements' => [ 'refs' => 0.3 ],
			] ],
			$respData['articles']
		);
	}

	public function testRun__doesNotCheckThatThePagesAreInAList(): void {
		// The feature is experimental: any title is answered for, within the rate limit.
		$handler = $this->newHandler(
			$this->newQualityLookup( [
				'Beavers' => self::scoreOf( 0.4 ),
				'Something else entirely' => self::scoreOf( 0.9 ),
			] ),
			$this->newUserFactory()
		);

		$respData = $this->executeHandlerAndGetBodyData(
			$handler,
			self::reqData( [ 'Beavers', 'Something else entirely' ] ),
			authority: $this->namedAuthority()
		);

		$this->assertSame(
			[ 'Beavers', 'Something else entirely' ],
			array_column( $respData['articles'], 'title' )
		);
	}

	public function testRun__leavesOutArticlesTheModelCannotScore(): void {
		$handler = $this->newHandler(
			// Nothing for Otters.
			$this->newQualityLookup( [ 'Beavers' => self::scoreOf( 0.4 ) ] ),
			$this->newUserFactory()
		);

		$respData = $this->executeHandlerAndGetBodyData(
			$handler,
			self::reqData( [ 'Beavers', 'Otters' ] ),
			authority: $this->namedAuthority()
		);

		$this->assertSame( [ 'Beavers' ], array_column( $respData['articles'], 'title' ) );
	}

	public function testRun__rejectsAnUnknownWiki(): void {
		$handler = $this->newHandler(
			$this->createNoOpMock( ArticleQualityLookup::class ),
			$this->newUserFactory()
		);

		$this->expectException( LocalizedHttpException::class );
		$this->expectExceptionCode( 400 );
		$this->executeHandler(
			$handler,
			self::reqData( [ 'Beavers' ], 'some-other-wiki' ),
			authority: $this->namedAuthority()
		);
	}

	public function testRun__flagDisabled(): void {
		// Neither the rate limit nor the model is used when the flag is off.
		$handler = $this->newHandler(
			$this->createNoOpMock( ArticleQualityLookup::class ),
			$this->createNoOpMock( UserFactory::class ),
			false
		);

		$this->expectException( HttpException::class );
		$this->expectExceptionCode( 404 );
		$this->executeHandler( $handler, self::reqData( [ 'Beavers' ] ), authority: $this->namedAuthority() );
	}

	public static function provideNotNamed(): iterable {
		yield 'anonymous' => [ 'anon' ];
		yield 'temporary' => [ 'temp' ];
	}

	/**
	 * @dataProvider provideNotNamed
	 */
	public function testRun__notNamed( string $type ): void {
		// Neither the rate limit nor the model is used for a caller that is not named.
		$handler = $this->newHandler(
			$this->createNoOpMock( ArticleQualityLookup::class ),
			$this->createNoOpMock( UserFactory::class )
		);
		$authority = $type === 'anon' ? $this->mockAnonUltimateAuthority() : $this->mockTempUltimateAuthority();

		$this->expectException( LocalizedHttpException::class );
		$this->expectExceptionCode( 401 );
		$this->executeHandler( $handler, self::reqData( [ 'Beavers' ] ), authority: $authority );
	}

	public function testRun__countsEachDistinctTitleTowardsTheRateLimit(): void {
		$user = $this->createMock( User::class );
		$user->expects( $this->once() )
			->method( 'pingLimiter' )
			->with( GetPagesQualityHandler::RATE_LIMIT_ACTION, 2 )
			->willReturn( false );
		$userFactory = $this->createMock( UserFactory::class );
		$userFactory->method( 'newFromAuthority' )->willReturn( $user );

		$handler = $this->newHandler( $this->newQualityLookup( [] ), $userFactory );

		$this->executeHandlerAndGetBodyData(
			$handler,
			self::reqData( [ 'Beavers', 'Otters', 'Beavers' ] ),
			authority: $this->namedAuthority()
		);
	}

	public function testRun__rateLimited(): void {
		// The model is not called when the caller is over the limit.
		$handler = $this->newHandler(
			$this->createNoOpMock( ArticleQualityLookup::class ),
			$this->newUserFactory( true )
		);

		$this->expectException( LocalizedHttpException::class );
		$this->expectExceptionCode( 429 );
		$this->executeHandler( $handler, self::reqData( [ 'Beavers' ] ), authority: $this->namedAuthority() );
	}

	public function testRun__rateLimitedWithTheRealLimiter(): void {
		// Use the real rate limiter, to make sure that the configuration key and the count work.
		$this->overrideConfigValue( MainConfigNames::RateLimits, [
			GetPagesQualityHandler::RATE_LIMIT_ACTION => [
				'ip-all' => [ 3, 60 ],
				'user' => [ 3, 60 ],
			],
		] );
		$authority = $this->getTestUser()->getAuthority();
		$userFactory = $this->getServiceContainer()->getUserFactory();
		$qualityLookup = $this->newQualityLookup( [] );

		// Two titles: under the limit.
		$this->executeHandler(
			$this->newHandler( $qualityLookup, $userFactory ),
			self::reqData( [ 'Beavers', 'Otters' ] ),
			authority: $authority
		);

		// Two more titles: over the limit of three.
		try {
			$this->executeHandler(
				$this->newHandler( $qualityLookup, $userFactory ),
				self::reqData( [ 'Badgers', 'Stoats' ] ),
				authority: $authority
			);
			$this->fail( 'Expected a 429 response' );
		} catch ( LocalizedHttpException $e ) {
			$this->assertSame( 429, $e->getCode() );
		}
	}
}
