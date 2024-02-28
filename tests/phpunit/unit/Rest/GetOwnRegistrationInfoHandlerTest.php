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
		IEventLookup $eventLookup = null,
		ParticipantsStore $participantsStore = null,
		CampaignsCentralUserLookup $centralUserLookup = null
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
	public function testRun( array $expectedResp, ParticipantsStore $participantsStore ) {
		$handler = $this->newHandler( null, $participantsStore );
		$respData = $this->executeHandlerAndGetBodyData( $handler, new RequestData( self::REQ_DATA ) );
		$this->assertSame( $expectedResp, $respData );
	}

	public function provideRunData(): Generator {
		$user = new CentralUser( 1 );
		$timestamp = '1688000000';

		$publicNoAnswersParticipant = new Participant(
			$user,
			$timestamp,
			10,
			false,
			[],
			null,
			null
		);
		$publicNoAnswersStore = $this->createMock( ParticipantsStore::class );
		$publicNoAnswersStore->method( 'getEventParticipant' )
			->willReturn( $publicNoAnswersParticipant );
		yield 'Public, no answers' => [
			[
				'private' => false,
				'answers' => [],
			],
			$publicNoAnswersStore
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
			null
		);
		$privateWithAnswersStore = $this->createMock( ParticipantsStore::class );
		$privateWithAnswersStore->method( 'getEventParticipant' )
			->willReturn( $privateWithAnswersParticipant );
		yield 'Private with answers' => [
			[
				'private' => true,
				'answers' => [
					'gender' => [ 'value' => 2 ],
					'affiliate' => [ 'value' => 3, 'other' => 'foo' ],
				],
			],
			$privateWithAnswersStore
		];
	}

	/**
	 * @dataProvider provideRunErrors
	 */
	public function testRun__errors(
		string $expectedMsg,
		int $expectedCode,
		IEventLookup $eventLookup = null,
		CampaignsCentralUserLookup $centralUserLookup = null,
		ParticipantsStore $participantsStore = null
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

	public function provideRunErrors(): Generator {
		$nonExistingEventLookup = $this->createMock( IEventLookup::class );
		$nonExistingEventLookup->expects( $this->once() )
			->method( 'getEventByID' )
			->willThrowException( $this->createMock( EventNotFoundException::class ) );
		yield 'Event does not exist' => [
			'campaignevents-rest-event-not-found',
			404,
			$nonExistingEventLookup
		];

		$notGlobalUserLookup = $this->createMock( CampaignsCentralUserLookup::class );
		$notGlobalUserLookup->method( 'newFromAuthority' )
			->willThrowException( $this->createMock( UserNotGlobalException::class ) );
		yield 'User not global' => [
			'campaignevents-register-not-allowed',
			403,
			null,
			$notGlobalUserLookup
		];

		$notParticipantStore = $this->createMock( ParticipantsStore::class );
		$notParticipantStore->method( 'getEventParticipant' )->willReturn( null );
		yield 'Not a participant' => [
			'campaignevents-rest-get-registration-info-notparticipant',
			404,
			null,
			null,
			$notParticipantStore
		];
	}
}
