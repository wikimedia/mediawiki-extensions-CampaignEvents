<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Rest;

use Generator;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventNotFoundException;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\Participants\Participant;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Questions\Answer;
use MediaWiki\Extension\CampaignEvents\Questions\EventQuestionsRegistry;
use MediaWiki\Extension\CampaignEvents\Rest\GetOwnRegistrationInfoHandler;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWikiUnitTestCase;

/**
 * @group Test
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\GetOwnRegistrationInfoHandler
 */
class GetOwnRegistrationInfoHandlerTest extends MediaWikiUnitTestCase {
	use HandlerTestTrait;

	private const REQ_DATA = [
		'pathParams' => [ 'id' => 42 ]
	];

	private function newHandler(
		?IEventLookup $eventLookup = null,
		?ParticipantsStore $participantsStore = null,
		?CampaignsCentralUserLookup $centralUserLookup = null
	): GetOwnRegistrationInfoHandler {
		if ( !$eventLookup ) {
			$mockEvent = $this->createMock( ExistingEventRegistration::class );
			$mockEvent->method( 'getParticipantQuestions' )->willReturn( range( 1, 5 ) );
			$eventLookup = $this->createMock( IEventLookup::class );
			$eventLookup->method( 'getEventByID' )->willReturn( $mockEvent );
		}
		return new GetOwnRegistrationInfoHandler(
			$eventLookup,
			$participantsStore ?? $this->createMock( ParticipantsStore::class ),
			$centralUserLookup ?? $this->createMock( CampaignsCentralUserLookup::class ),
			new EventQuestionsRegistry( true )
		);
	}

	/**
	 * @dataProvider provideRunData
	 */
	public function testRun( array $expectedResp, Participant $storedParticipant ) {
		$participantsStore = $this->createMock( ParticipantsStore::class );
		$participantsStore->method( 'getEventParticipant' )
			->willReturn( $storedParticipant );

		$handler = $this->newHandler( null, $participantsStore );
		$respData = $this->executeHandlerAndGetBodyData( $handler, new RequestData( self::REQ_DATA ) );
		$this->assertSame( $expectedResp, $respData );
	}

	public static function provideRunData(): Generator {
		$user = new CentralUser( 1 );
		$timestamp = '1688000000';
		$publicNoAnswersParticipant = new Participant(
			$user,
			$timestamp,
			10,
			false,
			[],
			null,
			null,
			false,
		);
		yield 'Public, no answers' => [
			[
				'private' => false,
				'show_contribution_association_prompt' => true,
				'answers' => [],
			],
			$publicNoAnswersParticipant
		];

		$privateWithAnswersParticipant = new Participant(
			$user,
			$timestamp,
			10,
			true,
			[
				new Answer( 1, 2, null ),
				new Answer( 5, 3, 'foo' ),
			],
			$timestamp,
			null,
			false,
		);
		yield 'Private with answers' => [
			[
				'private' => true,
				'show_contribution_association_prompt' => true,
				'answers' => [
					'gender' => [ 'value' => 2 ],
					'affiliate' => [ 'value' => 3, 'other' => 'foo' ],
				],
			],
			$privateWithAnswersParticipant
		];

		yield 'Chose to hide contribution dialog' => [
			[
				'private' => false,
				'show_contribution_association_prompt' => false,
				'answers' => [],
			],
			new Participant(
				$user,
				$timestamp,
				10,
				false,
				[],
				null,
				null,
				true,
			)
		];
	}

	public function doTestRunExpectingError(
		string $expectedMsg,
		int $expectedCode,
		?IEventLookup $eventLookup = null,
		?CampaignsCentralUserLookup $centralUserLookup = null,
		?ParticipantsStore $participantsStore = null
	) {
		$handler = $this->newHandler( $eventLookup, $participantsStore, $centralUserLookup );
		try {
			$this->executeHandler( $handler, new RequestData( self::REQ_DATA ) );
			$this->fail( 'No exception thrown' );
		} catch ( LocalizedHttpException $e ) {
			$this->assertSame( $expectedMsg, $e->getMessageValue()->getKey() );
			$this->assertSame( $expectedCode, $e->getCode() );
		}
	}

	public function testRun__eventDoesNotExist() {
		$nonExistingEventLookup = $this->createMock( IEventLookup::class );
		$nonExistingEventLookup->expects( $this->once() )
			->method( 'getEventByID' )
			->willThrowException( $this->createMock( EventNotFoundException::class ) );
		$this->doTestRunExpectingError(
			'campaignevents-rest-event-not-found',
			404,
			$nonExistingEventLookup
		);
	}

	public function testRun__userIsNotGlobal() {
		$notGlobalUserLookup = $this->createMock( CampaignsCentralUserLookup::class );
		$notGlobalUserLookup->method( 'newFromAuthority' )
			->willThrowException( $this->createMock( UserNotGlobalException::class ) );
		$this->doTestRunExpectingError(
			'campaignevents-register-not-allowed',
			403,
			null,
			$notGlobalUserLookup
		);
	}

	public function testRun__notAParticipant() {
		$notParticipantStore = $this->createMock( ParticipantsStore::class );
		$notParticipantStore->method( 'getEventParticipant' )->willReturn( null );
		$this->doTestRunExpectingError(
			'campaignevents-rest-get-registration-info-notparticipant',
			404,
			null,
			null,
			$notParticipantStore
		);
	}
}
