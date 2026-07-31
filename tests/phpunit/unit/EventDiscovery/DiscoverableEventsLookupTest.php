<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\EventDiscovery;

use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\EventDiscovery\DiscoverableEventsLookup;
use MediaWiki\Extension\CampaignEvents\EventDiscovery\IDiscoveryPromotionStore;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWPageProxy;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageURLResolver;
use MediaWiki\Permissions\Authority;
use MediaWiki\Tests\Unit\Permissions\MockAuthorityTrait;
use MediaWiki\User\Options\UserOptionsLookup;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\CampaignEvents\EventDiscovery\DiscoverableEventsLookup
 */
class DiscoverableEventsLookupTest extends MediaWikiUnitTestCase {
	use MockAuthorityTrait;

	private function newLookup(
		?IEventLookup $eventLookup = null,
		?IDiscoveryPromotionStore $promotionStore = null,
		?UserOptionsLookup $userOptionsLookup = null
	): DiscoverableEventsLookup {
		$pageURLResolver = $this->createMock( PageURLResolver::class );
		$pageURLResolver->method( 'getUrl' )->willReturnCallback(
			static fn ( MWPageProxy $page ): string => '/wiki/' . $page->getPrefixedText()
		);
		return new DiscoverableEventsLookup(
			$eventLookup ?? $this->createMock( IEventLookup::class ),
			$promotionStore ?? $this->makePromotionStore(),
			$userOptionsLookup ?? $this->makeOptedInLookup(),
			$pageURLResolver
		);
	}

	private function makeOptedInLookup( bool $optedIn = true ): UserOptionsLookup {
		$lookup = $this->createMock( UserOptionsLookup::class );
		$lookup->method( 'getBoolOption' )->willReturn( $optedIn );
		return $lookup;
	}

	private function makePromotionStore( bool $recorded = true ): IDiscoveryPromotionStore {
		$store = $this->createMock( IDiscoveryPromotionStore::class );
		$store->method( 'tryRecordPromotion' )->willReturn( $recorded );
		return $store;
	}

	private function makeAuthority( bool $isNamed = true ): Authority {
		return $isNamed ?
			$this->mockRegisteredAuthorityWithPermissions( [] ) :
			$this->mockTempAuthorityWithPermissions( [] );
	}

	private function makeEventLookupReturning( array $events ): IEventLookup {
		$objects = [];
		foreach ( $events as $data ) {
			$page = $this->createMock( MWPageProxy::class );
			$page->method( 'getPrefixedText' )->willReturn( $data['page'] );
			$event = $this->createMock( ExistingEventRegistration::class );
			$event->method( 'getID' )->willReturn( $data['id'] );
			$event->method( 'getName' )->willReturn( $data['name'] );
			$event->method( 'getPage' )->willReturn( $page );
			$event->method( 'getEndUTCTimestamp' )->willReturn( '20300101000000' );
			$objects[] = $event;
		}
		$lookup = $this->createMock( IEventLookup::class );
		$lookup->method( 'getEventsForDiscoveryByPage' )->willReturn( $objects );
		return $lookup;
	}

	private function callWith( DiscoverableEventsLookup $lookup, ?Authority $authority = null ): array {
		return $lookup->getAndRecordPromotableEvents(
			$authority ?? $this->makeAuthority(),
			new CentralUser( 1 ),
			'Some page',
			'testwiki',
			50
		);
	}

	public function testReturnsFormattedNewlyPromotedEvents() {
		$eventLookup = $this->makeEventLookupReturning( [
			[ 'id' => 42, 'name' => 'Pizza party', 'page' => 'Event:Pizza party' ],
			[ 'id' => 24, 'name' => 'Ytrap azzip', 'page' => 'Event:Ytrap azzip' ],
		] );
		$this->assertSame(
			[
				[ 'id' => 42, 'name' => 'Pizza party', 'url' => '/wiki/Event:Pizza party' ],
				[ 'id' => 24, 'name' => 'Ytrap azzip', 'url' => '/wiki/Event:Ytrap azzip' ],
			],
			$this->callWith( $this->newLookup( $eventLookup ) )
		);
	}

	public function testExcludesTemporaryAccounts() {
		$eventLookup = $this->createMock( IEventLookup::class );
		$eventLookup->expects( $this->never() )->method( 'getEventsForDiscoveryByPage' );
		$this->assertSame(
			[],
			$this->callWith( $this->newLookup( $eventLookup ), $this->makeAuthority( false ) )
		);
	}

	public function testExcludesOptedOutUsers() {
		$eventLookup = $this->createMock( IEventLookup::class );
		$eventLookup->expects( $this->never() )->method( 'getEventsForDiscoveryByPage' );
		$lookup = $this->newLookup( $eventLookup, null, $this->makeOptedInLookup( false ) );
		$this->assertSame( [], $this->callWith( $lookup ) );
	}

	public function testExcludesAlreadyPromotedEvents() {
		$eventLookup = $this->makeEventLookupReturning( [
			[ 'id' => 7, 'name' => 'Seen event', 'page' => 'Event:Seen event' ],
		] );
		$lookup = $this->newLookup( $eventLookup, $this->makePromotionStore( false ) );
		$this->assertSame( [], $this->callWith( $lookup ) );
	}
}
