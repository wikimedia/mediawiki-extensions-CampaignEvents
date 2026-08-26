<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Rest;

use MediaWiki\Config\HashConfig;
use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventNotFoundException;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWPageProxy;
use MediaWiki\Extension\CampaignEvents\Rest\GetWorklistPagesHandler;
use MediaWiki\Extension\CampaignEvents\Worklist\IWorklistArticlesLookup;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Rest\HttpException;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;

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

	private function newHandlerWithLookup(
		IWorklistArticlesLookup $lookup,
		bool $eventExists = true,
		bool $cardViewEnabled = true,
		string $eventPageDBkey = self::EVENT_PAGE_DBKEY
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

		$services = $this->getServiceContainer();
		return new GetWorklistPagesHandler(
			new HashConfig( [ 'CampaignEventsEnableWorklistCardView' => $cardViewEnabled ] ),
			$eventLookup,
			$lookup,
			$services->getTitleFactory(),
			$services->getLinkBatchFactory(),
			$services->getPageStoreFactory(),
			$services->getLinkRendererFactory()
		);
	}

	/**
	 * @param list<array{wiki: string, prefixedtext: string}> $storedPages
	 */
	private function executeWithPages( array $storedPages ): array {
		return $this->executeHandlerAndGetBodyData(
			$this->newHandler( $storedPages ),
			new RequestData( self::REQ_DATA )
		);
	}

	public function testRun__noPages(): void {
		$this->assertSame( [], $this->executeWithPages( [] ) );
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
					'title' => $existingPage->getPrefixedText(),
					'url' => $existingPage->getLinkURL( [], false, PROTO_RELATIVE ),
					'classes' => '',
				],
				[
					'wiki' => $localWiki,
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
		$this->assertSame( 'Foreign article', $entry['title'] );
		// An article on a wiki this one cannot resolve has nothing to link to. Where the wiki farm
		// does resolve it, WikiMap gives a URL and the link is rendered with the `external` class,
		// as in the worklist table; no farm is configured for this wiki in tests.
		$this->assertSame( '', $entry['url'] );
		$this->assertSame( '', $entry['classes'] );
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
					'title' => '<invalid title>',
					'url' => '',
					'classes' => '',
				],
			],
			$respData
		);
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

		$this->assertSame( [], $respData );
	}
}
