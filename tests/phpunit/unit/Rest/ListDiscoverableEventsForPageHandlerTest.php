<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Rest;

use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\EventDiscovery\IDiscoveryPromotionStore;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWPageProxy;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageURLResolver;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\Rest\ListDiscoverableEventsForPageHandler;
use MediaWiki\Permissions\Authority;
use MediaWiki\Rest\Handler;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\Options\UserOptionsLookup;
use MediaWiki\User\UserIdentity;
use MediaWikiUnitTestCase;

/**
 * @group Test
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\ListDiscoverableEventsForPageHandler
 */
class ListDiscoverableEventsForPageHandlerTest extends MediaWikiUnitTestCase {
	use HandlerTestTrait;

	private const REQUEST = [ 'method' => 'GET' ];
	private const VALIDATED_PARAMS = [ 'page' => 'Some page' ];

	private function newHandler(
		?CampaignsCentralUserLookup $centralUserLookup = null,
		?IEventLookup $eventLookup = null,
		?IDiscoveryPromotionStore $promotionStore = null,
		?UserOptionsLookup $userOptionsLookup = null,
		?TitleFactory $titleFactory = null
	): Handler {
		$pageURLResolver = $this->createMock( PageURLResolver::class );
		$pageURLResolver->method( 'getUrl' )->willReturnCallback(
			static fn ( MWPageProxy $page ): string => '/wiki/' . $page->getPrefixedText()
		);
		return new ListDiscoverableEventsForPageHandler(
			$centralUserLookup ?? $this->createMock( CampaignsCentralUserLookup::class ),
			$eventLookup ?? $this->createMock( IEventLookup::class ),
			$promotionStore ?? $this->getRecordingPromotionStore(),
			$userOptionsLookup ?? $this->getUserOptionsLookup(),
			$titleFactory ?? $this->getExistingPageTitleFactory(),
			$pageURLResolver
		);
	}

	private function getUserOptionsLookup( bool $discoveryEnabled = true ): UserOptionsLookup {
		$userOptionsLookup = $this->createMock( UserOptionsLookup::class );
		$userOptionsLookup->method( 'getBoolOption' )->willReturn( $discoveryEnabled );
		return $userOptionsLookup;
	}

	private function getExistingPageTitleFactory( int $articleID = 123 ): TitleFactory {
		$title = $this->createMock( Title::class );
		$title->method( 'getArticleID' )->willReturn( $articleID );
		$title->method( 'getPrefixedText' )->willReturn( 'Some page' );
		$titleFactory = $this->createMock( TitleFactory::class );
		$titleFactory->method( 'newFromText' )->willReturn( $title );
		return $titleFactory;
	}

	private function getRecordingPromotionStore( bool $newlyRecorded = true ): IDiscoveryPromotionStore {
		$promotionStore = $this->createMock( IDiscoveryPromotionStore::class );
		$promotionStore->method( 'tryRecordPromotion' )->willReturn( $newlyRecorded );
		return $promotionStore;
	}

	private function getEventLookupReturning( array $events ): IEventLookup {
		$objects = [];
		foreach ( $events as $eventData ) {
			$page = $this->createMock( MWPageProxy::class );
			$page->method( 'getPrefixedText' )->willReturn( $eventData['page'] );
			$event = $this->createMock( ExistingEventRegistration::class );
			$event->method( 'getID' )->willReturn( $eventData['id'] );
			$event->method( 'getName' )->willReturn( $eventData['name'] );
			$event->method( 'getPage' )->willReturn( $page );
			$event->method( 'getEndUTCTimestamp' )->willReturn( '20300101000000' );
			$objects[] = $event;
		}
		$eventLookup = $this->createMock( IEventLookup::class );
		$eventLookup->method( 'getEventsForDiscoveryByPage' )->willReturn( $objects );
		return $eventLookup;
	}

	private function getNamedAuthority(): Authority {
		$authority = $this->createMock( Authority::class );
		$authority->method( 'isNamed' )->willReturn( true );
		$authority->method( 'getUser' )->willReturn( $this->createMock( UserIdentity::class ) );
		return $authority;
	}

	private function getUnnamedAuthority(): Authority {
		$authority = $this->createMock( Authority::class );
		$authority->method( 'isNamed' )->willReturn( false );
		return $authority;
	}

	private function runHandler( Handler $handler, ?Authority $authority = null ): array {
		return $this->executeHandlerAndGetBodyData(
			$handler,
			new RequestData( self::REQUEST ),
			[],
			[],
			self::VALIDATED_PARAMS,
			[],
			$authority ?? $this->getNamedAuthority()
		);
	}

	/**
	 * @dataProvider provideRun
	 */
	public function testRun( array $events, array $expected ) {
		$handler = $this->newHandler( null, $this->getEventLookupReturning( $events ) );
		$this->assertSame( $expected, $this->runHandler( $handler ) );
	}

	public static function provideRun() {
		yield 'No events' => [ [], [] ];
		yield 'Has events' => [
			[
				[ 'id' => 42, 'name' => 'Pizza party', 'page' => 'Event:Pizza party' ],
				[ 'id' => 24, 'name' => 'Ytrap azzip', 'page' => 'Event:Ytrap azzip' ],
			],
			[
				[ 'id' => 42, 'name' => 'Pizza party', 'url' => '/wiki/Event:Pizza party' ],
				[ 'id' => 24, 'name' => 'Ytrap azzip', 'url' => '/wiki/Event:Ytrap azzip' ],
			],
		];
	}

	public function testRun__notNamed() {
		$eventLookup = $this->createMock( IEventLookup::class );
		$eventLookup->expects( $this->never() )->method( 'getEventsForDiscoveryByPage' );
		$handler = $this->newHandler( null, $eventLookup );
		$this->assertSame( [], $this->runHandler( $handler, $this->getUnnamedAuthority() ) );
	}

	public function testRun__optedOut() {
		$eventLookup = $this->createMock( IEventLookup::class );
		$eventLookup->expects( $this->never() )->method( 'getEventsForDiscoveryByPage' );
		$handler = $this->newHandler( null, $eventLookup, null, $this->getUserOptionsLookup( false ) );
		$this->assertSame( [], $this->runHandler( $handler ) );
	}

	public function testRun__userNotGlobal() {
		$centralUserLookup = $this->createMock( CampaignsCentralUserLookup::class );
		$centralUserLookup->expects( $this->atLeastOnce() )
			->method( 'newFromAuthority' )
			->willThrowException( new UserNotGlobalException( 12345 ) );
		$handler = $this->newHandler( $centralUserLookup );
		$this->assertSame( [], $this->runHandler( $handler ) );
	}

	public function testRun__pageDoesNotExist() {
		$eventLookup = $this->createMock( IEventLookup::class );
		$eventLookup->expects( $this->never() )->method( 'getEventsForDiscoveryByPage' );
		$handler = $this->newHandler(
			null, $eventLookup, null, null, $this->getExistingPageTitleFactory( 0 )
		);
		$this->assertSame( [], $this->runHandler( $handler ) );
	}

	public function testRun__invalidPageTitle() {
		$eventLookup = $this->createMock( IEventLookup::class );
		$eventLookup->expects( $this->never() )->method( 'getEventsForDiscoveryByPage' );
		$titleFactory = $this->createMock( TitleFactory::class );
		$titleFactory->method( 'newFromText' )->willReturn( null );
		$handler = $this->newHandler( null, $eventLookup, null, null, $titleFactory );
		$this->assertSame( [], $this->runHandler( $handler ) );
	}

	public function testRun__alreadyPromoted() {
		$eventLookup = $this->getEventLookupReturning(
			[ [ 'id' => 7, 'name' => 'Seen event', 'page' => 'Event:Seen event' ] ]
		);
		$handler = $this->newHandler( null, $eventLookup, $this->getRecordingPromotionStore( false ) );
		$this->assertSame( [], $this->runHandler( $handler ) );
	}
}
