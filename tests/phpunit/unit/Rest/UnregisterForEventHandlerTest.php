<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Rest;

use Generator;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventNotFoundException;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\Participants\UnregisterParticipantCommand;
use MediaWiki\Extension\CampaignEvents\Rest\UnregisterForEventHandler;
use MediaWiki\Permissions\PermissionStatus;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWiki\User\UserFactory;
use MediaWikiUnitTestCase;
use StatusValue;

/**
 * @group Test
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\UnregisterForEventHandler
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\EventIDParamTrait
 */
class UnregisterForEventHandlerTest extends MediaWikiUnitTestCase {
	use HandlerTestTrait;
	use CSRFTestHelperTrait;

	private const DEFAULT_REQ_DATA = [
		'method' => 'DELETE',
		'pathParams' => [ 'id' => 42 ],
		'headers' => [ 'Content-Type' => 'application/json' ],
	];

	private function newHandler(
		UnregisterParticipantCommand $unregisterCommand = null,
		IEventLookup $eventLookup = null,
		UserFactory $userFactory = null
	): UnregisterForEventHandler {
		if ( !$unregisterCommand ) {
			$unregisterCommand = $this->createMock( UnregisterParticipantCommand::class );
			$unregisterCommand->method( 'unregisterIfAllowed' )->willReturn( StatusValue::newGood( true ) );
		}
		return new UnregisterForEventHandler(
			$eventLookup ?? $this->createMock( IEventLookup::class ),
			$unregisterCommand,
			$userFactory ?? $this->getUserFactory( true )
		);
	}

	public function testRun__badToken() {
		$handler = $this->newHandler( null, null, $this->getUserFactory( false ) );

		try {
			$this->executeHandler( $handler, new RequestData( self::DEFAULT_REQ_DATA ) );
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
	 * @dataProvider provideRequestDataWithErrors
	 */
	public function testRun__error(
		int $expectedStatusCode,
		string $expectedErrorKey,
		UnregisterParticipantCommand $unregisterParticipantCommand = null,
		IEventLookup $eventLookup = null
	) {
		$handler = $this->newHandler( $unregisterParticipantCommand, $eventLookup );

		try {
			$this->executeHandler( $handler, new RequestData( self::DEFAULT_REQ_DATA ) );
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

		$permError = 'some-permission-error';
		$commandWithPermError = $this->createMock( UnregisterParticipantCommand::class );
		$commandWithPermError->expects( $this->atLeastOnce() )
			->method( 'unregisterIfAllowed' )
			->willReturn( PermissionStatus::newFatal( $permError ) );
		yield 'User cannot unregister' => [
			403,
			$permError,
			$commandWithPermError
		];

		$commandError = 'some-error-from-command';
		$commandWithError = $this->createMock( UnregisterParticipantCommand::class );
		$commandWithError->expects( $this->atLeastOnce() )
			->method( 'unregisterIfAllowed' )
			->willReturn( StatusValue::newFatal( $commandError ) );
		yield 'Command error' => [
			400,
			$commandError,
			$commandWithError
		];
	}

	/**
	 * @param UnregisterParticipantCommand $unregisterParticipantCommand
	 * @param bool $expectedModified
	 * @dataProvider provideRequestDataSuccessful
	 */
	public function testRun__successful(
		UnregisterParticipantCommand $unregisterParticipantCommand,
		bool $expectedModified
	) {
		$handler = $this->newHandler( $unregisterParticipantCommand );
		$reqData = new RequestData( self::DEFAULT_REQ_DATA );
		$respData = $this->executeHandlerAndGetBodyData( $handler, $reqData );
		$this->assertArrayHasKey( 'modified', $respData );
		$this->assertSame( $expectedModified, $respData['modified'] );
	}

	public function provideRequestDataSuccessful(): Generator {
		$modifiedCommand = $this->createMock( UnregisterParticipantCommand::class );
		$modifiedCommand->method( 'unregisterIfAllowed' )->willReturn( StatusValue::newGood( true ) );
		yield 'Modified' => [ $modifiedCommand, true ];

		$notModifiedCommand = $this->createMock( UnregisterParticipantCommand::class );
		$notModifiedCommand->method( 'unregisterIfAllowed' )->willReturn( StatusValue::newGood( false ) );
		yield 'Not modified' => [ $notModifiedCommand, false ];
	}
}
