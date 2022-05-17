<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Rest;

use Generator;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventNotFoundException;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\Participants\RegisterParticipantCommand;
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

	private const DEFAULT_REQ_DATA = [
		'method' => 'PUT',
		'pathParams' => [ 'id' => 42 ],
		'headers' => [ 'Content-Type' => 'application/json' ],
	];

	private function newHandler(
		RegisterParticipantCommand $registerCommand = null,
		IEventLookup $eventLookup = null
	): RegisterForEventHandler {
		if ( !$registerCommand ) {
			$registerCommand = $this->createMock( RegisterParticipantCommand::class );
			$registerCommand->method( 'registerIfAllowed' )->willReturn( StatusValue::newGood( true ) );
		}
		$handler = new RegisterForEventHandler(
			$eventLookup ?? $this->createMock( IEventLookup::class ),
			$registerCommand
		);
		$this->setHandlerCSRFSafe( $handler );
		return $handler;
	}

	/**
	 * @param int $expectedStatusCode
	 * @param string|null $expectedErrorKey
	 * @param RegisterParticipantCommand|null $registerParticipantCommand
	 * @param IEventLookup|null $eventLookup
	 * @dataProvider provideRequestDataWithErrors
	 */
	public function testRun__error(
		int $expectedStatusCode,
		?string $expectedErrorKey,
		RegisterParticipantCommand $registerParticipantCommand = null,
		IEventLookup $eventLookup = null
	) {
		$handler = $this->newHandler( $registerParticipantCommand, $eventLookup );

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
	 * @param RegisterParticipantCommand $registerParticipantCommand
	 * @param bool $expectedModified
	 * @dataProvider provideRequestDataSuccessful
	 */
	public function testRun__successful(
		RegisterParticipantCommand $registerParticipantCommand,
		bool $expectedModified
	) {
		$handler = $this->newHandler( $registerParticipantCommand );
		$reqData = new RequestData( self::DEFAULT_REQ_DATA );
		$respData = $this->executeHandlerAndGetBodyData( $handler, $reqData );
		$this->assertArrayHasKey( 'modified', $respData );
		$this->assertSame( $expectedModified, $respData['modified'] );
	}

	public function provideRequestDataSuccessful(): Generator {
		$modifiedCommand = $this->createMock( RegisterParticipantCommand::class );
		$modifiedCommand->method( 'registerIfAllowed' )->willReturn( StatusValue::newGood( true ) );
		yield 'Modified' => [ $modifiedCommand, true ];

		$notModifiedCommand = $this->createMock( RegisterParticipantCommand::class );
		$notModifiedCommand->method( 'registerIfAllowed' )->willReturn( StatusValue::newGood( false ) );
		yield 'Not modified' => [ $notModifiedCommand, false ];
	}
}
