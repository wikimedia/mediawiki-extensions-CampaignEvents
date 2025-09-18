<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Rest;

use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\Rest\ListOwnEventsForEditHandler;
use MediaWiki\Rest\Handler;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWikiUnitTestCase;

/**
 * @group Test
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\ListOwnEventsForEditHandler
 */
class ListOwnEventsForEditHandlerTest extends MediaWikiUnitTestCase {
	use HandlerTestTrait;

	protected function newHandler(
		?CampaignsCentralUserLookup $centralUserLookup = null,
		?IEventLookup $eventLookup = null
	): Handler {
		return new ListOwnEventsForEditHandler(
			$centralUserLookup ?? $this->createMock( CampaignsCentralUserLookup::class ),
			$eventLookup ?? $this->createMock( IEventLookup::class )
		);
	}

	/**
	 * @dataProvider provideRun
	 */
	public function testRun( array $expected ) {
		$lookupRet = [];
		foreach ( $expected as $eventData ) {
			$event = $this->createMock( ExistingEventRegistration::class );
			$event->method( "getID" )->willReturn( $eventData['id'] );
			$event->method( "getName" )->willReturn( $eventData['name'] );
			$lookupRet[] = $event;
		}
		$eventLookup = $this->createMock( IEventLookup::class );
		$eventLookup->method( 'getEventsForContributionAssociationByParticipant' )->willReturn( $lookupRet );

		$handler = $this->newHandler( null, $eventLookup );
		$respData = $this->executeHandlerAndGetBodyData( $handler, new RequestData( [ 'method' => 'GET' ] ) );

		$this->assertSame( $expected, $respData );
	}

	public static function provideRun() {
		yield 'No events' => [ [] ];
		yield 'Has events' => [
			[
				[
					'id' => 42,
					'name' => 'Pizza party'
				],
				[
					'id' => 24,
					'name' => 'Ytrap azzip'
				],
			],
		];
	}

	public function testRun__userNotGlobal() {
		$centralUserLookup = $this->createMock( CampaignsCentralUserLookup::class );
		$centralUserLookup->expects( $this->atLeastOnce() )
			->method( 'newFromAuthority' )
			->willThrowException( new UserNotGlobalException( 12345 ) );
		$handler = $this->newHandler( $centralUserLookup );

		$respData = $this->executeHandlerAndGetBodyData( $handler, new RequestData( [ 'method' => 'GET' ] ) );
		$this->assertSame( [], $respData );
	}
}
