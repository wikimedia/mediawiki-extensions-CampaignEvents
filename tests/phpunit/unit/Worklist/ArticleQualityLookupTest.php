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

/**
 * @covers \MediaWiki\Extension\CampaignEvents\Worklist\ArticleQualityLookup
 */
class ArticleQualityLookupTest extends MediaWikiUnitTestCase {
	private const WIKI = 'awiki';
	private const ENDPOINT = 'https://inference.example.org/v1/models/articlequality:predict';

	/** @var array The requests the multi client was handed, so the URL and headers can be read */
	private array $sentRequests = [];

	/** A trimmed articlequality response, in the shape the model really sends. */
	private static function modelResponse( float $score, string $label, array $normalized ): string {
		return json_encode( [
			'label' => $label,
			'features' => [ 'raw' => [], 'normalized' => $normalized ],
			'score' => $score,
			'model_name' => 'articlequality',
		] );
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
	 * @return ArticleQualityLookup
	 */
	private function newLookup(
		$revisionsResponse,
		array $modelResponses,
		?WANObjectCache $cache = null,
		?string $langCode = 'en',
		?string $endpoint = self::ENDPOINT,
		?string $hostHeader = null
	): ArticleQualityLookup {
		$this->sentRequests = [];
		$multiClient = $this->createMock( MultiHttpClient::class );
		$multiClient->method( 'runMulti' )->willReturnCallback(
			function ( array $reqs ) use ( $modelResponses ): array {
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
