<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Rest;

use Generator;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventNotFoundException;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWPageProxy;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Participants\RegisterParticipantCommand;
use MediaWiki\Extension\CampaignEvents\Questions\EventQuestionsRegistry;
use MediaWiki\Extension\CampaignEvents\Questions\InvalidAnswerDataException;
use MediaWiki\Extension\CampaignEvents\Rest\RegisterForEventHandler;
use MediaWiki\Permissions\PermissionStatus;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWikiUnitTestCase;
use StatusValue;

/**
 * @group Test
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\RegisterForEventHandler
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\EventIDParamTrait
 */
class RegisterForEventHandlerTest extends MediaWikiUnitTestCase {
	use HandlerTestTrait;
	use CSRFTestHelperTrait;

	private function getRequestData( bool $private = false, bool $showAssocPrompt = true ): array {
		return [
			'method' => 'PUT',
			'pathParams' => [ 'id' => 42 ],
			'bodyContents' => json_encode( [
				'is_private' => $private,
				'show_contribution_association_prompt' => $showAssocPrompt,
			] ),
			'headers' => [ 'Content-Type' => 'application/json' ],
		];
	}

	private function newHandler(
		?RegisterParticipantCommand $registerCommand = null,
		?IEventLookup $eventLookup = null,
		?EventQuestionsRegistry $eventQuestionsRegistry = null,
		?MWPageProxy $page = null
	): RegisterForEventHandler {
		if ( !$registerCommand ) {
			$registerCommand = $this->createMock( RegisterParticipantCommand::class );
			$registerCommand->method( 'registerIfAllowed' )->willReturn( StatusValue::newGood( true ) );
		}

		if ( !$page ) {
			$page = $this->createMock( MWPageProxy::class );
			$page->method( 'getWikiId' )->willReturn( false );
		}

		if ( !$eventLookup ) {
			$eventRegistration = $this->createMock( ExistingEventRegistration::class );
			$eventRegistration->method( 'getPage' )->willReturn( $page );

			$eventLookup = $this->createMock( IEventLookup::class );
			$eventLookup->method( 'getEventByID' )->willReturn( $eventRegistration );
		}

		return new RegisterForEventHandler(
			$eventLookup,
			$registerCommand,
			$eventQuestionsRegistry ?? $this->createMock( EventQuestionsRegistry::class ),
			$this->createMock( ParticipantsStore::class ),
			$this->createMock( CampaignsCentralUserLookup::class )
		);
	}

	/**
	 * @dataProvider provideBadTokenSessions
	 */
	public function testRun__badToken( callable $session, string $excepMsg, ?string $token ) {
		$session = $session( $this );
		$this->assertCorrectBadTokenBehaviour(
			$this->newHandler(),
			$this->getRequestData(),
			$session,
			$token,
			$excepMsg
		);
	}

	private function doTestRunExpectingError(
		int $expectedStatusCode,
		?string $expectedErrorKey,
		?RegisterParticipantCommand $registerParticipantCommand = null,
		?IEventLookup $eventLookup = null,
		?EventQuestionsRegistry $eventQuestionsRegistry = null,
		?MWPageProxy $page = null
	) {
		$handler = $this->newHandler(
			$registerParticipantCommand,
			$eventLookup,
			$eventQuestionsRegistry,
			$page
		);

		try {
			$this->executeHandler( $handler, new RequestData( $this->getRequestData() ) );
			$this->fail( 'No exception thrown' );
		} catch ( LocalizedHttpException $e ) {
			$this->assertSame( $expectedStatusCode, $e->getCode() );
			$this->assertSame( $expectedErrorKey, $e->getMessageValue()->getKey() );
		}
	}

	public function testRun__eventDoesNotExist() {
		$eventDoesNotExistLookup = $this->createMock( IEventLookup::class );
		$eventDoesNotExistLookup->method( 'getEventByID' )
			->willThrowException( $this->createMock( EventNotFoundException::class ) );
		$this->doTestRunExpectingError(
			404,
			'campaignevents-rest-event-not-found',
			null,
			$eventDoesNotExistLookup
		);
	}

	public function testRun__nonLocalEvent() {
		$page = $this->createMock( MWPageProxy::class );
		$page->method( 'getWikiId' )->willReturn( 'anotherwiki' );
		$this->doTestRunExpectingError(
			400,
			'campaignevents-rest-register-for-event-nonlocal-error-message',
			null,
			null,
			null,
			$page
		);
	}

	public function testRun__invalidParticipantAnswers() {
		$invalidQuestRegistry = $this->createMock( EventQuestionsRegistry::class );
		$invalidQuestRegistry->expects( $this->atLeastOnce() )
			->method( 'extractUserAnswersAPI' )
			->willThrowException( $this->createMock( InvalidAnswerDataException::class ) );
		$this->doTestRunExpectingError(
			400,
			'campaignevents-rest-register-invalid-answer',
			null,
			null,
			$invalidQuestRegistry
		);
	}

	public function testRun__permissionError() {
		$permError = 'some-permission-error';
		$commandWithPermError = $this->createMock( RegisterParticipantCommand::class );
		$commandWithPermError->expects( $this->atLeastOnce() )
			->method( 'registerIfAllowed' )
			->willReturn( PermissionStatus::newFatal( $permError ) );
		$this->doTestRunExpectingError(
			403,
			$permError,
			$commandWithPermError
		);
	}

	public function testRun__commandError() {
		$commandError = 'some-error-from-command';
		$commandWithError = $this->createMock( RegisterParticipantCommand::class );
		$commandWithError->expects( $this->atLeastOnce() )
			->method( 'registerIfAllowed' )
			->willReturn( StatusValue::newFatal( $commandError ) );
		$this->doTestRunExpectingError(
			400,
			$commandError,
			$commandWithError
		);
	}

	/**
	 * @dataProvider provideRequestDataSuccessful
	 */
	public function testRun__successful( bool $modified ) {
		$registerParticipantCommand = $this->createMock( RegisterParticipantCommand::class );
		$registerParticipantCommand->method( 'registerIfAllowed' )->willReturn( StatusValue::newGood( $modified ) );
		$handler = $this->newHandler( $registerParticipantCommand );
		$reqData = new RequestData( $this->getRequestData() );
		$respData = $this->executeHandlerAndGetBodyData( $handler, $reqData );
		$this->assertArrayHasKey( 'modified', $respData );
		$this->assertSame( $modified, $respData['modified'] );
	}

	public static function provideRequestDataSuccessful(): Generator {
		yield 'Modified' => [ true ];
		yield 'Not modified' => [ false ];
	}

	/**
	 * @dataProvider provideRunPrivate
	 */
	public function testRun__private( bool $private ) {
		$registerParticipantCommand = $this->createMock( RegisterParticipantCommand::class );
		$expectedCommandArg = $private ?
			RegisterParticipantCommand::REGISTRATION_PRIVATE :
			RegisterParticipantCommand::REGISTRATION_PUBLIC;

		$registerParticipantCommand->expects( $this->once() )->method( 'registerIfAllowed' )
			->with( $this->anything(), $this->anything(), $expectedCommandArg )
			->willReturn( StatusValue::newGood( true ) );

		$handler = $this->newHandler( $registerParticipantCommand );
		$reqData = new RequestData( $this->getRequestData( $private ) );
		$this->executeHandlerAndGetBodyData( $handler, $reqData );
	}

	public static function provideRunPrivate(): array {
		return [
			'private' => [ true ],
			'public' => [ false ],
		];
	}

	/**
	 * @dataProvider provideRunShowContributionAssociationPrompt
	 */
	public function testRun__showContributionAssociationPrompt( bool $showPrompt ) {
		$registerParticipantCommand = $this->createMock( RegisterParticipantCommand::class );
		$expectedCommandArg = $showPrompt ?
			RegisterParticipantCommand::SHOW_CONTRIBUTION_ASSOCIATION_PROMPT :
			RegisterParticipantCommand::HIDE_CONTRIBUTION_ASSOCIATION_PROMPT;

		$registerParticipantCommand->expects( $this->once() )->method( 'registerIfAllowed' )
			->with( $this->anything(), $this->anything(), $this->anything(), $this->anything(), $expectedCommandArg )
			->willReturn( StatusValue::newGood( true ) );

		$handler = $this->newHandler( $registerParticipantCommand );
		$reqData = new RequestData( $this->getRequestData( showAssocPrompt: $showPrompt ) );
		$this->executeHandlerAndGetBodyData( $handler, $reqData );
	}

	public static function provideRunShowContributionAssociationPrompt(): array {
		return [
			'Show prompt' => [ true ],
			'Hide prompt' => [ false ],
		];
	}
}
