<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Rest;

use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\CampaignEvents\MWEntity\WikiLookup;
use MediaWiki\Extension\CampaignEvents\Rest\GetWorklistPagesQualityHandler;
use MediaWiki\Extension\CampaignEvents\Worklist\ArticleQualityLookup;
use MediaWiki\Extension\CampaignEvents\Worklist\IWorklistPagesLookup;
use MediaWiki\Extension\CampaignEvents\Worklist\WorklistPagesLookupFactory;
use MediaWiki\Rest\HttpException;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWiki\Title\Title;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;

/**
 * @group Test
 * @group Database
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\GetWorklistPagesQualityHandler
 */
class GetWorklistPagesQualityHandlerTest extends MediaWikiIntegrationTestCase {
	use HandlerTestTrait;

	private const WIKI = 'awiki';
	private const EVENT_PAGE_DBKEY = 'My_event';
	private const WORKLIST_PAGE_NAMESPACE = NS_EVENT;
	private const WORKLIST_PAGE_DBKEY = 'My_event/Worklist';

	protected function setUp(): void {
		parent::setUp();
		// The handler resolves the worklist page through the page store, so it has to exist.
		$this->getExistingTestPage( Title::makeTitle( self::WORKLIST_PAGE_NAMESPACE, self::WORKLIST_PAGE_DBKEY ) );
	}

	private function reqData( array $titles, string $wiki = self::WIKI, array $pathOverrides = [] ): array {
		return [
			'pathParams' => array_replace( [
				'worklistwiki' => WikiMap::getCurrentWikiId(),
				'namespace' => self::WORKLIST_PAGE_NAMESPACE,
				'title' => self::WORKLIST_PAGE_DBKEY
			], $pathOverrides ),
			'queryParams' => [ 'wiki' => $wiki, 'titles' => implode( '|', $titles ) ],
		];
	}

	/**
	 * @param list<array{wiki: string, prefixedtext: string}>|null $storedPages In the worklist, null to assert they
	 *   are not even checked.
	 * @param array $quality What the model reports, keyed by prefixed text
	 * @param bool $cardViewEnabled
	 * @return GetWorklistPagesQualityHandler
	 */
	private function newHandler(
		?array $storedPages,
		array $quality = [],
		bool $cardViewEnabled = true
	): GetWorklistPagesQualityHandler {
		if ( $storedPages !== null ) {
			$worklistPagesLookup = $this->createMock( IWorklistPagesLookup::class );
			// Filters as the store does: the titles asked about that are in the worklist for that wiki.
			$worklistPagesLookup->method( 'filterWorklistPages' )->willReturnCallback(
				static function ( string $wiki, array $titles ) use ( $storedPages ): array {
					$stored = [];
					foreach ( $storedPages as $storedPage ) {
						if ( $storedPage['wiki'] === $wiki ) {
							$stored[$storedPage['prefixedtext']] = true;
						}
					}
					return array_values( array_filter( $titles, static fn ( $title ) => isset( $stored[$title] ) ) );
				}
			);
		} else {
			$worklistPagesLookup = $this->createNoOpMock( IWorklistPagesLookup::class );
		}
		$worklistLookupFactory = $this->createMock( WorklistPagesLookupFactory::class );
		$worklistLookupFactory->method( 'getLookupForWorklist' )->willReturn( $worklistPagesLookup );

		$qualityLookup = $this->createMock( ArticleQualityLookup::class );
		$qualityLookup->method( 'getQualityForArticles' )->willReturnCallback(
			static function ( string $wiki, array $titles ) use ( $quality ): array {
				return array_intersect_key( $quality, array_fill_keys( $titles, true ) );
			}
		);

		$wikiLookup = $this->createMock( WikiLookup::class );
		$wikiLookup->method( 'getAllWikis' )->willReturn( [ WikiMap::getCurrentWikiId(), self::WIKI ] );

		return new GetWorklistPagesQualityHandler(
			new HashConfig( [ 'CampaignEventsEnableWorklistCardView' => $cardViewEnabled ] ),
			$this->getServiceContainer()->getPageStoreFactory(),
			$worklistLookupFactory,
			$qualityLookup,
			$wikiLookup,
		);
	}

	private static function scoreOf( float $score, array $elements = [] ): array {
		return [ 'score' => $score, 'label' => 'C', 'elements' => $elements ];
	}

	public function testRun__returnsScoreLabelAndElements(): void {
		$handler = $this->newHandler(
			[ [ 'wiki' => self::WIKI, 'prefixedtext' => 'Beavers' ] ],
			[ 'Beavers' => [ 'score' => 0.42, 'label' => 'C', 'elements' => [ 'refs' => 0.3 ] ] ]
		);

		$respData = $this->executeHandlerAndGetBodyData(
			$handler,
			new RequestData( $this->reqData( [ 'Beavers' ] ) )
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

	public function testRun__ignoresPagesNotInTheWorklist(): void {
		// Otherwise the endpoint is an open proxy onto the model: any title on any wiki could be
		// made to cost a model run by asking this event about it.
		$handler = $this->newHandler(
			[ [ 'wiki' => self::WIKI, 'prefixedtext' => 'Beavers' ] ],
			[
				'Beavers' => self::scoreOf( 0.4 ),
				'Something else entirely' => self::scoreOf( 0.9 ),
			]
		);

		$respData = $this->executeHandlerAndGetBodyData(
			$handler,
			new RequestData( $this->reqData( [ 'Beavers', 'Something else entirely' ] ) )
		);

		$this->assertSame( [ 'Beavers' ], array_column( $respData['articles'], 'title' ) );
	}

	public function testRun__ignoresPagesOnAnotherWiki(): void {
		// The same title can be in the worklist for a different wiki; asking about awiki must not
		// answer for bwiki's copy.
		$handler = $this->newHandler(
			[ [ 'wiki' => 'bwiki', 'prefixedtext' => 'Beavers' ] ],
			[ 'Beavers' => self::scoreOf( 0.4 ) ]
		);

		$respData = $this->executeHandlerAndGetBodyData(
			$handler,
			new RequestData( $this->reqData( [ 'Beavers' ] ) )
		);

		$this->assertSame( [], $respData['articles'] );
	}

	public function testRun__leavesOutArticlesTheModelCannotScore(): void {
		$handler = $this->newHandler(
			[
				[ 'wiki' => self::WIKI, 'prefixedtext' => 'Beavers' ],
				[ 'wiki' => self::WIKI, 'prefixedtext' => 'Otters' ],
			],
			// Nothing for Otters: the card shows no chip and the rest of the screen still loads.
			[ 'Beavers' => self::scoreOf( 0.4 ) ]
		);

		$respData = $this->executeHandlerAndGetBodyData(
			$handler,
			new RequestData( $this->reqData( [ 'Beavers', 'Otters' ] ) )
		);

		$this->assertSame( [ 'Beavers' ], array_column( $respData['articles'], 'title' ) );
	}

	public function testRun__noWorklistPage(): void {
		$nonexistentWorklist = $this->getNonexistingTestPage();
		$pathOverrides = [
			'namespace' => $nonexistentWorklist->getNamespace(),
			'title' => $nonexistentWorklist->getDBkey(),
		];

		$handler = $this->newHandler( null );

		$respData = $this->executeHandlerAndGetBodyData(
			$handler,
			new RequestData( $this->reqData( [ 'Beavers' ], pathOverrides: $pathOverrides ) )
		);

		$this->assertSame( [], $respData['articles'] );
	}

	public function testRun__cardViewDisabled(): void {
		$handler = $this->newHandler( null, [], false );

		$this->expectException( HttpException::class );
		$this->expectExceptionCode( 404 );
		$this->executeHandler( $handler, new RequestData( $this->reqData( [ 'Beavers' ] ) ) );
	}
}
