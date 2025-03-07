<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Rest;

use Generator;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventNotFoundException;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\Messaging\CampaignsUserMailer;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserLinker;
use MediaWiki\Extension\CampaignEvents\Participants\Participant;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\Questions\Answer;
use MediaWiki\Extension\CampaignEvents\Questions\EventQuestionsRegistry;
use MediaWiki\Extension\CampaignEvents\Rest\ListParticipantsHandler;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWiki\User\UserFactory;
use MediaWikiIntegrationTestCase;
use Wikimedia\Message\IMessageFormatterFactory;
use Wikimedia\Message\ITextFormatter;
use Wikimedia\Message\MessageValue;

/**
 * @group Test
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\ListParticipantsHandler
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\EventIDParamTrait
 * TODO: Make a unit test once Language is available in the REST framework (T269492) and we get rid
 * of User/UserArray.
 */
class ListParticipantsHandlerTest extends MediaWikiIntegrationTestCase {
	use HandlerTestTrait;

	private const REQ_DATA = [
		'pathParams' => [ 'id' => 42 ],
		'queryParams' => [ 'include_private' => false ],
	];

	protected function setUp(): void {
		// Make sure that the user language is English so that we can verify the formatted timestamp
		$this->setUserLang( 'en' );
	}

	private function newHandler(
		?PermissionChecker $permissionChecker = null,
		?IEventLookup $eventLookup = null,
		?ParticipantsStore $participantsStore = null,
		?CampaignsCentralUserLookup $centralUserLookup = null,
		?UserFactory $userFactory = null,
		?UserLinker $userLink = null
	): ListParticipantsHandler {
		if ( !$permissionChecker ) {
			$permissionChecker = $this->createMock( PermissionChecker::class );
			$permissionChecker->method( 'userCanViewPrivateParticipants' )->willReturn( true );
			$permissionChecker->method( 'userCanEmailParticipants' )->willReturn( true );
		}

		$msgFormatter = $this->createMock( ITextFormatter::class );
		$msgFormatter->method( 'format' )->willReturnCallback( static fn ( MessageValue $msg ) => $msg->getKey() );
		$msgFormatterFactory = $this->createMock( IMessageFormatterFactory::class );
		$msgFormatterFactory->method( 'getTextFormatter' )->willReturn( $msgFormatter );
		return new ListParticipantsHandler(
			$permissionChecker,
			$eventLookup ?? $this->createMock( IEventLookup::class ),
			$participantsStore ?? $this->createMock( ParticipantsStore::class ),
			$centralUserLookup ?? $this->createMock( CampaignsCentralUserLookup::class ),
			$userLink ?? $this->createMock( UserLinker::class ),
			$userFactory ?? $this->createMock( UserFactory::class ),
			$this->createMock( CampaignsUserMailer::class ),
			new EventQuestionsRegistry( true ),
			$msgFormatterFactory
		);
	}

	/**
	 * @dataProvider provideRunData
	 */
	public function testRun(
		array $expectedResp,
		array $storedParticipants,
		array $usernamesMap
	) {
		$participantsStore = $this->createMock( ParticipantsStore::class );
		$participantsStore->expects( $this->atLeastOnce() )
			->method( 'getEventParticipants' )
			->willReturn( $storedParticipants );

		$centralUserLookup = $this->createMock( CampaignsCentralUserLookup::class );
		$centralUserLookup->method( 'getNamesIncludingDeletedAndSuppressed' )->willReturn( $usernamesMap );

		$userLink = $this->createMock( UserLinker::class );
		$userLink->method( 'getUserPagePath' )->willReturn( [
			'path' => '',
			'title' => '',
			'classes' => '',
		] );
		$handler = $this->newHandler( null, null, $participantsStore, $centralUserLookup, null, $userLink );
		$respData = $this->executeHandlerAndGetBodyData( $handler, new RequestData( self::REQ_DATA ) );
		$this->assertArrayEquals( $expectedResp, $respData );
	}

	public static function provideRunData(): Generator {
		yield 'No participants' => [ [], [], [] ];

		$participants = [];
		$expected = [];
		$usernames = [];
		for ( $i = 1; $i < 4; $i++ ) {
			$participants[] = new Participant( new CentralUser( $i ), '20220315120000', $i, false, [], null, null );
			$usernames[$i] = "Test user $i";
			$expected[] = self::getExpectedParticipantsData( $i, $i, false );
		}

		yield 'Has participants' => [
			$expected,
			$participants,
			$usernames,
		];

		$deletedUserID = 1;
		$deletedParticipant = new Participant(
			new CentralUser( $deletedUserID ),
			'20220315120000',
			1,
			false,
			[],
			null,
			null
		);
		$deletedUserExpected = [
			[
				'participant_id' => 1,
				'user_id' => $deletedUserID,
				'hidden' => true,
				'user_registered_at' => wfTimestamp( TS_MW, '20220315120000' ),
				'user_registered_at_formatted' => '12:00, 15 March 2022',
				'private' => false,
			]
		];
		yield 'Deleted user' => [
			$deletedUserExpected,
			[ $deletedParticipant ],
			[ $deletedUserID => CampaignsCentralUserLookup::USER_HIDDEN ]
		];
	}

	/**
	 * @dataProvider provideRunParticipantQuestions
	 */
	public function testRunParticipantQuestions(
		bool $userCanSeeParticipantNonPIIData = false,
		bool $isPastEvent = false,
		array $answers = [],
		?array $nonPiiAnswers = null,
		?string $participantAnswersAggregatedDate = null,
		?string $aggregatedMessage = null
	) {
		$userLink = $this->createMock( UserLinker::class );
		$userLink->method( 'getUserPagePath' )->willReturn( [
			'path' => '',
			'title' => '',
			'classes' => '',
		] );
		$participants = [];
		$expectedResp = [];
		$usernames = [];
		for ( $i = 1; $i < 4; $i++ ) {
			$participants[] = new Participant(
				new CentralUser( $i ), '20220315120000', $i, false, $answers, null, $participantAnswersAggregatedDate
			);
			$usernames[$i] = "Test user $i";
			$expectedResp[] = self::getExpectedParticipantsData( $i, $i, null, $nonPiiAnswers, $aggregatedMessage );
		}

		$partStore = $this->createMock( ParticipantsStore::class );
		$partStore->expects( $this->atLeastOnce() )->method( 'getEventParticipants' )->willReturn( $participants );
		$centralUserLookup = $this->createMock( CampaignsCentralUserLookup::class );
		$centralUserLookup->method( 'getNamesIncludingDeletedAndSuppressed' )->willReturn( $usernames );

		$permChecker = $this->createMock( PermissionChecker::class );
		$permChecker->method( 'userCanViewNonPIIParticipantsData' )
			->willReturn( $userCanSeeParticipantNonPIIData );

		$eventLookup = $this->createMock( IEventLookup::class );
		$eventRegistration = $this->createMock( ExistingEventRegistration::class );
		$eventRegistration->method( 'getParticipantQuestions' )->willReturn( [ 1, 2, 3, 4, 5 ] );
		$eventRegistration->method( 'isPast' )->willReturn( $isPastEvent );
		$eventLookup->method( 'getEventByID' )->willReturn( $eventRegistration );

		$handler = $this->newHandler(
			$permChecker, $eventLookup, $partStore, $centralUserLookup, null, $userLink
		);
		$respData = $this->executeHandlerAndGetBodyData( $handler, new RequestData( self::REQ_DATA ) );
		$this->assertArrayEquals( $expectedResp, $respData );
	}

	/**
	 * @param array $answers
	 *
	 * @return array
	 */
	private static function generateNoPiiAnswers( array $answers ): array {
		$nonPiiAnswers = [];
		foreach ( $answers as $answer ) {
			$nonPiiAnswers[] = [
				'message' => $answer[ 1 ],
				'questionID' => $answer[ 0 ]
			];
		}
		return $nonPiiAnswers;
	}

	public static function provideRunParticipantQuestions(): Generator {
		yield 'Future event, user cannot view non pii data' => [];

		$noNonPiiAnswers = self::generateNoPiiAnswers( [
			[ 4, 'campaignevents-participant-question-no-response' ],
			[ 5, 'campaignevents-participant-question-no-response' ],
		] );
		yield 'Future event without PII answers' => [
			true, false, [], $noNonPiiAnswers
		];

		$nonPiiAnswers = self::generateNoPiiAnswers( [
			[ 4, 'campaignevents-register-question-confidence-contributing-option-some-not-confident' ],
			[ 5, 'campaignevents-register-question-affiliate-option-affiliate' ],
		] );
		yield 'Future event with all non PII answers' => [
			true, false, [ new Answer( 4, 2, null ), new Answer( 5, 1, null ) ], $nonPiiAnswers
		];

		$onlyOneNonPiiAnswer = self::generateNoPiiAnswers( [
			[ 4, 'campaignevents-participant-question-no-response' ],
			[ 5, 'campaignevents-register-question-affiliate-option-affiliate' ],
		] );
		yield 'Future event with only one non PII answer' => [
			true, false, [ new Answer( 5, 1, null ) ], $onlyOneNonPiiAnswer
		];

		$aggregatedMessage = 'campaignevents-participant-question-have-been-aggregated';
		yield 'Future event with participant aggregated answers' => [
			true, false, [ new Answer( 5, 1, null ) ], null, '20220315120000', $aggregatedMessage
		];

		yield 'Past event' => [ true, true ];
	}

	private static function getExpectedParticipantsData(
		int $participantID,
		int $userID,
		?bool $isValidRecipient = null,
		?array $nonPiiAnswers = null,
		?string $aggregatedAnswersMessage = null
	): array {
		$participantData = [
			'participant_id' => $participantID,
			'user_id' => $userID,
			'user_name' => 'Test user ' . $userID,
			'user_page' => [
				'path' => '',
				'title' => '',
				'classes' => ''
			],
			'user_registered_at' => wfTimestamp( TS_MW, '20220315120000' ),
			'user_registered_at_formatted' => '12:00, 15 March 2022',
			'private' => false,
		];

		if ( $isValidRecipient !== null ) {
			$participantData[ 'user_is_valid_recipient' ] = $isValidRecipient;
		}
		if ( is_array( $nonPiiAnswers ) ) {
			$participantData[ 'non_pii_answers' ] = $nonPiiAnswers;
		} elseif ( $aggregatedAnswersMessage ) {
			$participantData[ 'non_pii_answers' ] = $aggregatedAnswersMessage;
		}
		return $participantData;
	}

	private static function getDataWithParams( array $params ): array {
		$ret = self::REQ_DATA;
		$ret['queryParams'] = $params + $ret['queryParams'];
		return $ret;
	}

	public function doTestRunExpectingError(
		string $expectedMsg,
		int $expectedCode,
		array $reqData,
		?PermissionChecker $permissionChecker = null,
		?IEventLookup $eventLookup = null
	) {
		$handler = $this->newHandler( $permissionChecker, $eventLookup );

		try {
			$this->executeHandler( $handler, new RequestData( $reqData ) );
			$this->fail( 'No exception thrown' );
		} catch ( LocalizedHttpException $e ) {
			$this->assertSame( $expectedMsg, $e->getMessageValue()->getKey() );
			$this->assertSame( $expectedCode, $e->getCode() );
		}
	}

	public function testRun__eventDoesNotExist() {
		$eventNotFoundLookup = $this->createMock( IEventLookup::class );
		$eventNotFoundLookup->expects( $this->once() )
			->method( 'getEventByID' )
			->willThrowException( $this->createMock( EventNotFoundException::class ) );
		$this->doTestRunExpectingError(
			'campaignevents-rest-event-not-found',
			404,
			self::REQ_DATA,
			null,
			$eventNotFoundLookup
		);
	}

	public function testRun__cannotSeePrivateParticipants() {
		$unauthorizedPermChecker = $this->createMock( PermissionChecker::class );
		$unauthorizedPermChecker->expects( $this->atLeastOnce() )
			->method( 'userCanViewPrivateParticipants' )
			->willReturn( false );
		$this->doTestRunExpectingError(
			'campaignevents-rest-list-participants-cannot-see-private',
			403,
			self::getDataWithParams( [ 'include_private' => true ] ),
			$unauthorizedPermChecker
		);
	}

	/** @dataProvider provideRunInvalidData */
	public function testRun__invalidData( string $expectedError, int $expectedCode, array $data ) {
		$this->doTestRunExpectingError( $expectedError, $expectedCode, $data );
	}

	public static function provideRunInvalidData(): Generator {
		yield 'Empty username filter' => [
			'campaignevents-rest-list-participants-empty-filter',
			400,
			self::getDataWithParams( [ 'username_filter' => '' ] )
		];
	}
}
