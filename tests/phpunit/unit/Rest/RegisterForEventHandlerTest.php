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
use MediaWiki\Session\Session;
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

	private function getRequestData( bool $private = false ): array {
		return [
			'method' => 'PUT',
			'pathParams' => [ 'id' => 42 ],
			'bodyContents' => json_encode( [ 'is_private' => $private ] ),
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
	public function testRun__badToken( Session $session, string $excepMsg, ?string $token ) {
		$this->assertCorrectBadTokenBehaviour(
			$this->newHandler(),
			$this->getRequestData(),
			$session,
			$token,
			$excepMsg
		);
	}

	/**
	 * @param int $expectedStatusCode
	 * @param string|null $expectedErrorKey
	 * @param RegisterParticipantCommand|null $registerParticipantCommand
	 * @param IEventLookup|null $eventLookup
	 * @param EventQuestionsRegistry|null $eventQuestionsRegistry
	 * @dataProvider provideRequestDataWithErrors
	 */
	public function testRun__error(
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

	/**
	 * @return Generator
	 */
	public function provideRequestDataWithErrors(): Generator {
		$eventDoesNotExistLookup = $this->createMock( IEventLookup::class );
		$eventDoesNotExistLookup->method( 'getEventByID' )
			->willThrowException( $this->createMock( EventNotFoundException::class ) );
		yield 'Event does not exist' => [
			404,
			'campaignevents-rest-event-not-found',
			null,
			$eventDoesNotExistLookup
		];

		$page = $this->createMock( MWPageProxy::class );
		$page->method( 'getWikiId' )->willReturn( 'anotherwiki' );
		$nonLocalErrorMessage = 'campaignevents-rest-register-for-event-nonlocal-error-message';
		yield 'Non local event' => [
			400,
			$nonLocalErrorMessage,
			null,
			null,
			null,
			$page
		];

		$invalidAnsError = 'campaignevents-rest-register-invalid-answer';
		$invalidQuestRegistry = $this->createMock( EventQuestionsRegistry::class );
		$invalidQuestRegistry->expects( $this->atLeastOnce() )
			->method( 'extractUserAnswersAPI' )
			->willThrowException( $this->createMock( InvalidAnswerDataException::class ) );
		yield 'Invalid participant answers' => [
			400,
			$invalidAnsError,
			null,
			null,
			$invalidQuestRegistry
		];

		$permError = 'some-permission-error';
		$commandWithPermError = $this->createMock( RegisterParticipantCommand::class );
		$commandWithPermError->expects( $this->atLeastOnce() )
			->method( 'registerIfAllowed' )
			->willReturn( PermissionStatus::newFatal( $permError ) );
		yield 'User cannot register' => [
			403,
			$permError,
			$commandWithPermError
		];

		$commandError = 'some-error-from-command';
		$commandWithError = $this->createMock( RegisterParticipantCommand::class );
		$commandWithError->expects( $this->atLeastOnce() )
			->method( 'registerIfAllowed' )
			->willReturn( StatusValue::newFatal( $commandError ) );
		yield 'Command error' => [
			400,
			$commandError,
			$commandWithError
		];
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
}
