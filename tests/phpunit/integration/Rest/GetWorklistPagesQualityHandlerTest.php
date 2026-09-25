<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Rest;

use MediaWiki\Config\HashConfig;
use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventNotFoundException;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWPageProxy;
use MediaWiki\Extension\CampaignEvents\Rest\GetWorklistPagesQualityHandler;
use MediaWiki\Extension\CampaignEvents\Worklist\ArticleQualityLookup;
use MediaWiki\Extension\CampaignEvents\Worklist\IWorklistArticlesLookup;
use MediaWiki\Rest\HttpException;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWikiIntegrationTestCase;

/**
 * @group Test
 * @group Database
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\GetWorklistPagesQualityHandler
 */
class GetWorklistPagesQualityHandlerTest extends MediaWikiIntegrationTestCase {
	use HandlerTestTrait;

	private const EVENT_ID = 42;
	private const WIKI = 'awiki';
	private const EVENT_PAGE_DBKEY = 'My_event';
	private const WORKLIST_PAGE_DBKEY = 'My_event/Worklist';

	protected function setUp(): void {
		parent::setUp();
		// The handler resolves the worklist page through the page store, so it has to exist.
		$this->getExistingTestPage( self::WORKLIST_PAGE_DBKEY );
	}

	private static function reqData( array $titles, string $wiki = self::WIKI ): array {
		return [
			'pathParams' => [ 'id' => self::EVENT_ID ],
			'queryParams' => [ 'wiki' => $wiki, 'titles' => implode( '|', $titles ) ],
		];
	}

	/**
	 * @param list<array{wiki: string, prefixedtext: string}> $storedPages In the worklist
	 * @param array $quality What the model reports, keyed by prefixed text
	 * @param bool $eventExists
	 * @param bool $cardViewEnabled
	 * @return GetWorklistPagesQualityHandler
	 */
	private function newHandler(
		array $storedPages,
		array $quality = [],
		bool $eventExists = true,
		bool $cardViewEnabled = true
	): GetWorklistPagesQualityHandler {
		$eventPage = $this->createMock( MWPageProxy::class );
		$eventPage->method( 'getNamespace' )->willReturn( NS_MAIN );
		$eventPage->method( 'getDBkey' )->willReturn( self::EVENT_PAGE_DBKEY );
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

		$articlesLookup = $this->createMock( IWorklistArticlesLookup::class );
		$articlesLookup->method( 'getWorklistArticles' )->willReturn( $storedPages );

		$qualityLookup = $this->createMock( ArticleQualityLookup::class );
		$qualityLookup->method( 'getQualityForArticles' )->willReturnCallback(
			static function ( string $wiki, array $titles ) use ( $quality ): array {
				return array_intersect_key( $quality, array_fill_keys( $titles, true ) );
			}
		);

		return new GetWorklistPagesQualityHandler(
			new HashConfig( [ 'CampaignEventsEnableWorklistCardView' => $cardViewEnabled ] ),
			$eventLookup,
			$articlesLookup,
			$this->getServiceContainer()->getPageStoreFactory(),
			$qualityLookup
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
			new RequestData( self::reqData( [ 'Beavers' ] ) )
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
			new RequestData( self::reqData( [ 'Beavers', 'Something else entirely' ] ) )
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
			new RequestData( self::reqData( [ 'Beavers' ] ) )
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
			new RequestData( self::reqData( [ 'Beavers', 'Otters' ] ) )
		);

		$this->assertSame( [ 'Beavers' ], array_column( $respData['articles'], 'title' ) );
	}

	public function testRun__noWorklistPage(): void {
		// The event's /Worklist subpage has never been created, so it holds nothing to score.
		$handler = $this->newHandlerForEventPage( 'Event_with_no_worklist' );

		$respData = $this->executeHandlerAndGetBodyData(
			$handler,
			new RequestData( self::reqData( [ 'Beavers' ] ) )
		);

		$this->assertSame( [], $respData['articles'] );
	}

	private function newHandlerForEventPage( string $eventPageDBkey ): GetWorklistPagesQualityHandler {
		$eventPage = $this->createMock( MWPageProxy::class );
		$eventPage->method( 'getNamespace' )->willReturn( NS_MAIN );
		$eventPage->method( 'getDBkey' )->willReturn( $eventPageDBkey );
		$eventPage->method( 'getWikiId' )->willReturn( WikiAwareEntity::LOCAL );
		$event = $this->createMock( ExistingEventRegistration::class );
		$event->method( 'getPage' )->willReturn( $eventPage );
		$eventLookup = $this->createMock( IEventLookup::class );
		$eventLookup->method( 'getEventByID' )->willReturn( $event );

		return new GetWorklistPagesQualityHandler(
			new HashConfig( [ 'CampaignEventsEnableWorklistCardView' => true ] ),
			$eventLookup,
			$this->createMock( IWorklistArticlesLookup::class ),
			$this->getServiceContainer()->getPageStoreFactory(),
			$this->createMock( ArticleQualityLookup::class )
		);
	}

	public function testRun__cardViewDisabled(): void {
		$handler = $this->newHandler( [], [], true, false );

		$this->expectException( HttpException::class );
		$this->expectExceptionCode( 404 );
		$this->executeHandler( $handler, new RequestData( self::reqData( [ 'Beavers' ] ) ) );
	}

	public function testRun__eventNotFound(): void {
		$handler = $this->newHandler( [], [], false );

		$this->expectException( HttpException::class );
		$this->executeHandler( $handler, new RequestData( self::reqData( [ 'Beavers' ] ) ) );
	}
}
