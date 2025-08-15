<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Rest;

use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionValidator;
use MediaWiki\Extension\CampaignEvents\MWEntity\WikiLookup;
use MediaWiki\Extension\CampaignEvents\Rest\EventContributionsHandler;
use MediaWiki\Rest\HttpException;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWikiUnitTestCase;
use Wikimedia\Message\MessageValue;

/**
 * @group Test
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\EventContributionsHandler
 */
class EventContributionsHandlerTest extends MediaWikiUnitTestCase {
	use HandlerTestTrait;
	use CSRFTestHelperTrait;

	private function getRequestData(): array {
		return [
			'method' => 'POST',
			'pathParams' => [
				'id' => 42,
				'wiki' => 'enwiki',
				'revid' => 12345
			],
			'headers' => [ 'Content-Type' => 'application/json' ],
		];
	}

	private function newHandler(
		$validator = null,
		$wikiLookup = null,
		$eventLookup = null
	): EventContributionsHandler {
		if ( !$validator ) {
			$validator = $this->createMock( EventContributionValidator::class );
			$validator->method( 'validateAndSchedule' )->willReturnCallback( static function () {
				// void method
			} );
		}

		if ( !$wikiLookup ) {
			$wikiLookup = $this->createMock( WikiLookup::class );
			$wikiLookup->method( 'getAllWikis' )->willReturn( [ 'enwiki', 'dewiki' ] );
		}

		if ( !$eventLookup ) {
			$eventLookup = $this->createMock( IEventLookup::class );
			$event = $this->createMock( ExistingEventRegistration::class );
			$event->method( 'getID' )->willReturn( 42 );
			$eventLookup->method( 'getEventByID' )->willReturn( $event );
		}

		return new EventContributionsHandler( $validator, $wikiLookup, $eventLookup );
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
		$validator = null
	) {
		$handler = $this->newHandler( $validator );

		try {
			$this->executeHandler( $handler, new RequestData( $this->getRequestData() ) );
			$this->fail( 'No exception thrown' );
		} catch ( LocalizedHttpException $e ) {
			$this->assertSame( $expectedStatusCode, $e->getCode() );
			if ( $expectedErrorKey ) {
				$this->assertSame( $expectedErrorKey, $e->getMessageValue()->getKey() );
			}
		} catch ( HttpException $e ) {
			$this->assertSame( $expectedStatusCode, $e->getCode() );
		}
	}

	public function testRun__validatorError() {
		$validatorWithError = $this->createMock( EventContributionValidator::class );
		$validatorWithError->expects( $this->once() )
			->method( 'validateAndSchedule' )
			->willThrowException( new LocalizedHttpException(
				MessageValue::new( 'campaignevents-rest-event-not-found' ),
				404
			) );

		$this->doTestRunExpectingError(
			404,
			'campaignevents-rest-event-not-found',
			$validatorWithError
		);
	}

	public function testRun__validatorHttpError() {
		$validatorWithHttpError = $this->createMock( EventContributionValidator::class );
		$validatorWithHttpError->expects( $this->once() )
			->method( 'validateAndSchedule' )
			->willThrowException( new HttpException( 'Feature not enabled', 400 ) );

		$this->doTestRunExpectingError( 400, null, $validatorWithHttpError );
	}

	public function testRun__success() {
		$event = $this->createMock( ExistingEventRegistration::class );
		$event->method( 'getID' )->willReturn( 42 );

		$eventLookup = $this->createMock( IEventLookup::class );
		$eventLookup->method( 'getEventByID' )->with( 42 )->willReturn( $event );

		$validator = $this->createMock( EventContributionValidator::class );
		$validator->expects( $this->once() )
			->method( 'validateAndSchedule' )
			->with( $event, 12345, 'enwiki', $this->anything() )
			->willReturnCallback( static function () {
				// void method
			} );

		$handler = $this->newHandler( $validator, null, $eventLookup );
		$reqData = new RequestData( $this->getRequestData() );
		$response = $this->executeHandler( $handler, $reqData );

		$this->assertSame( 202, $response->getStatusCode() );
		$this->assertSame( '[]', $response->getBody()->getContents() );
	}
}
