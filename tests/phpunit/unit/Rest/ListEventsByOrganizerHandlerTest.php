<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Rest;

use Generator;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\Rest\ListEventsByOrganizerHandler;
use MediaWiki\Rest\Handler;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentityValue;
use MediaWiki\User\UserNameUtils;
use MediaWikiUnitTestCase;

/**
 * @group Test
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\ListEventsByOrganizerHandler
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\AbstractListEventsByUserHandler
 */
class ListEventsByOrganizerHandlerTest extends MediaWikiUnitTestCase {
	use HandlerTestTrait;

	protected const DEFAULT_REQ_DATA = [
		'method' => 'GET',
		'pathParams' => [
			'user' => 'Admin',
		]
	];

	/**
	 * @param IEventLookup|null $eventLookup
	 * @return Handler
	 */
	protected function newHandler(
		IEventLookup $eventLookup = null
	): Handler {
		$userLookup = $this->createMock( CampaignsCentralUserLookup::class );
		$userLookup->method( "getCentralID" )->willReturn( 1 );

		$userFactory = $this->createMock( UserFactory::class );
		$userNameUtils = $this->createMock( UserNameUtils::class );

		$handler = new ListEventsByOrganizerHandler(
			$eventLookup,
			$userLookup,
			$userFactory,
			$userNameUtils
		);

		return $handler;
	}

	/**
	 * @param IEventLookup $eventLookup
	 * @param array $expected
	 * @dataProvider provideExecuteDataForEventListingTest
	 */
	public function testExecute( IEventLookup $eventLookup, array $expected ) {
		$handler = $this->newHandler( $eventLookup );

		$request = new RequestData( self::DEFAULT_REQ_DATA );

		$respData = $this->executeHandlerAndGetBodyData(
			$handler,
			$request,
			[],
			[],
			[ "user" => new UserIdentityValue( 1, "Admin" ) ]
		);

		$this->assertSame( $expected, $respData );
	}

	public function provideExecuteDataForEventListingTest(): Generator {
		$firstEventLookup = $this->createMock( IEventLookup::class );
		$firstEventLookup->method( 'getEventsByOrganizer' )->willReturn( [] );
		yield 'No events' => [ $firstEventLookup, [] ];

		$firstEvent = $this->createMock( ExistingEventRegistration::class );
		$firstEvent->method( "getID" )->willReturn( 2 );
		$firstEvent->method( "getName" )->willReturn( "Test Editathon Event 1" );

		$secondEvent = $this->createMock( ExistingEventRegistration::class );
		$secondEvent->method( "getID" )->willReturn( 5 );
		$secondEvent->method( "getName" )->willReturn( "Test Editathon Event 2" );

		$eventsList = [
			$firstEvent,
			$secondEvent
		];
		$secondEventLookup = $this->createMock( IEventLookup::class );
		$secondEventLookup->method( 'getEventsByOrganizer' )->willReturn( $eventsList );

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
		yield 'Return events' => [ $secondEventLookup, $expectedEvents ];
	}
}
