<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Rest;

use Generator;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventNotFoundException;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWPageProxy;
use MediaWiki\Extension\CampaignEvents\Participants\UnregisterParticipantCommand;
use MediaWiki\Extension\CampaignEvents\Rest\RemoveParticipantsFromEventHandler;
use MediaWiki\Permissions\PermissionStatus;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;
use MediaWiki\Session\Session;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWikiUnitTestCase;
use StatusValue;

/**
 * @group Test
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\RemoveParticipantsFromEventHandler
 */
class RemoveParticipantsFromEventHandlerTest extends MediaWikiUnitTestCase {
	use HandlerTestTrait;
	use CSRFTestHelperTrait;

	private static function getRequestData( ?array $userIDs = [ 1, 2 ], ?bool $invert = null ): array {
		$bodyContents = [
			'user_ids' => $userIDs
		];
		if ( $invert !== null ) {
			$bodyContents['invert_users'] = $invert;
		}
		return [
			'method' => 'DELETE',
			'pathParams' => [ 'id' => 42 ],
			'headers' => [
				'Content-Type' => 'application/json',
			],
			'bodyContents' => json_encode( $bodyContents ),
		];
	}

	/**
	 * @param IEventLookup|null $eventLookup
	 * @param UnregisterParticipantCommand|null $unregisterParticipantCommand
	 * @param MWPageProxy|null $page
	 * @return RemoveParticipantsFromEventHandler
	 */
	private function newHandler(
		?IEventLookup $eventLookup = null,
		?UnregisterParticipantCommand $unregisterParticipantCommand = null,
		?MWPageProxy $page = null
	): RemoveParticipantsFromEventHandler {
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

		if ( !$unregisterParticipantCommand ) {
			$unregisterParticipantCommand = $this->createMock( UnregisterParticipantCommand::class );
			$unregisterParticipantCommand->method( 'removeParticipantsIfAllowed' )
				->willReturn( StatusValue::newGood( [ 'public' => 1, 'private' => 1 ] ) );
		}
		return new RemoveParticipantsFromEventHandler(
			$eventLookup,
			$unregisterParticipantCommand
		);
	}

	/**
	 * @dataProvider provideBadTokenSessions
	 */
	public function testRun__badToken( Session $session, string $excepMsg, ?string $token ) {
		$this->assertCorrectBadTokenBehaviour(
			$this->newHandler(),
			self::getRequestData(),
			$session,
			$token,
			$excepMsg
		);
	}

	private function doTestRunExpectingError(
		int $expectedStatusCode,
		string $expectedErrorKey,
		?UnregisterParticipantCommand $unregisterParticipantCommand = null,
		?IEventLookup $eventLookup = null,
		?array $requestData = null,
		?MWPageProxy $page = null
	) {
		$handler = $this->newHandler( $eventLookup, $unregisterParticipantCommand, $page );

		try {
			$this->executeHandler( $handler, new RequestData( $requestData ?? self::getRequestData(
			)
			) );
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

	public function testRun__cannotRemoveParticipants() {
		$permError = 'some-permission-error';
		$commandWithPermError = $this->createMock( UnregisterParticipantCommand::class );
		$commandWithPermError->expects( $this->atLeastOnce() )
			->method( 'removeParticipantsIfAllowed' )
			->willReturn( PermissionStatus::newFatal( $permError ) );
		$this->doTestRunExpectingError(
			403,
			$permError,
			$commandWithPermError
		);
	}

	public function testRun__commandError() {
		$commandError = 'some-error-from-command';
		$commandWithError = $this->createMock( UnregisterParticipantCommand::class );
		$commandWithError->expects( $this->atLeastOnce() )
			->method( 'removeParticipantsIfAllowed' )
			->willReturn( StatusValue::newFatal( $commandError ) );
		$this->doTestRunExpectingError(
			400,
			$commandError,
			$commandWithError
		);
	}

	public function testRun__nonLocalEvent() {
		$page = $this->createMock( MWPageProxy::class );
		$page->method( 'getWikiId' )->willReturn( 'anotherwiki' );
		$this->doTestRunExpectingError(
			400,
			'campaignevents-rest-remove-participants-nonlocal-error-message',
			null,
			null,
			null,
			$page
		);
	}

	/** @dataProvider provideInvalidParameters */
	public function testRun__invalidParameters( string $expectedError, array $requestData ) {
		$this->doTestRunExpectingError( 400, $expectedError, null, null, $requestData );
	}

	public static function provideInvalidParameters(): Generator {
		yield 'Parameter user_ids must not be empty array' => [
			"campaignevents-rest-remove-participants-invalid-users-ids",
			self::getRequestData( [] ),
		];
	}

	/**
	 * @dataProvider provideRequestDataSuccessful
	 */
	public function testRun__successful(
		array $rawModified,
		array $reqData
	) {
		$unregisterParticipantCommand = $this->createMock( UnregisterParticipantCommand::class );
		$unregisterParticipantCommand->method( 'removeParticipantsIfAllowed' )
			->willReturn( StatusValue::newGood( $rawModified ) );
		$handler = $this->newHandler( null, $unregisterParticipantCommand );
		$reqData = new RequestData( $reqData );
		$respData = $this->executeHandlerAndGetBodyData( $handler, $reqData );

		$this->assertSame( $rawModified, $respData );
	}

	public static function provideRequestDataSuccessful(): Generator {
		yield 'Some Modified' => [
			[ 'public' => 1, 'private' => 0 ],
			self::getRequestData()
		];

		yield 'Some Modified and invert_users true' => [
			[ 'public' => 1, 'private' => 0 ],
			self::getRequestData( [ 1 ], true )
		];

		yield 'None modified' => [
			[ 'public' => 0, 'private' => 0 ],
			self::getRequestData()
		];

		yield 'All mofified' => [
			[ 'public' => 1, 'private' => 1 ],
			self::getRequestData( null, false )
		];
	}
}
