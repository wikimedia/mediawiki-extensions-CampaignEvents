<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Rest;

use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\EventGoal\GoalProgressFormatter;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\Rest\ListOwnEventsForEditHandler;
use MediaWiki\Extension\CampaignEvents\Worklist\WorklistEventsStore;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Rest\Handler;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWiki\Title\TitleFormatter;
use MediaWikiUnitTestCase;

/**
 * @group Test
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\ListOwnEventsForEditHandler
 */
class ListOwnEventsForEditHandlerTest extends MediaWikiUnitTestCase {
	use HandlerTestTrait;

	protected function newHandler(
		?CampaignsCentralUserLookup $centralUserLookup = null,
		?IEventLookup $eventLookup = null,
		?WorklistEventsStore $worklistEventsStore = null,
		?TitleFormatter $titleFormatter = null
	): Handler {
		return new ListOwnEventsForEditHandler(
			$centralUserLookup ?? $this->createMock( CampaignsCentralUserLookup::class ),
			$eventLookup ?? $this->createMock( IEventLookup::class ),
			$this->createMock( GoalProgressFormatter::class ),
			$worklistEventsStore ?? $this->createMock( WorklistEventsStore::class ),
			$titleFormatter ?? $this->createMock( TitleFormatter::class )
		);
	}

	private function makeEventLookupReturning( array $events ): IEventLookup {
		$objects = [];
		foreach ( $events as $eventData ) {
			$event = $this->createMock( ExistingEventRegistration::class );
			$event->method( 'getID' )->willReturn( $eventData['id'] );
			$event->method( 'getName' )->willReturn( $eventData['name'] );
			$objects[] = $event;
		}
		$eventLookup = $this->createMock( IEventLookup::class );
		$eventLookup->method( 'getEventsForContributionAssociationByParticipant' )->willReturn( $objects );
		return $eventLookup;
	}

	/**
	 * @dataProvider provideRun
	 */
	public function testRun( array $events, array $expected ) {
		$handler = $this->newHandler( null, $this->makeEventLookupReturning( $events ) );
		$respData = $this->executeHandlerAndGetBodyData(
			$handler, new RequestData( [ 'method' => 'GET' ] ), [], [], [ 'title' => null ]
		);

		$this->assertSame( $expected, $respData );
	}

	public static function provideRun() {
		yield 'No events' => [ [], [] ];
		yield 'Has events (no page, so none auto-associable)' => [
			[
				[ 'id' => 42, 'name' => 'Pizza party' ],
				[ 'id' => 24, 'name' => 'Ytrap azzip' ],
			],
			[
				[ 'id' => 42, 'name' => 'Pizza party', 'autoAssociable' => false ],
				[ 'id' => 24, 'name' => 'Ytrap azzip', 'autoAssociable' => false ],
			],
		];
	}

	public function testRun__marksAutoAssociableEventForPage() {
		$eventLookup = $this->makeEventLookupReturning( [
			[ 'id' => 42, 'name' => 'Pizza party' ],
			[ 'id' => 24, 'name' => 'Ytrap azzip' ],
		] );

		$worklistEventsStore = $this->createMock( WorklistEventsStore::class );
		$worklistEventsStore->method( 'filterEventsByPageInWorklist' )->willReturn( [ 42 ] );

		$page = $this->createMock( LinkTarget::class );
		$titleFormatter = $this->createMock( TitleFormatter::class );
		$titleFormatter->method( 'getPrefixedText' )->willReturn( 'Some page' );

		$handler = $this->newHandler( null, $eventLookup, $worklistEventsStore, $titleFormatter );
		$respData = $this->executeHandlerAndGetBodyData(
			$handler, new RequestData( [ 'method' => 'GET' ] ), [], [], [ 'title' => $page ]
		);

		$this->assertSame(
			[
				[ 'id' => 42, 'name' => 'Pizza party', 'autoAssociable' => true ],
				[ 'id' => 24, 'name' => 'Ytrap azzip', 'autoAssociable' => false ],
			],
			$respData
		);
	}

	public function testRun__userNotGlobal() {
		$centralUserLookup = $this->createMock( CampaignsCentralUserLookup::class );
		$centralUserLookup->expects( $this->atLeastOnce() )
			->method( 'newFromAuthority' )
			->willThrowException( new UserNotGlobalException( 12345 ) );
		$handler = $this->newHandler( $centralUserLookup );

		$respData = $this->executeHandlerAndGetBodyData(
			$handler, new RequestData( [ 'method' => 'GET' ] ), [], [], [ 'title' => null ]
		);
		$this->assertSame( [], $respData );
	}
}
