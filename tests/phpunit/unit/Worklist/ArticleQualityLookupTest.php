<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Worklist;

use Generator;
use MediaWiki\Extension\CampaignEvents\Worklist\ArticleQualityLookup;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Site\MediaWikiSite;
use MediaWiki\Site\SiteLookup;
use MediaWikiUnitTestCase;
use Psr\Log\NullLogger;
use Wikimedia\Http\MultiHttpClient;
use Wikimedia\ObjectCache\HashBagOStuff;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @covers \MediaWiki\Extension\CampaignEvents\Worklist\ArticleQualityLookup
 */
class ArticleQualityLookupTest extends MediaWikiUnitTestCase {
	private const WIKI = 'awiki';
	private const ENDPOINT = 'https://inference.example.org/v1/models/articlequality:predict';

	/** @var array The requests the multi client was handed, so the URL and headers can be read */
	private array $sentRequests = [];

	/** @var list<list<string>> Titles sent to the model, one list per batch of requests */
	private array $batches = [];

	/** The clock, for both the cache made by newCache() and ConvertibleTimestamp. */
	private float $now = 1_000_000.0;

	/** A trimmed articlequality response, in the shape the model really sends. */
	private static function modelResponse(
		float $score,
		string $label,
		array $normalized,
		?string $version = null
	): string {
		$response = [
			'label' => $label,
			'features' => [ 'raw' => [], 'normalized' => $normalized ],
			'score' => $score,
			'model_name' => 'articlequality',
		];
		if ( $version !== null ) {
			$response['model_version'] = $version;
		}
		return json_encode( $response );
	}

	private static function revisionsResponse( array $titleToRevID ): string {
		$pages = [];
		foreach ( $titleToRevID as $title => $revID ) {
			$pages[] = $revID === null
				? [ 'title' => $title, 'missing' => true ]
				: [ 'title' => $title, 'revisions' => [ [ 'revid' => $revID ] ] ];
		}
		return json_encode( [ 'query' => [ 'pages' => $pages ] ] );
	}

	/**
	 * @param string|false $revisionsResponse Body of the action API revisions query
	 * @param array $modelResponses Model responses keyed by the title they answer for
	 * @param WANObjectCache|null $cache
	 * @param string|null $langCode Language of the wiki, or null for a wiki the lookup cannot find
	 * @param string|null $endpoint Inference endpoint, or null for a wiki that configures none
	 * @param string|null $hostHeader Host header to route on, or null to send none
	 * @param callable|null $whileRequesting Run while the model requests are in flight, to stand
	 *   in for another request doing its own work at the same time
	 * @return ArticleQualityLookup
	 */
	private function newLookup(
		$revisionsResponse,
		array $modelResponses,
		?WANObjectCache $cache = null,
		?string $langCode = 'en',
		?string $endpoint = self::ENDPOINT,
		?string $hostHeader = null,
		?callable $whileRequesting = null
	): ArticleQualityLookup {
		$this->sentRequests = [];
		$multiClient = $this->createMock( MultiHttpClient::class );
		$multiClient->method( 'runMulti' )->willReturnCallback(
			function ( array $reqs ) use ( $modelResponses, $whileRequesting ): array {
				$this->batches[] = array_keys( $reqs );
				if ( $whileRequesting ) {
					$whileRequesting();
				}
				// As MultiHttpClient really answers: each request back, with its own reply
				// under 'response'. Getting this wrong in the mock hid a bug that only
				// showed up against the live service.
				$this->sentRequests = $reqs;
				foreach ( $reqs as $title => &$req ) {
					$req['response'] = array_key_exists( $title, $modelResponses )
						? [ 'code' => 200, 'reason' => 'OK', 'headers' => [],
							'body' => $modelResponses[$title], 'error' => '' ]
						: [ 'code' => 503, 'reason' => 'Service Unavailable', 'headers' => [],
							'body' => '', 'error' => 'upstream error' ];
				}
				unset( $req );
				return $reqs;
			}
		);

		$httpRequestFactory = $this->createMock( HttpRequestFactory::class );
		$httpRequestFactory->method( 'get' )->willReturn( $revisionsResponse );
		$httpRequestFactory->method( 'createMultiClient' )->willReturn( $multiClient );
		$httpRequestFactory->method( 'getUserAgent' )->willReturn( 'TestAgent' );

		$siteLookup = $this->createMock( SiteLookup::class );
		if ( $langCode === null ) {
			$siteLookup->method( 'getSite' )->willReturn( null );
		} else {
			$site = $this->createMock( MediaWikiSite::class );
			$site->method( 'getLanguageCode' )->willReturn( $langCode );
			$site->method( 'getFileUrl' )->willReturn( 'https://a.example.org/w/api.php' );
			$siteLookup->method( 'getSite' )->willReturn( $site );
		}

		return new ArticleQualityLookup(
			$httpRequestFactory,
			$cache ?? new WANObjectCache( [ 'cache' => new HashBagOStuff() ] ),
			$siteLookup,
			new NullLogger(),
			$endpoint,
			$hostHeader
		);
	}

	/** @dataProvider provideNoEndpointConfigured */
	public function testGetQualityForArticles__noEndpointConfigured( ?string $endpoint ): void {
		// A wiki with no inference service shows no quality rather than failing: nothing is
		// asked for, and the cards are complete without it.
		$lookup = $this->newLookup(
			self::revisionsResponse( [ 'Beavers' => 17 ] ),
			[ 'Beavers' => self::modelResponse( 0.9, 'GA', [] ) ],
			null,
			'en',
			$endpoint
		);

		$this->assertSame( [], $lookup->getQualityForArticles( 'awiki', [ 'Beavers' ] ) );
		$this->assertSame( [], $this->sentRequests );
	}

	public static function provideNoEndpointConfigured(): Generator {
		yield 'Unset' => [ null ];
		// Blanked rather than unset, which would otherwise be requested as an empty URL.
		yield 'Blanked' => [ '' ];
	}

	public function testGetQualityForArticles__sendsTheConfiguredEndpointAndHost(): void {
		// An inference service may route on the Host header and not answer without it.
		$lookup = $this->newLookup(
			self::revisionsResponse( [ 'Beavers' => 17 ] ),
			[ 'Beavers' => self::modelResponse( 0.9, 'GA', [] ) ],
			null,
			'en',
			self::ENDPOINT,
			'articlequality.example.wikimedia.org'
		);

		$lookup->getQualityForArticles( 'awiki', [ 'Beavers' ] );

		$sent = reset( $this->sentRequests );
		$this->assertSame( self::ENDPOINT, $sent['url'] );
		$this->assertSame( 'articlequality.example.wikimedia.org', $sent['headers']['Host'] );
	}

	/** @dataProvider provideNoHostHeader */
	public function testGetQualityForArticles__sendsNoHostHeaderWhenNotConfigured( ?string $hostHeader ): void {
		// The public API gateway needs none, and sending an empty one would misroute.
		$lookup = $this->newLookup(
			self::revisionsResponse( [ 'Beavers' => 17 ] ),
			[ 'Beavers' => self::modelResponse( 0.9, 'GA', [] ) ],
			null,
			'en',
			self::ENDPOINT,
			$hostHeader
		);

		$lookup->getQualityForArticles( 'awiki', [ 'Beavers' ] );

		$this->assertArrayNotHasKey( 'Host', reset( $this->sentRequests )['headers'] );
	}

	public static function provideNoHostHeader(): Generator {
		yield 'Unset' => [ null ];
		yield 'Blanked' => [ '' ];
	}

	/** A cache whose clock, like ConvertibleTimestamp's, is $this->now. */
	private function newCache(): WANObjectCache {
		$cache = new WANObjectCache( [ 'cache' => new HashBagOStuff() ] );
		$cache->setMockTime( $this->now );
		ConvertibleTimestamp::setFakeTime( fn () => (int)$this->now );
		return $cache;
	}

	/**
	 * Look the given articles up, with the model scoring every one of them at $score.
	 *
	 * @param WANObjectCache $cache
	 * @param array<string,int> $titleToRevID
	 * @param float $score
	 * @param string $version The version the model reports
	 * @return array
	 */
	private function scoreAll( WANObjectCache $cache, array $titleToRevID, float $score, string $version ): array {
		$titles = array_keys( $titleToRevID );
		return $this->newLookup(
			self::revisionsResponse( $titleToRevID ),
			array_fill_keys( $titles, self::modelResponse( $score, 'C', [], $version ) ),
			$cache
		)->getQualityForArticles( self::WIKI, $titles );
	}

	/** Look the given articles up with the model returning nothing, so only the cache can answer. */
	private function lookUpCached( WANObjectCache $cache, array $titleToRevID ): array {
		return $this->newLookup( self::revisionsResponse( $titleToRevID ), [], $cache )
			->getQualityForArticles( self::WIKI, array_keys( $titleToRevID ) );
	}

	public function testGetQualityForArticles__returnsScoreAndElements(): void {
		$lookup = $this->newLookup(
			self::revisionsResponse( [ 'Beavers' => 17 ] ),
			[ 'Beavers' => self::modelResponse( 0.958, 'GA', [ 'refs' => 1, 'infobox' => true ] ) ]
		);

		$this->assertSame(
			[ 'Beavers' => [
				'score' => 0.958,
				'label' => 'GA',
				'elements' => [ 'refs' => 1, 'infobox' => true ],
			] ],
			$lookup->getQualityForArticles( self::WIKI, [ 'Beavers' ] )
		);
	}

	public function testGetQualityForArticles__skipsArticlesTheModelCannotScore(): void {
		$lookup = $this->newLookup(
			self::revisionsResponse( [ 'Beavers' => 17, 'Otters' => 18 ] ),
			// No entry for Otters, so the mocked client answers 503 for it.
			[ 'Beavers' => self::modelResponse( 0.5, 'C', [ 'refs' => 0.4 ] ) ]
		);

		// The reader still gets the article that could be scored, rather than an error for both.
		$this->assertSame(
			[ 'Beavers' ],
			array_keys( $lookup->getQualityForArticles( self::WIKI, [ 'Beavers', 'Otters' ] ) )
		);
	}

	public function testGetQualityForArticles__skipsPagesWithNoRevision(): void {
		$lookup = $this->newLookup(
			// A page still to be created has nothing to score.
			self::revisionsResponse( [ 'Beavers' => 17, 'Not yet written' => null ] ),
			[ 'Beavers' => self::modelResponse( 0.5, 'C', [] ) ]
		);

		$this->assertSame(
			[ 'Beavers' ],
			array_keys( $lookup->getQualityForArticles( self::WIKI, [ 'Beavers', 'Not yet written' ] ) )
		);
	}

	public function testGetQualityForArticles__cachesOnTheRevision(): void {
		$cache = new WANObjectCache( [ 'cache' => new HashBagOStuff() ] );
		$body = self::modelResponse( 0.7, 'B', [ 'refs' => 0.8 ] );

		$first = $this->newLookup( self::revisionsResponse( [ 'Beavers' => 17 ] ), [ 'Beavers' => $body ], $cache );
		$expected = $first->getQualityForArticles( self::WIKI, [ 'Beavers' ] );

		// The model answers nothing this time; the same revision must still produce the score.
		$second = $this->newLookup( self::revisionsResponse( [ 'Beavers' => 17 ] ), [], $cache );
		$this->assertSame( $expected, $second->getQualityForArticles( self::WIKI, [ 'Beavers' ] ) );
	}

	public function testGetQualityForArticles__aNewRevisionIsScoredAgain(): void {
		$cache = new WANObjectCache( [ 'cache' => new HashBagOStuff() ] );

		$first = $this->newLookup(
			self::revisionsResponse( [ 'Beavers' => 17 ] ),
			[ 'Beavers' => self::modelResponse( 0.2, 'Stub', [] ) ],
			$cache
		);
		$first->getQualityForArticles( self::WIKI, [ 'Beavers' ] );

		// The article has been edited, so the cached score for the old revision must not be used.
		$second = $this->newLookup(
			self::revisionsResponse( [ 'Beavers' => 18 ] ),
			[ 'Beavers' => self::modelResponse( 0.9, 'GA', [] ) ],
			$cache
		);
		$quality = $second->getQualityForArticles( self::WIKI, [ 'Beavers' ] );
		$this->assertSame( 0.9, $quality['Beavers']['score'] );
	}

	public function testGetQualityForArticles__anUnknownVersionIsLearnedFromOneArticle(): void {
		$lookup = $this->newLookup(
			self::revisionsResponse( [ 'Beavers' => 17, 'Otters' => 18, 'Voles' => 19 ] ),
			array_fill_keys( [ 'Beavers', 'Otters', 'Voles' ], self::modelResponse( 0.5, 'C', [], '1' ) )
		);

		// One article is scored on its own first, to learn the version, then the rest together.
		$quality = $lookup->getQualityForArticles( self::WIKI, [ 'Beavers', 'Otters', 'Voles' ] );
		$this->assertSame( [ [ 'Beavers' ], [ 'Otters', 'Voles' ] ], $this->batches );
		$this->assertSame( [ 'Beavers', 'Otters', 'Voles' ], array_keys( $quality ) );
	}

	public function testGetQualityForArticles__aKnownVersionIsNotLearnedAgain(): void {
		$cache = new WANObjectCache( [ 'cache' => new HashBagOStuff() ] );
		$this->newLookup(
			self::revisionsResponse( [ 'Beavers' => 17 ] ),
			[ 'Beavers' => self::modelResponse( 0.5, 'C', [], '1' ) ],
			$cache
		)->getQualityForArticles( self::WIKI, [ 'Beavers' ] );

		// The version is cached now, so new articles are all scored together.
		$this->batches = [];
		$this->newLookup(
			self::revisionsResponse( [ 'Otters' => 18, 'Voles' => 19 ] ),
			array_fill_keys( [ 'Otters', 'Voles' ], self::modelResponse( 0.5, 'C', [], '1' ) ),
			$cache
		)->getQualityForArticles( self::WIKI, [ 'Otters', 'Voles' ] );
		$this->assertSame( [ [ 'Otters', 'Voles' ] ], $this->batches );
	}

	public function testGetQualityForArticles__aFailedProbeFallsBackToScoringTheRest(): void {
		$lookup = $this->newLookup(
			self::revisionsResponse( [ 'Beavers' => 17, 'Otters' => 18, 'Voles' => 19 ] ),
			// No entry for Beavers, so the article tried first fails.
			[
				'Otters' => self::modelResponse( 0.5, 'C', [], '1' ),
				'Voles' => self::modelResponse( 0.6, 'C', [], '1' ),
			]
		);

		// That may be the article's problem rather than the model's, so the rest are asked about
		// together, as they would have been anyway, and none is asked about twice.
		$quality = $lookup->getQualityForArticles( self::WIKI, [ 'Beavers', 'Otters', 'Voles' ] );
		$this->assertSame( [ [ 'Beavers' ], [ 'Otters', 'Voles' ] ], $this->batches );
		$this->assertSame( [ 'Otters', 'Voles' ], array_keys( $quality ) );
	}

	public function testGetQualityForArticles__noVersionIsLearnedWhenNothingNeedsScoring(): void {
		$cache = new WANObjectCache( [ 'cache' => new HashBagOStuff() ] );
		$failing = $this->newLookup( self::revisionsResponse( [ 'Beavers' => 17 ] ), [], $cache );
		$failing->getQualityForArticles( self::WIKI, [ 'Beavers' ] );

		// Beavers has just failed and nothing else needs scoring, so nothing is asked about,
		// not even to learn the version.
		$this->batches = [];
		$this->newLookup(
			self::revisionsResponse( [ 'Beavers' => 17 ] ),
			[ 'Beavers' => self::modelResponse( 0.5, 'C', [], '1' ) ],
			$cache
		)->getQualityForArticles( self::WIKI, [ 'Beavers' ] );
		$this->assertSame( [], $this->batches );
	}

	public function testGetQualityForArticles__anEmptyVersionIsAVersion(): void {
		$cache = new WANObjectCache( [ 'cache' => new HashBagOStuff() ] );
		$this->newLookup(
			self::revisionsResponse( [ 'Beavers' => 17 ] ),
			// No version in the response.
			[ 'Beavers' => self::modelResponse( 0.5, 'C', [] ) ],
			$cache
		)->getQualityForArticles( self::WIKI, [ 'Beavers' ] );

		// So it is not learned again: the next articles are scored together.
		$this->batches = [];
		$this->newLookup(
			self::revisionsResponse( [ 'Otters' => 18, 'Voles' => 19 ] ),
			array_fill_keys( [ 'Otters', 'Voles' ], self::modelResponse( 0.5, 'C', [] ) ),
			$cache
		)->getQualityForArticles( self::WIKI, [ 'Otters', 'Voles' ] );
		$this->assertSame( [ [ 'Otters', 'Voles' ] ], $this->batches );
	}

	// Scores are cached under the version that produced them (step 2).

	public function testGetQualityForArticles__cachedScoresAreFoundUnderAKnownVersion(): void {
		$cache = $this->newCache();
		$this->scoreAll( $cache, [ 'Beavers' => 17, 'Otters' => 18 ], 0.4, '1' );

		$this->batches = [];
		$quality = $this->lookUpCached( $cache, [ 'Beavers' => 17, 'Otters' => 18 ] );
		$this->assertSame( [], $this->batches );
		$this->assertSame( [ 'Beavers', 'Otters' ], array_keys( $quality ) );
	}

	public function testGetQualityForArticles__aFailureDoesNotRemoveACachedScore(): void {
		$cache = $this->newCache();
		$this->scoreAll( $cache, [ 'Beavers' => 17 ], 0.2, '1' );

		// A day on, version 1 is no longer in use, so Beavers is asked about again, and fails.
		$this->now += WANObjectCache::TTL_DAY + 1;
		$this->assertSame( [], $this->lookUpCached( $cache, [ 'Beavers' => 17 ] ) );

		// Once the failure has expired and version 1 is heard from again, its score for Beavers
		// is still there: the failure was kept under a key of its own.
		$this->now += WANObjectCache::TTL_MINUTE * 11;
		$this->scoreAll( $cache, [ 'Otters' => 18 ], 0.5, '1' );
		$this->batches = [];
		$quality = $this->lookUpCached( $cache, [ 'Beavers' => 17 ] );
		$this->assertSame( [], $this->batches );
		$this->assertSame( 0.2, $quality['Beavers']['score'] );
	}

	// Every fresh score renews its version (step 3).

	public function testGetQualityForArticles__aVersionReturnedAgainStaysInUse(): void {
		$cache = $this->newCache();
		$this->scoreAll( $cache, [ 'Beavers' => 17 ], 0.2, '1' );

		// Version 1 is returned again, which renews it, not only when it was first seen.
		$this->now += WANObjectCache::TTL_HOUR * 20;
		$this->scoreAll( $cache, [ 'Otters' => 18 ], 0.5, '1' );

		// So more than a day after Beavers was scored, its score is still served.
		$this->now += WANObjectCache::TTL_HOUR * 10;
		$this->batches = [];
		$quality = $this->lookUpCached( $cache, [ 'Beavers' => 17 ] );
		$this->assertSame( [], $this->batches );
		$this->assertSame( 0.2, $quality['Beavers']['score'] );
	}

	public function testGetQualityForArticles__anUpdateMadeMeanwhileIsNotLost(): void {
		$cache = $this->newCache();
		$this->scoreAll( $cache, [ 'Beavers' => 17 ], 0.2, '1' );

		// While Otters is being scored, another request records version 2.
		$this->newLookup(
			self::revisionsResponse( [ 'Otters' => 18 ] ),
			[ 'Otters' => self::modelResponse( 0.5, 'C', [], '1' ) ],
			$cache,
			'en',
			self::ENDPOINT,
			null,
			function () use ( $cache ) {
				$this->scoreAll( $cache, [ 'Voles' => 19 ], 0.9, '2' );
			}
		)->getQualityForArticles( self::WIKI, [ 'Otters' ] );

		// Recording version 1 afterwards did not write over version 2, so Voles is still found.
		$this->batches = [];
		$quality = $this->lookUpCached( $cache, [ 'Voles' => 19 ] );
		$this->assertSame( [], $this->batches );
		$this->assertSame( 0.9, $quality['Voles']['score'] );
	}

	// A version not returned for a day is dropped (step 5), and so is the entry (point 8).

	public function testGetQualityForArticles__anOldVersionIsRetiredADayAfterItsLastResponse(): void {
		$cache = $this->newCache();
		$this->scoreAll( $cache, [ 'Beavers' => 17 ], 0.2, '1' );
		$this->now += WANObjectCache::TTL_HOUR;
		$this->scoreAll( $cache, [ 'Otters' => 18 ], 0.5, '2' );
		$newModel = [ 'Beavers' => self::modelResponse( 0.9, 'GA', [], '2' ) ];

		// Within a day of version 1's last response, its score is still served.
		$this->now += WANObjectCache::TTL_HOUR * 22;
		$quality = $this->newLookup( self::revisionsResponse( [ 'Beavers' => 17 ] ), $newModel, $cache )
			->getQualityForArticles( self::WIKI, [ 'Beavers' ] );
		$this->assertSame( 0.2, $quality['Beavers']['score'] );

		// After that it is not. Version 2 is still in use, so no version needs learning: the
		// article is simply scored again, by the new model.
		$this->now += WANObjectCache::TTL_MINUTE * 90;
		$this->batches = [];
		$quality = $this->newLookup( self::revisionsResponse( [ 'Beavers' => 17 ] ), $newModel, $cache )
			->getQualityForArticles( self::WIKI, [ 'Beavers' ] );
		$this->assertSame( [ [ 'Beavers' ] ], $this->batches );
		$this->assertSame( 0.9, $quality['Beavers']['score'] );
	}

	public function testGetQualityForArticles__aStaleVersionIsDroppedOnTheNextWrite(): void {
		$cache = $this->newCache();
		$this->scoreAll( $cache, [ 'Beavers' => 17 ], 0.2, '1' );
		$this->now += WANObjectCache::TTL_HOUR;
		$this->scoreAll( $cache, [ 'Otters' => 18 ], 0.5, '2' );

		$this->now += WANObjectCache::TTL_DAY;
		$this->scoreAll( $cache, [ 'Voles' => 19 ], 0.5, '2' );

		// Read directly, as nothing else shows what the entry holds: version 1 is gone from it.
		$entry = $cache->get( $cache->makeGlobalKey( 'CampaignEvents-articlequality-version' ) );
		$this->assertSame( [ 2 ], array_keys( $entry ) );
	}

	public function testGetQualityForArticles__afterAQuietDayOneArticleRelearnsTheVersion(): void {
		$cache = $this->newCache();
		$revisions = [ 'Beavers' => 17, 'Otters' => 18, 'Voles' => 19 ];
		$this->scoreAll( $cache, $revisions, 0.2, '1' );

		// Only cached scores were read for a day, so no version is known any more. One article is
		// enough to learn it, and the others are served from the cache.
		$this->now += WANObjectCache::TTL_DAY + 1;
		$this->batches = [];
		$quality = $this->scoreAll( $cache, $revisions, 0.9, '1' );
		$this->assertSame( [ [ 'Beavers' ] ], $this->batches );
		$this->assertSame( 0.9, $quality['Beavers']['score'] );
		$this->assertSame( 0.2, $quality['Otters']['score'] );
		$this->assertSame( 0.2, $quality['Voles']['score'] );
	}

	// Every version in use is checked, the most recent first (step 6).

	public function testGetQualityForArticles__keepsBothVersionsDuringARollout(): void {
		$cache = $this->newCache();
		$this->newLookup(
			self::revisionsResponse( [ 'Beavers' => 17, 'Otters' => 18 ] ),
			// Old and new pods responding side by side.
			[
				'Beavers' => self::modelResponse( 0.2, 'Stub', [], '1' ),
				'Otters' => self::modelResponse( 0.3, 'Start', [], '2' ),
			],
			$cache
		)->getQualityForArticles( self::WIKI, [ 'Beavers', 'Otters' ] );

		// Neither version retires the other's scores, so nothing is asked about again.
		$this->batches = [];
		$quality = $this->lookUpCached( $cache, [ 'Beavers' => 17, 'Otters' => 18 ] );
		$this->assertSame( [], $this->batches );
		$this->assertSame( 0.2, $quality['Beavers']['score'] );
		$this->assertSame( 0.3, $quality['Otters']['score'] );
	}

	public function testGetQualityForArticles__prefersTheVersionReturnedMostRecently(): void {
		$cache = $this->newCache();
		$this->scoreAll( $cache, [ 'Beavers' => 17 ], 0.2, '1' );

		// A day later version 1 has lapsed, so Beavers is scored again, this time by version 2.
		$this->now += WANObjectCache::TTL_DAY + 1;
		$this->scoreAll( $cache, [ 'Beavers' => 17 ], 0.9, '2' );

		// Version 1 is heard from again, as during a rollout, so Beavers has a score under each
		// version, both in use. Version 1 responded last, so its score is the one served.
		$this->now += 10;
		$this->scoreAll( $cache, [ 'Otters' => 18 ], 0.5, '1' );
		$this->assertSame( 0.2, $this->lookUpCached( $cache, [ 'Beavers' => 17 ] )['Beavers']['score'] );

		// Then version 2 responds, and its score is served instead.
		$this->now += 10;
		$this->scoreAll( $cache, [ 'Voles' => 19 ], 0.5, '2' );
		$this->assertSame( 0.9, $this->lookUpCached( $cache, [ 'Beavers' => 17 ] )['Beavers']['score'] );
	}

	public function testGetQualityForArticles__aScoreWithNoVersionIsServedFromTheCache(): void {
		$cache = $this->newCache();
		$this->newLookup(
			self::revisionsResponse( [ 'Beavers' => 17 ] ),
			// No version in the response, which is still a version: the empty one.
			[ 'Beavers' => self::modelResponse( 0.5, 'C', [] ) ],
			$cache
		)->getQualityForArticles( self::WIKI, [ 'Beavers' ] );

		$this->batches = [];
		$quality = $this->lookUpCached( $cache, [ 'Beavers' => 17 ] );
		$this->assertSame( [], $this->batches );
		$this->assertSame( 0.5, $quality['Beavers']['score'] );
	}

	public function testGetQualityForArticles__failuresAreNotRetriedImmediately(): void {
		$cache = new WANObjectCache( [ 'cache' => new HashBagOStuff() ] );

		$failing = $this->newLookup( self::revisionsResponse( [ 'Beavers' => 17 ] ), [], $cache );
		$this->assertSame( [], $failing->getQualityForArticles( self::WIKI, [ 'Beavers' ] ) );

		// A revision the model has just failed on is left alone rather than asked about again on
		// every page view, so a working model is not consulted until the failure expires.
		$working = $this->newLookup(
			self::revisionsResponse( [ 'Beavers' => 17 ] ),
			[ 'Beavers' => self::modelResponse( 0.9, 'GA', [] ) ],
			$cache
		);
		$this->assertSame( [], $working->getQualityForArticles( self::WIKI, [ 'Beavers' ] ) );
	}

	public function testGetQualityForArticles__normalisedTitlesMapBackToWhatWasAsked(): void {
		$lookup = $this->newLookup(
			// The API answers with the normalised form, which is not what the worklist stores.
			self::revisionsResponse( [ 'Beaver dam' => 17 ] ),
			[ 'Beaver_dam' => self::modelResponse( 0.6, 'C', [] ) ]
		);

		$this->assertSame(
			[ 'Beaver_dam' ],
			array_keys( $lookup->getQualityForArticles( self::WIKI, [ 'Beaver_dam' ] ) )
		);
	}

	public function testGetQualityForArticles__wikiWithNoKnownLanguage(): void {
		// The model needs a language, so a wiki the site lookup does not know cannot be scored.
		$lookup = $this->newLookup( self::revisionsResponse( [ 'Beavers' => 17 ] ), [], null, null );
		$this->assertSame( [], $lookup->getQualityForArticles( self::WIKI, [ 'Beavers' ] ) );
	}

	/** @dataProvider provideNothingToDo */
	public function testGetQualityForArticles__nothingToDo( array $titles, $revisionsResponse ): void {
		$lookup = $this->newLookup( $revisionsResponse, [] );
		$this->assertSame( [], $lookup->getQualityForArticles( self::WIKI, $titles ) );
	}

	public static function provideNothingToDo(): Generator {
		yield 'No titles asked for' => [ [], '{}' ];
		yield 'The revisions query failed' => [ [ 'Beavers' ], false ];
		yield 'The revisions query returned nonsense' => [ [ 'Beavers' ], 'not json' ];
	}

	public function testGetQualityForArticles__capsTheNumberOfArticles(): void {
		$titles = [];
		for ( $i = 0; $i < ArticleQualityLookup::MAX_ARTICLES + 10; $i++ ) {
			$titles[] = "Article $i";
		}
		$revisions = [];
		$responses = [];
		foreach ( $titles as $index => $title ) {
			$revisions[$title] = $index + 1;
			$responses[$title] = self::modelResponse( 0.5, 'C', [] );
		}

		$lookup = $this->newLookup( self::revisionsResponse( $revisions ), $responses );

		// One request per article is real work for the model, so a caller cannot ask for a
		// worklist's worth of them at once.
		$this->assertCount(
			ArticleQualityLookup::MAX_ARTICLES,
			$lookup->getQualityForArticles( self::WIKI, $titles )
		);
	}
}
