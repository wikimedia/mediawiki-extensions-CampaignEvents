<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Rest;

use Generator;
use MediaWiki\Config\HashConfig;
use MediaWiki\Config\SiteConfiguration;
use MediaWiki\Extension\CampaignEvents\MWEntity\WikiLookup;
use MediaWiki\Extension\CampaignEvents\Rest\GetWorklistPagesHandler;
use MediaWiki\Extension\CampaignEvents\Worklist\IWorklistPagesLookup;
use MediaWiki\Extension\CampaignEvents\Worklist\WorklistPagesLookupFactory;
use MediaWiki\Language\Language;
use MediaWiki\MainConfigNames;
use MediaWiki\Page\ExistingPageRecord;
use MediaWiki\Page\PageStore;
use MediaWiki\Page\PageStoreFactory;
use MediaWiki\Rest\HttpException;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWiki\Utils\MWTimestamp;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use stdClass;
use Wikimedia\Timestamp\TimestampFormat as TS;

/**
 * @group Test
 * @group Database
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\GetWorklistPagesHandler
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\EventIDParamTrait
 */
class GetWorklistPagesHandlerTest extends MediaWikiIntegrationTestCase {
	use HandlerTestTrait;

	private const FOREIGN_WIKI = 'someforeignwiki';

	private const LOCAL_SERVER = 'https://local.example.org';
	private const LOCAL_SCRIPT_PATH = '/w';
	private const LOCAL_API_URL = self::LOCAL_SERVER . self::LOCAL_SCRIPT_PATH . '/api.php';

	private function getRequestData( array $queryParams = [] ): RequestData {
		return new RequestData( [
			'pathParams' => [
				'wiki' => WikiMap::getCurrentWikiId(),
				'namespace' => NS_EVENT,
				'title' => 'Test worklist'
			],
			'queryParams' => $queryParams,
		] );
	}

	/**
	 * @param list<array{wiki: string, prefixedtext: string, timestamp:int|null}> $storedPages
	 */
	private function newHandler(
		array $storedPages,
		bool $cardViewEnabled = true
	): GetWorklistPagesHandler {
		$lookup = $this->createMock( IWorklistPagesLookup::class );
		$lookup->method( 'getWorklistPages' )
			->willReturn( [ 'result' => $storedPages, 'paginationOffsets' => [] ] );

		return $this->newHandlerWithLookup( $lookup, cardViewEnabled: $cardViewEnabled );
	}

	/**
	 * @param IWorklistPagesLookup $lookup
	 * @param bool $worklistPageExists
	 * @param bool $cardViewEnabled
	 * @param array<string,string> $scriptPaths Per-wiki ScriptPath, as $wgConf would resolve it
	 */
	private function newHandlerWithLookup(
		IWorklistPagesLookup $lookup,
		bool $worklistPageExists = true,
		bool $cardViewEnabled = true,
		array $scriptPaths = []
	): GetWorklistPagesHandler {
		$pageStore = $this->createMock( PageStore::class );
		$pageStore->method( 'getPageByName' )
			->willReturn( $worklistPageExists ? $this->createMock( ExistingPageRecord::class ) : null );
		$pageStoreFactory = $this->createMock( PageStoreFactory::class );
		$pageStoreFactory->method( 'getPageStore' )->willReturn( $pageStore );

		$wikiLookup = $this->createMock( WikiLookup::class );
		$wikiLookup->method( 'getAllWikis' )->willReturn( [ WikiMap::getCurrentWikiId(), self::FOREIGN_WIKI ] );
		$wikiLookup->method( 'getLocalizedNames' )->willReturnCallback(
			static fn ( array $wikiIDs ): array => array_combine(
				$wikiIDs,
				array_map( static fn ( string $wiki ): string => "Name of $wiki", $wikiIDs )
			)
		);
		$wikiLookup->method( 'getScriptPath' )->willReturnCallback(
			static fn ( string $wiki ): ?string => $scriptPaths[$wiki] ?? null
		);

		$worklistPagesLookupFactory = $this->createMock( WorklistPagesLookupFactory::class );
		$worklistPagesLookupFactory->method( 'getLookupForWorklist' )->willReturn( $lookup );

		$services = $this->getServiceContainer();
		return new GetWorklistPagesHandler(
			new HashConfig( [
				'CampaignEventsEnableWorklistCardView' => $cardViewEnabled,
				MainConfigNames::CanonicalServer => self::LOCAL_SERVER,
				MainConfigNames::ScriptPath => self::LOCAL_SCRIPT_PATH,
			] ),
			$pageStoreFactory,
			$wikiLookup,
			$services->getTitleFactory(),
			$services->getLinkBatchFactory(),
			$services->getLinkRendererFactory(),
			$worklistPagesLookupFactory,
		);
	}

	/**
	 * @param list<array{wiki: string, prefixedtext: string, timestamp:int|null}> $storedPages
	 */
	private function executeWithPages( array $storedPages ): array {
		return $this->executeForBody( $storedPages )['pages'];
	}

	private function executeForBody( array $storedPages ): array {
		return $this->executeHandlerAndGetBodyData(
			$this->newHandler( $storedPages ),
			$this->getRequestData()
		);
	}

	public function testRun__noPages(): void {
		$respData = $this->executeHandlerAndGetBodyData(
			$this->newHandler( [] ),
			$this->getRequestData()
		);

		// An empty worklist still names no wikis, so the map comes back alongside the pages.
		$this->assertSame( [ 'wikis' => [], 'pages' => [], 'pagination_offsets' => [] ], $respData );
	}

	public function testRun__localPages(): void {
		$existingPage = $this->getExistingTestPage( 'Worklist article that exists' )->getTitle();
		$missingPage = $this->getNonexistingTestPage( 'Worklist article to be created' )->getTitle();
		$localWiki = WikiMap::getCurrentWikiId();

		$formatTime = static fn ( $ts ) => "Formatted " . MWTimestamp::convert( TS::MW, $ts );
		$lang = $this->createMock( Language::class );
		$lang->method( 'userTimeAndDate' )->willReturnCallback( $formatTime );
		$this->setUserLang( $lang );

		$addedTime = 1791473863;
		$respData = $this->executeWithPages( [
			[
				'wiki' => $localWiki,
				'prefixedtext' => $existingPage->getPrefixedText(),
				'timestamp' => $addedTime,
			],
			[
				'wiki' => $localWiki,
				'prefixedtext' => $missingPage->getPrefixedText(),
				'timestamp' => null,
			],
		] );

		$this->assertSame(
			[
				[
					'wiki' => $localWiki,
					'is_local' => true,
					'title' => $existingPage->getPrefixedText(),
					'timestamp' => MWTimestamp::convert( TS::ISO_8601, $addedTime ),
					'timestamp_formatted' => $formatTime( $addedTime ),
					'url' => $existingPage->getLinkURL( [], false, PROTO_RELATIVE ),
					'classes' => '',
				],
				[
					'wiki' => $localWiki,
					'is_local' => true,
					'title' => $missingPage->getPrefixedText(),
					'timestamp' => null,
					'timestamp_formatted' => null,
					// A page still to be created is linked as a red link, exactly as the
					// server-rendered worklist table does.
					'url' => $missingPage->getLinkURL(
						[ 'action' => 'edit', 'redlink' => '1' ], false, PROTO_RELATIVE
					),
					'classes' => 'new',
				],
			],
			$respData
		);
	}

	public function testRun__foreignPage(): void {
		$respData = $this->executeWithPages( [
			[
				'wiki' => self::FOREIGN_WIKI,
				'prefixedtext' => 'Foreign article',
				'timestamp' => MWTimestamp::now(),
			],
		] );

		$this->assertCount( 1, $respData );
		$entry = $respData[0];
		$this->assertSame( self::FOREIGN_WIKI, $entry['wiki'] );
		$this->assertFalse( $entry['is_local'], 'Relative to the wiki that answered the request' );
		$this->assertSame( 'Foreign article', $entry['title'] );
		// An article on a wiki this one cannot resolve has nothing to link to. Where the wiki farm
		// does resolve it, WikiMap gives a URL and the link is rendered with the `external` class,
		// as in the worklist table; no farm is configured for this wiki in tests.
		$this->assertSame( '', $entry['url'] );
		$this->assertSame( '', $entry['classes'] );
	}

	/**
	 * @dataProvider provideForeignApiUrl
	 */
	public function testRun__foreignApiUrl( array $scriptPaths, string $expectedUrl ): void {
		$conf = new SiteConfiguration();
		$conf->suffixes = [ 'wiki' ];
		$conf->settings = [
			'wgServer' => [ 'resolvedwiki' => '//resolved.example.org' ],
			'wgCanonicalServer' => [ 'resolvedwiki' => 'https://resolved.example.org' ],
			'wgArticlePath' => [ 'resolvedwiki' => '/wiki/$1' ],
		];
		$this->setMwGlobals( 'wgConf', $conf );

		$lookup = $this->createMock( IWorklistPagesLookup::class );
		$lookup->method( 'getWorklistPages' )->willReturn( [
			'result' => [
				[
					'wiki' => 'resolvedwiki',
					'prefixedtext' => 'Foreign article',
					'timestamp' => MWTimestamp::now(),
				],
			],
			'paginationOffsets' => [],
		] );
		$body = $this->executeHandlerAndGetBodyData(
			$this->newHandlerWithLookup( $lookup, scriptPaths: $scriptPaths ),
			$this->getRequestData()
		);

		$this->assertSame( $expectedUrl, $body['wikis']['resolvedwiki']['api_url'] );
	}

	public static function provideForeignApiUrl(): Generator {
		yield 'ScriptPath resolved for the wiki' => [
			[ 'resolvedwiki' => '/w2' ],
			'https://resolved.example.org/w2/api.php',
		];
		yield 'Falls back to the local ScriptPath' => [
			[],
			'https://resolved.example.org' . self::LOCAL_SCRIPT_PATH . '/api.php',
		];
	}

	public function testRun__keepsTheOrderTheLookupReturned(): void {
		$localWiki = WikiMap::getCurrentWikiId();
		$respData = $this->executeWithPages( [
			[
				'wiki' => self::FOREIGN_WIKI,
				'prefixedtext' => 'Zebra',
				'timestamp' => MWTimestamp::now(),
			],
			[
				'wiki' => $localWiki,
				'prefixedtext' => 'Beaver',
				'timestamp' => null,
			],
			[
				'wiki' => self::FOREIGN_WIKI,
				'prefixedtext' => 'Aardvark',
				'timestamp' => MWTimestamp::now(),
			],
		] );

		$this->assertSame(
			[ 'Zebra', 'Beaver', 'Aardvark' ],
			array_column( $respData, 'title' ),
			'Ordering is the lookup\'s business; the handler must not reshuffle it'
		);
	}

	public function testRun__unparseableLocalTitleIsNotFatal(): void {
		$localWiki = WikiMap::getCurrentWikiId();
		$timestamp = MWTimestamp::now( TS::ISO_8601 );

		$lang = $this->createMock( Language::class );
		$lang->method( 'userTimeAndDate' )->willReturnArgument( 0 );
		$this->setUserLang( $lang );

		$respData = $this->executeWithPages( [
			[
				'wiki' => $localWiki,
				'prefixedtext' => '<invalid title>',
				'timestamp' => $timestamp,
			],
		] );

		$this->assertSame(
			[
				[
					'wiki' => $localWiki,
					'is_local' => true,
					'title' => '<invalid title>',
					'timestamp' => $timestamp,
					'timestamp_formatted' => $timestamp,
					'url' => '',
					'classes' => '',
				],
			],
			$respData
		);
	}

	public function testRun__namesEachWikiOnce(): void {
		$localWiki = WikiMap::getCurrentWikiId();
		$body = $this->executeForBody( [
			[
				'wiki' => $localWiki,
				'prefixedtext' => 'One',
				'timestamp' => null,
			],
			[
				'wiki' => $localWiki,
				'prefixedtext' => 'Two',
				'timestamp' => MWTimestamp::now(),
			],
			[
				'wiki' => self::FOREIGN_WIKI,
				'prefixedtext' => 'Three',
				'timestamp' => MWTimestamp::now(),
			],
		] );

		// One entry per wiki, not one per page: the name is the same for every page of a wiki and
		// a worklist can hold thousands of them.
		$this->assertSame(
			[
				$localWiki => [ 'name' => "Name of $localWiki", 'api_url' => self::LOCAL_API_URL ],
				self::FOREIGN_WIKI => [
					'name' => 'Name of ' . self::FOREIGN_WIKI,
					// No farm is configured for this wiki in tests, so it cannot be resolved.
					'api_url' => null,
				],
			],
			(array)$body['wikis']
		);
	}

	public function testRun__noPagesStillSendsAWikiObject(): void {
		$response = $this->executeHandler(
			$this->newHandler( [] ),
			$this->getRequestData()
		);

		// Decoded into objects rather than arrays: decoding associatively turns both [] and {}
		// into an empty PHP array, so only this tells apart what a client would receive.
		$body = json_decode( (string)$response->getBody(), false );
		$this->assertInstanceOf( stdClass::class, $body->wikis );
	}

	public function testRun__cardViewDisabled(): void {
		// With the feature off the endpoint answers as though it were not there at all. The error
		// is deliberately not localised: the flag is temporary.
		$this->expectException( HttpException::class );
		$this->expectExceptionCode( 404 );
		$this->executeHandler(
			$this->newHandler( [], false ),
			$this->getRequestData()
		);
	}

	public function testRun__noWorklistPage(): void {
		$lookup = $this->createMock( IWorklistPagesLookup::class );
		$lookup->expects( $this->never() )->method( 'getWorklistPages' );

		$respData = $this->executeHandlerAndGetBodyData(
			$this->newHandlerWithLookup( $lookup, false ),
			$this->getRequestData()
		);

		// An empty worklist still names no wikis, so the map comes back alongside the pages.
		$this->assertSame( [ 'wikis' => [], 'pages' => [], 'pagination_offsets' => [] ], $respData );
	}

	public function testRun__paginationParametersArePassedThrough(): void {
		$limit = 1234;
		$offset = 'Foo|Bar';
		$backwards = true;
		$titleFilter = 'Some filter';

		$respOffsets = [
			IWorklistPagesLookup::PAGINATION_CUR => '|7',
			IWorklistPagesLookup::PAGINATION_PREV => '|5',
			IWorklistPagesLookup::PAGINATION_NEXT => '10|11',
			IWorklistPagesLookup::PAGINATION_FIRST => null,
			IWorklistPagesLookup::PAGINATION_LAST => null,
		];
		$lookup = $this->createMock( IWorklistPagesLookup::class );
		$lookup->expects( $this->once() )
			->method( 'getWorklistPages' )
			->with(
				IWorklistPagesLookup::SORT_TIMESTAMP,
				$limit,
				$offset,
				IWorklistPagesLookup::DIR_DESCENDING,
				$backwards,
				$titleFilter,
			)
			->willReturn( [ 'result' => [], 'paginationOffsets' => $respOffsets ] );

		$respData = $this->executeHandlerAndGetBodyData(
			$this->newHandlerWithLookup( $lookup ),
			$this->getRequestData( [
				'limit' => $limit,
				'offset' => $offset,
				'backwards' => $backwards,
				'title_filter' => $titleFilter
			] )
		);

		$this->assertSame( $respOffsets, $respData['pagination_offsets'] );
	}
}
