<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Rest;

use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\Rest\ListEventsByParticipantHandler;
use MediaWiki\Rest\Handler;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWikiUnitTestCase;

/**
 * @group Test
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\ListEventsByParticipantHandler
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\AbstractListEventsByUserHandler
 */
class ListEventsByParticipantHandlerTest extends MediaWikiUnitTestCase {
	use HandlerTestTrait;

	protected const DEFAULT_REQ_DATA = [
		'method' => 'GET',
		'pathParams' => [
			'userid' => 1,
		]
	];

	/**
	 * @param IEventLookup|null $eventLookup
	 * @param CampaignsCentralUserLookup|null $centralUserLookup
	 * @return Handler
	 */
	protected function newHandler(
		?IEventLookup $eventLookup = null,
		?CampaignsCentralUserLookup $centralUserLookup = null
	): Handler {
		if ( !$centralUserLookup ) {
			$centralUserLookup = $this->createMock( CampaignsCentralUserLookup::class );
			$centralUserLookup->method( 'existsAndIsVisible' )->willReturn( true );
		}
		return new ListEventsByParticipantHandler(
			$eventLookup ?? $this->createMock( IEventLookup::class ),
			$centralUserLookup
		);
	}

	public function testExecute__noEvents() {
		$noEventsLookup = $this->createMock( IEventLookup::class );
		$noEventsLookup->method( 'getEventsByParticipant' )->willReturn( [] );
		$handler = $this->newHandler( $noEventsLookup );
		$respData = $this->executeHandlerAndGetBodyData( $handler, new RequestData( self::DEFAULT_REQ_DATA ) );
		$this->assertSame( [], $respData );
	}

	public function testExecute__hasEvents() {
		$firstEvent = $this->createMock( ExistingEventRegistration::class );
		$firstEvent->method( "getID" )->willReturn( 6 );
		$firstEvent->method( "getName" )->willReturn( "Test Editathon Event 3" );

		$secondEvent = $this->createMock( ExistingEventRegistration::class );
		$secondEvent->method( "getID" )->willReturn( 9 );
		$secondEvent->method( "getName" )->willReturn( "Test Editathon Event 4" );

		$eventsList = [
			$firstEvent,
			$secondEvent
		];
		$eventLookup = $this->createMock( IEventLookup::class );
		$eventLookup->method( 'getEventsByParticipant' )->willReturn( $eventsList );

		$handler = $this->newHandler( $eventLookup );
		$respData = $this->executeHandlerAndGetBodyData( $handler, new RequestData( self::DEFAULT_REQ_DATA ) );

		$expectedEvents = [
			[
				"event_id" => $firstEvent->getID(),
				"event_name" => $firstEvent->getName()
			],
			[
				"event_id" => $secondEvent->getID(),
				"event_name" => $secondEvent->getName()
			]
		];
		$this->assertSame( $expectedEvents, $respData );
	}

	public function testExecute__deletedEvent() {
		$deletedEvent = $this->createMock( ExistingEventRegistration::class );
		$deletedEvent->method( "getID" )->willReturn( 123 );
		$deletedEvent->method( "getName" )->willReturn( "Deleted event" );
		$deletedEvent->method( 'getDeletionTimestamp' )->willReturn( '1654000000' );

		$delEventLookup = $this->createMock( IEventLookup::class );
		$delEventLookup->method( 'getEventsByParticipant' )->willReturn( [ $deletedEvent ] );

		$handler = $this->newHandler( $delEventLookup );
		$respData = $this->executeHandlerAndGetBodyData( $handler, new RequestData( self::DEFAULT_REQ_DATA ) );

		$expected = [
			[
				"event_id" => $deletedEvent->getID(),
				"event_name" => $deletedEvent->getName(),
				'event_deleted' => true
			]
		];
		$this->assertSame( $expected, $respData );
	}

	public function testExecute__userNotFound() {
		$centralUserLookup = $this->createMock( CampaignsCentralUserLookup::class );
		$centralUserLookup->expects( $this->atLeastOnce() )->method( 'existsAndIsVisible' )->willReturn( false );
		$handler = $this->newHandler( null, $centralUserLookup );
		$request = new RequestData( self::DEFAULT_REQ_DATA );

		try {
			$this->executeHandler( $handler, $request );
			$this->fail( 'No exception thrown' );
		} catch ( LocalizedHttpException $e ) {
			$this->assertSame( 'campaignevents-rest-user-not-found', $e->getMessageValue()->getKey() );
			$this->assertSame( 404, $e->getCode() );
		}
	}
}
