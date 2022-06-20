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
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWiki\User\UserFactory;
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
	 * @param UserFactory|null $userFactory
	 * @return RemoveParticipantsFromEventHandler
	 */
	private function newHandler(
		IEventLookup $eventLookup = null,
		UnregisterParticipantCommand $unregisterParticipantCommand = null,
		UserFactory $userFactory = null
	): RemoveParticipantsFromEventHandler {
		if ( !$unregisterParticipantCommand ) {
			$unregisterParticipantCommand = $this->createMock( UnregisterParticipantCommand::class );
			$unregisterParticipantCommand->method( 'removeParticipantsIfAllowed' )
				->willReturn( StatusValue::newGood( true ) );
		}
		return new RemoveParticipantsFromEventHandler(
			$eventLookup ?? $this->createMock( IEventLookup::class ),
			$unregisterParticipantCommand,
			$userFactory ?? $this->getUserFactory( true )
		);
	}

	public function testRun__badToken() {
		$handler = $this->newHandler( null, null, $this->getUserFactory( false ) );

		try {
			$this->executeHandler( $handler, new RequestData( $this->getRequestData() ) );
			$this->fail( 'No exception thrown' );
		} catch ( LocalizedHttpException $e ) {
			$this->assertSame( 400, $e->getCode() );
			$this->assertStringContainsString( 'badtoken', $e->getMessageValue()->getKey() );
		}
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
		?UnregisterParticipantCommand $unregisterParticipantCommand = null,
		?IEventLookup $eventLookup = null,
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
	 * @dataProvider provideRequestDataSuccessful
	 */
	public function testRun__successful(
		UnregisterParticipantCommand $unregisterParticipantCommand,
		int $expectedModified
	) {
		$handler = $this->newHandler( null, $unregisterParticipantCommand );
		$reqData = new RequestData( $this->getRequestData() );
		$respData = $this->executeHandlerAndGetBodyData( $handler, $reqData );
		$this->assertArrayHasKey( 'modified', $respData );
		$this->assertSame( $expectedModified, $respData['modified'] );
	}

	public function provideRequestDataSuccessful(): Generator {
		$modifiedCommand = $this->createMock( UnregisterParticipantCommand::class );
		$modifiedCommand->method( 'removeParticipantsIfAllowed' )->willReturn( StatusValue::newGood( 1 ) );
		yield 'Some Modified' => [ $modifiedCommand, 1 ];

		$notModifiedCommand = $this->createMock( UnregisterParticipantCommand::class );
		$notModifiedCommand->method( 'removeParticipantsIfAllowed' )->willReturn( StatusValue::newGood( 0 ) );
		yield 'None modified' => [ $notModifiedCommand, 0 ];
	}
}
