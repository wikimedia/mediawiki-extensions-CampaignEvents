<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Rest;

use Generator;
use MediaWiki\Config\HashConfig;
use MediaWiki\Config\SiteConfiguration;
use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventNotFoundException;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWPageProxy;
use MediaWiki\Extension\CampaignEvents\MWEntity\WikiLookup;
use MediaWiki\Extension\CampaignEvents\Rest\GetWorklistPagesHandler;
use MediaWiki\Extension\CampaignEvents\Worklist\IWorklistArticlesLookup;
use MediaWiki\MainConfigNames;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Rest\HttpException;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use stdClass;

/**
 * @group Test
 * @group Database
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\GetWorklistPagesHandler
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\EventIDParamTrait
 */
class GetWorklistPagesHandlerTest extends MediaWikiIntegrationTestCase {
	use HandlerTestTrait;

	private const EVENT_ID = 42;
	private const REQ_DATA = [
		'pathParams' => [ 'id' => self::EVENT_ID ],
	];
	private const FOREIGN_WIKI = 'someforeignwiki';
	private const LOCAL_SERVER = 'https://local.example.org';
	private const LOCAL_SCRIPT_PATH = '/w';
	private const LOCAL_API_URL = self::LOCAL_SERVER . self::LOCAL_SCRIPT_PATH . '/api.php';

	private const EVENT_PAGE_DBKEY = 'My_event';
	private const WORKLIST_PAGE_DBKEY = 'My_event/Worklist';

	protected function setUp(): void {
		parent::setUp();
		// The handler resolves the worklist page through the page store, so it has to exist for
		// the lookup to be asked about it.
		$this->getExistingTestPage( self::WORKLIST_PAGE_DBKEY );
	}

	/**
	 * @param list<array{wiki: string, prefixedtext: string}> $storedPages
	 */
	private function newHandler(
		array $storedPages,
		bool $eventExists = true,
		bool $cardViewEnabled = true
	): GetWorklistPagesHandler {
		$lookup = $this->createMock( IWorklistArticlesLookup::class );
		$lookup->method( 'getWorklistArticles' )->willReturn( $storedPages );

		return $this->newHandlerWithLookup( $lookup, $eventExists, $cardViewEnabled );
	}

	/**
	 * @param IWorklistArticlesLookup $lookup
	 * @param bool $eventExists
	 * @param bool $cardViewEnabled
	 * @param string $eventPageDBkey
	 * @param array<string,string> $scriptPaths Per-wiki ScriptPath, as $wgConf would resolve it
	 */
	private function newHandlerWithLookup(
		IWorklistArticlesLookup $lookup,
		bool $eventExists = true,
		bool $cardViewEnabled = true,
		string $eventPageDBkey = self::EVENT_PAGE_DBKEY,
		array $scriptPaths = []
	): GetWorklistPagesHandler {
		$eventPage = $this->createMock( MWPageProxy::class );
		$eventPage->method( 'getNamespace' )->willReturn( NS_MAIN );
		$eventPage->method( 'getDBkey' )->willReturn( $eventPageDBkey );
		$eventPage->method( 'getWikiId' )->willReturn( WikiAwareEntity::LOCAL );
		$event = $this->createMock( ExistingEventRegistration::class );
		$event->method( 'getPage' )->willReturn( $eventPage );

		$eventLookup = $this->createMock( IEventLookup::class );
		if ( $eventExists ) {
			$eventLookup->method( 'getEventByID' )->willReturn( $event );
		} else {
			$eventLookup->method( 'getEventByID' )
				->willThrowException( $this->createMock( EventNotFoundException::class ) );
		}

		$wikiLookup = $this->createMock( WikiLookup::class );
		$wikiLookup->method( 'getLocalizedNames' )->willReturnCallback(
			static fn ( array $wikiIDs ): array => array_combine(
				$wikiIDs,
				array_map( static fn ( string $wiki ): string => "Name of $wiki", $wikiIDs )
			)
		);
		$wikiLookup->method( 'getScriptPath' )->willReturnCallback(
			static fn ( string $wiki ): ?string => $scriptPaths[$wiki] ?? null
		);

		$services = $this->getServiceContainer();
		return new GetWorklistPagesHandler(
			new HashConfig( [
				'CampaignEventsEnableWorklistCardView' => $cardViewEnabled,
				MainConfigNames::CanonicalServer => self::LOCAL_SERVER,
				MainConfigNames::ScriptPath => self::LOCAL_SCRIPT_PATH,
			] ),
			$eventLookup,
			$lookup,
			$wikiLookup,
			$services->getTitleFactory(),
			$services->getLinkBatchFactory(),
			$services->getLinkRendererFactory(),
			CampaignEventsServices::getWorklistEventsStore(),
		);
	}

	/**
	 * @param list<array{wiki: string, prefixedtext: string}> $storedPages
	 */
	private function executeWithPages( array $storedPages ): array {
		return $this->executeForBody( $storedPages )['pages'];
	}

	private function executeForBody( array $storedPages ): array {
		return $this->executeHandlerAndGetBodyData(
			$this->newHandler( $storedPages ),
			new RequestData( self::REQ_DATA )
		);
	}

	public function testRun__noPages(): void {
		$respData = $this->executeHandlerAndGetBodyData(
			$this->newHandler( [] ),
			new RequestData( self::REQ_DATA )
		);

		// An empty worklist still names no wikis, so the map comes back alongside the pages.
		$this->assertSame( [ 'wikis' => [], 'pages' => [] ], $respData );
	}

	public function testRun__localPages(): void {
		$existingPage = $this->getExistingTestPage( 'Worklist article that exists' )->getTitle();
		$missingPage = $this->getNonexistingTestPage( 'Worklist article to be created' )->getTitle();
		$localWiki = WikiMap::getCurrentWikiId();

		$respData = $this->executeWithPages( [
			[ 'wiki' => $localWiki, 'prefixedtext' => $existingPage->getPrefixedText() ],
			[ 'wiki' => $localWiki, 'prefixedtext' => $missingPage->getPrefixedText() ],
		] );

		$this->assertSame(
			[
				[
					'wiki' => $localWiki,
					'is_local' => true,
					'title' => $existingPage->getPrefixedText(),
					'url' => $existingPage->getLinkURL( [], false, PROTO_RELATIVE ),
					'classes' => '',
				],
				[
					'wiki' => $localWiki,
					'is_local' => true,
					'title' => $missingPage->getPrefixedText(),
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
			[ 'wiki' => self::FOREIGN_WIKI, 'prefixedtext' => 'Foreign article' ],
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

		$lookup = $this->createMock( IWorklistArticlesLookup::class );
		$lookup->method( 'getWorklistArticles' )->willReturn( [
			[ 'wiki' => 'resolvedwiki', 'prefixedtext' => 'Foreign article' ],
		] );
		$body = $this->executeHandlerAndGetBodyData(
			$this->newHandlerWithLookup( $lookup, true, true, self::EVENT_PAGE_DBKEY, $scriptPaths ),
			new RequestData( self::REQ_DATA )
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
			[ 'wiki' => self::FOREIGN_WIKI, 'prefixedtext' => 'Zebra' ],
			[ 'wiki' => $localWiki, 'prefixedtext' => 'Beaver' ],
			[ 'wiki' => self::FOREIGN_WIKI, 'prefixedtext' => 'Aardvark' ],
		] );

		$this->assertSame(
			[ 'Zebra', 'Beaver', 'Aardvark' ],
			array_column( $respData, 'title' ),
			'Ordering is the lookup\'s business; the handler must not reshuffle it'
		);
	}

	public function testRun__unparseableLocalTitleIsNotFatal(): void {
		$localWiki = WikiMap::getCurrentWikiId();
		$respData = $this->executeWithPages( [
			[ 'wiki' => $localWiki, 'prefixedtext' => '<invalid title>' ],
		] );

		$this->assertSame(
			[
				[
					'wiki' => $localWiki,
					'is_local' => true,
					'title' => '<invalid title>',
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
			[ 'wiki' => $localWiki, 'prefixedtext' => 'One' ],
			[ 'wiki' => $localWiki, 'prefixedtext' => 'Two' ],
			[ 'wiki' => self::FOREIGN_WIKI, 'prefixedtext' => 'Three' ],
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
			new RequestData( self::REQ_DATA )
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
			$this->newHandler( [], true, false ),
			new RequestData( self::REQ_DATA )
		);
	}

	public function testRun__eventNotFound(): void {
		$handler = $this->newHandler( [], false );
		$this->expectException( LocalizedHttpException::class );
		$this->expectExceptionCode( 404 );
		$this->executeHandler( $handler, new RequestData( self::REQ_DATA ) );
	}

	public function testRun__readsTheEventsWorklistPage(): void {
		$lookup = $this->createMock( IWorklistArticlesLookup::class );
		$lookup->expects( $this->once() )
			->method( 'getWorklistArticles' )
			->with(
				// The worklist is a fixed subpage of the event page.
				$this->callback(
					static fn ( PageIdentity $page ): bool =>
						$page->getDBkey() === self::WORKLIST_PAGE_DBKEY
						&& $page->getNamespace() === NS_MAIN
						&& $page->getWikiId() === WikiAwareEntity::LOCAL
				),
				// The whole list, newest first, because the client paginates it itself.
				0,
				0,
				IWorklistArticlesLookup::DESCENDING,
				IWorklistArticlesLookup::TIMESTAMP_SORT
			)
			->willReturn( [] );

		$this->executeHandlerAndGetBodyData(
			$this->newHandlerWithLookup( $lookup ),
			new RequestData( self::REQ_DATA )
		);
	}

	public function testRun__noWorklistPage(): void {
		// The worklist page is created by the edit that adds the first article, so an event that
		// has never had one has an empty worklist rather than an error.
		$lookup = $this->createMock( IWorklistArticlesLookup::class );
		$lookup->expects( $this->never() )->method( 'getWorklistArticles' );

		$respData = $this->executeHandlerAndGetBodyData(
			$this->newHandlerWithLookup( $lookup, true, true, 'Event_with_no_worklist' ),
			new RequestData( self::REQ_DATA )
		);

		// An empty worklist still names no wikis, so the map comes back alongside the pages.
		$this->assertSame( [ 'wikis' => [], 'pages' => [] ], $respData );
	}
}
