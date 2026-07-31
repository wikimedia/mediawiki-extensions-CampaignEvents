<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Rest;

use MediaWiki\Extension\CampaignEvents\EventDiscovery\DiscoverableEventsLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\Rest\ListDiscoverableEventsForPageHandler;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Rest\Handler;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWiki\Title\TitleFormatter;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\ListDiscoverableEventsForPageHandler
 */
class ListDiscoverableEventsForPageHandlerTest extends MediaWikiUnitTestCase {
	use HandlerTestTrait;

	private function newHandler(
		?CampaignsCentralUserLookup $centralUserLookup = null,
		?DiscoverableEventsLookup $discoverableEventsLookup = null
	): Handler {
		$titleFormatter = $this->createMock( TitleFormatter::class );
		$titleFormatter->method( 'getPrefixedText' )->willReturn( 'Some page' );
		return new ListDiscoverableEventsForPageHandler(
			$centralUserLookup ?? $this->makeCentralUserLookup(),
			$titleFormatter,
			$discoverableEventsLookup ?? $this->createMock( DiscoverableEventsLookup::class )
		);
	}

	private function makeCentralUserLookup( bool $hasGlobalAccount = true ): CampaignsCentralUserLookup {
		$lookup = $this->createMock( CampaignsCentralUserLookup::class );
		if ( $hasGlobalAccount ) {
			$lookup->method( 'newFromAuthority' )->willReturn( new CentralUser( 1 ) );
		} else {
			$lookup->method( 'newFromAuthority' )->willThrowException( new UserNotGlobalException( 1 ) );
		}
		return $lookup;
	}

	private function runHandler( Handler $handler ): array {
		return $this->executeHandlerAndGetBodyData(
			$handler,
			new RequestData( [ 'method' => 'GET' ] ),
			[],
			[],
			[ 'page' => $this->createMock( LinkTarget::class ) ]
		);
	}

	public function testRun__returnsEventsFromLookup() {
		$events = [ [ 'id' => 42, 'name' => 'Pizza party', 'url' => '/wiki/Event:Pizza party' ] ];
		$discoverableEventsLookup = $this->createMock( DiscoverableEventsLookup::class );
		$discoverableEventsLookup->method( 'getAndRecordPromotableEvents' )->willReturn( $events );

		$this->assertSame(
			$events,
			$this->runHandler( $this->newHandler( null, $discoverableEventsLookup ) )
		);
	}

	public function testRun__returnsEmptyWhenLookupHasNone() {
		$discoverableEventsLookup = $this->createMock( DiscoverableEventsLookup::class );
		$discoverableEventsLookup->method( 'getAndRecordPromotableEvents' )->willReturn( [] );

		$this->assertSame(
			[],
			$this->runHandler( $this->newHandler( null, $discoverableEventsLookup ) )
		);
	}

	public function testRun__userNotGlobal() {
		$discoverableEventsLookup = $this->createMock( DiscoverableEventsLookup::class );
		$discoverableEventsLookup->expects( $this->never() )->method( 'getAndRecordPromotableEvents' );

		$handler = $this->newHandler( $this->makeCentralUserLookup( false ), $discoverableEventsLookup );
		$this->assertSame( [], $this->runHandler( $handler ) );
	}
}
