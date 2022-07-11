<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Rest;

use Generator;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventNotFoundException;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsUser;
use MediaWiki\Extension\CampaignEvents\Participants\Participant;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Rest\ListParticipantsHandler;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWikiUnitTestCase;

/**
 * @group Test
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\ListParticipantsHandler
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\EventIDParamTrait
 */
class ListParticipantsHandlerTest extends MediaWikiUnitTestCase {
	use HandlerTestTrait;

	private const REQ_DATA = [
		'pathParams' => [ 'id' => 42 ]
	];

	private function newHandler(
		IEventLookup $eventLookup = null,
		ParticipantsStore $participantsStore = null
	): ListParticipantsHandler {
		return new ListParticipantsHandler(
			$eventLookup ?? $this->createMock( IEventLookup::class ),
			$participantsStore ?? $this->createMock( ParticipantsStore::class )
		);
	}

	/**
	 * @dataProvider provideRunData
	 */
	public function testRun( array $expectedResp, ParticipantsStore $participantsStore ) {
		$handler = $this->newHandler( null, $participantsStore );
		$respData = $this->executeHandlerAndGetBodyData( $handler, new RequestData( self::REQ_DATA ) );
		$this->assertSame( $expectedResp, $respData );
	}

	public function provideRunData(): Generator {
		yield 'No participants' => [ [], $this->createMock( ParticipantsStore::class ) ];

		$users = [];
		for ( $i = 1; $i < 4; $i++ ) {
			$curUser = $this->createMock( ICampaignsUser::class );
			$curUser->method( 'getLocalID' )->willReturn( $i );
			$participant = new Participant( $curUser, '20220315120000' );
			$users[] = $participant;
		}
		$partStore = $this->createMock( ParticipantsStore::class );
		$partStore->expects( $this->atLeastOnce() )->method( 'getEventParticipants' )->willReturn( $users );
		yield 'Has participants' => [
			[ [ 'user_id' => 1 ], [ 'user_id' => 2 ], [ 'user_id' => 3 ] ],
			$partStore
		];
	}

	public function testRun__invalidEvent() {
		$eventLookup = $this->createMock( IEventLookup::class );
		$eventLookup->expects( $this->once() )
			->method( 'getEventByID' )
			->willThrowException( $this->createMock( EventNotFoundException::class ) );
		$handler = $this->newHandler( $eventLookup );
		try {
			$this->executeHandler( $handler, new RequestData( self::REQ_DATA ) );
			$this->fail( 'No exception thrown' );
		} catch ( LocalizedHttpException $e ) {
			$this->assertSame(
				'campaignevents-rest-event-not-found',
				$e->getMessageValue()->getKey()
			);
			$this->assertSame( 404, $e->getCode() );
		}
	}
}
