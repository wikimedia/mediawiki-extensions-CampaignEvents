<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Rest;

use Generator;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventNotFoundException;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserLinker;
use MediaWiki\Extension\CampaignEvents\Participants\Participant;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Rest\ListParticipantsHandler;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWikiIntegrationTestCase;

/**
 * @group Test
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\ListParticipantsHandler
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\EventIDParamTrait
 * TODO: Make a unit test once Language is available in the REST framework (T269492)
 */
class ListParticipantsHandlerTest extends MediaWikiIntegrationTestCase {
	use HandlerTestTrait;

	private const REQ_DATA = [
		'pathParams' => [ 'id' => 42 ]
	];

	protected function setUp(): void {
		// Make sure that the user language is English so that we can verify the formatted timestamp
		$this->setUserLang( 'en' );
	}

	private function newHandler(
		IEventLookup $eventLookup = null,
		ParticipantsStore $participantsStore = null,
		CampaignsCentralUserLookup $centralUserLookup = null
	): ListParticipantsHandler {
		return new ListParticipantsHandler(
			$eventLookup ?? $this->createMock( IEventLookup::class ),
			$participantsStore ?? $this->createMock( ParticipantsStore::class ),
			$centralUserLookup ?? $this->createMock( CampaignsCentralUserLookup::class ),
			$this->createMock( UserLinker::class )
		);
	}

	/**
	 * @dataProvider provideRunData
	 */
	public function testRun(
		array $expectedResp,
		ParticipantsStore $participantsStore
	) {
		$handler = $this->newHandler( null, $participantsStore, null );
		$respData = $this->executeHandlerAndGetBodyData( $handler, new RequestData( self::REQ_DATA ) );

		$this->assertSame( $expectedResp, $respData );
	}

	public function provideRunData(): Generator {
		yield 'No participants' => [
			[],
			$this->createMock( ParticipantsStore::class ),
			$this->createMock( CampaignsCentralUserLookup::class )
		];

		$participants = [];
		$expected = [];
		for ( $i = 1; $i < 4; $i++ ) {
			$participants[] = new Participant( new CentralUser( $i ), '20220315120000', $i );

			$expected[] = [
				'participant_id' => $i,
				'user_id' => $i,
				'user_name' => '',
				'user_page' => [
					'path' => '',
					'title' => '',
					'classes' => ''
				],
				'user_registered_at' => wfTimestamp( TS_MW, '20220315120000' ),
				'user_registered_at_formatted' => '12:00, 15 March 2022'
			];
		}

		$partStore = $this->createMock( ParticipantsStore::class );
		$partStore->expects( $this->atLeastOnce() )->method( 'getEventParticipants' )->willReturn( $participants );
		yield 'Has participants' => [
			$expected,
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
