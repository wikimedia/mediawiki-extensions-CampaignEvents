<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Rest;

use Generator;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventNotFoundException;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
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

	/**
	 * @param array|null $userIDs
	 * @return array
	 */
	private function getRequestData( ?array $userIDs = [ 1, 2 ] ): array {
		return [
			'method' => 'DELETE',
			'pathParams' => [ 'id' => 42 ],
			'headers' => [
				'Content-Type' => 'application/json',
			],
			'bodyContents' => json_encode(
				[
					'user_ids' => $userIDs
				]
			),
		];
	}

	/**
	 * @param IEventLookup|null $eventLookup
	 * @param UnregisterParticipantCommand|null $unregisterParticipantCommand
	 * @return RemoveParticipantsFromEventHandler
	 */
	private function newHandler(
		IEventLookup $eventLookup = null,
		UnregisterParticipantCommand $unregisterParticipantCommand = null
	): RemoveParticipantsFromEventHandler {
		if ( !$unregisterParticipantCommand ) {
			$unregisterParticipantCommand = $this->createMock( UnregisterParticipantCommand::class );
			$unregisterParticipantCommand->method( 'removeParticipantsIfAllowed' )
				->willReturn( StatusValue::newGood( true ) );
		}
		return new RemoveParticipantsFromEventHandler(
			$eventLookup ?? $this->createMock( IEventLookup::class ),
			$unregisterParticipantCommand
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
	 * @param string $expectedErrorKey
	 * @param UnregisterParticipantCommand|null $unregisterParticipantCommand
	 * @param IEventLookup|null $eventLookup
	 * @param array $requestData
	 * @dataProvider provideRequestDataWithErrors
	 */
	public function testRun__error(
		int $expectedStatusCode,
		string $expectedErrorKey,
		?UnregisterParticipantCommand $unregisterParticipantCommand,
		?IEventLookup $eventLookup,
		array $requestData
	) {
		$handler = $this->newHandler( $eventLookup, $unregisterParticipantCommand );

		try {
			$this->executeHandler( $handler, new RequestData( $requestData ) );
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
		$requestData = $this->getRequestData();
		$eventDoesNotExistLookup = $this->createMock( IEventLookup::class );
		$eventDoesNotExistLookup->method( 'getEventByID' )
			->willThrowException( $this->createMock( EventNotFoundException::class ) );
		yield 'Event does not exist' => [
			404,
			'campaignevents-rest-event-not-found',
			null,
			$eventDoesNotExistLookup,
			$requestData
		];

		$permError = 'some-permission-error';
		$commandWithPermError = $this->createMock( UnregisterParticipantCommand::class );
		$commandWithPermError->expects( $this->atLeastOnce() )
			->method( 'removeParticipantsIfAllowed' )
			->willReturn( PermissionStatus::newFatal( $permError ) );
		yield 'User cannot remove participants' => [
			403,
			$permError,
			$commandWithPermError,
			null,
			$requestData
		];

		$commandError = 'some-error-from-command';
		$commandWithError = $this->createMock( UnregisterParticipantCommand::class );
		$commandWithError->expects( $this->atLeastOnce() )
			->method( 'removeParticipantsIfAllowed' )
			->willReturn( StatusValue::newFatal( $commandError ) );
		yield 'Command error' => [
			400,
			$commandError,
			$commandWithError,
			null,
			$requestData
		];

		$eventDoesNotExistLookup = $this->createMock( IEventLookup::class );
		yield 'Parameter user_ids must not be empty array' => [
			400,
			"campaignevents-rest-remove-participants-invalid-users-ids",
			null,
			$eventDoesNotExistLookup,
			$this->getRequestData( [] )
		];
	}

	/**
	 * @param UnregisterParticipantCommand $unregisterParticipantCommand
	 * @param int $expectedModified
	 * @param array $reqData
	 * @dataProvider provideRequestDataSuccessful
	 */
	public function testRun__successful(
		UnregisterParticipantCommand $unregisterParticipantCommand,
		int $expectedModified,
		array $reqData
	) {
		$handler = $this->newHandler( null, $unregisterParticipantCommand );
		$reqData = new RequestData( $reqData );
		$respData = $this->executeHandlerAndGetBodyData( $handler, $reqData );

		$this->assertArrayHasKey( 'modified', $respData );
		$this->assertSame( $expectedModified, $respData['modified'] );
	}

	public function provideRequestDataSuccessful(): Generator {
		$modifiedCommand = $this->createMock( UnregisterParticipantCommand::class );
		$modifiedCommand->method( 'removeParticipantsIfAllowed' )->willReturn( StatusValue::newGood( 1 ) );
		yield 'Some Modified' => [ $modifiedCommand, 1, $this->getRequestData() ];

		$invertReqData = $this->getRequestData();
		$invertReqData[ 'bodyContents' ] = json_encode(
			[
				'user_ids' => [ 1 ],
				'invert_users' => true,
			]
		);
		yield 'Some Modified and invert_users true' => [ $modifiedCommand, 1, $invertReqData ];

		$notModifiedCommand = $this->createMock( UnregisterParticipantCommand::class );
		$notModifiedCommand->method( 'removeParticipantsIfAllowed' )->willReturn( StatusValue::newGood( 0 ) );
		yield 'None modified' => [ $notModifiedCommand, 0, $this->getRequestData() ];

		$allModifiedCommand = $this->createMock( UnregisterParticipantCommand::class );
		$allModifiedCommand->method( 'removeParticipantsIfAllowed' )->willReturn( StatusValue::newGood( 2 ) );
		$invertReqData = $this->getRequestData();
		$invertReqData[ 'bodyContents' ] = json_encode(
			[
				'user_ids' => null,
				'invert_users' => false,
			]
		);
		yield 'All mofified' => [ $allModifiedCommand, 2, $invertReqData ];
	}
}
