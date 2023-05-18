<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\TrackingTool\Tool;

use Generator;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\TrackingTool\Tool\WikiEduDashboard;
use MediaWiki\Http\HttpRequestFactory;
use MediaWikiUnitTestCase;
use MWHttpRequest;
use PHPUnit\Framework\MockObject\MockObject;
use StatusValue;

/**
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\TrackingTool\Tool\WikiEduDashboard
 * @covers ::__construct
 */
class WikiEduDashboardTest extends MediaWikiUnitTestCase {
	private function getTool( MWHttpRequest $request = null ): WikiEduDashboard {
		if ( !$request ) {
			$request = $this->getJsonReqMock( [ 'success' => true ], false );
			$request->method( 'execute' )->willReturn( StatusValue::newGood() );
		}
		$httpRequestFactory = $this->createMock( HttpRequestFactory::class );
		$httpRequestFactory->method( 'create' )->willReturn( $request );

		return new WikiEduDashboard(
			$httpRequestFactory,
			$this->createMock( CampaignsCentralUserLookup::class ),
			1,
			'some-url',
			[ 'secret' => '', 'proxy' => null ]
		);
	}

	/**
	 * @param array $respBody
	 * @param bool $error
	 * @return MWHttpRequest&MockObject
	 */
	private function getJsonReqMock( array $respBody = [], bool $error = true ): MWHttpRequest {
		$request = $this->createMock( MWHttpRequest::class );
		$request->method( 'getResponseHeader' )
			->with( 'Content-Type' )
			->willReturn( 'application/json' );
		$request->method( 'getContent' )->willReturn( json_encode( $respBody ) );
		$status = $error ? StatusValue::newFatal( 'unused' ) : StatusValue::newGood();
		$request->method( 'execute' )->willReturn( $status );
		return $request;
	}

	/**
	 * @covers ::validateToolAddition
	 * @dataProvider provideValidateToolAddition
	 */
	public function testValidateToolAddition(
		?MWHttpRequest $request,
		?string $expectedError
	) {
		$actual = $this->getTool( $request )->validateToolAddition(
			$this->createMock( ExistingEventRegistration::class ),
			[],
			'something'
		);
		if ( $expectedError === null ) {
			$this->assertStatusGood( $actual );
		} else {
			$this->assertStatusError( $expectedError, $actual );
		}
	}

	public function provideValidateToolAddition(): Generator {
		yield 'Success' => [ null, null ];

		$notJsonResponseReq = $this->createMock( MWHttpRequest::class );
		$notJsonResponseReq->method( 'getResponseHeader' )
			->with( 'Content-Type' )
			->willReturn( 'definitely-not-json' );
		$notJsonResponseReq->method( 'execute' )->willReturn( StatusValue::newGood() );
		yield 'Response is not JSON' => [ $notJsonResponseReq, 'campaignevents-tracking-tool-http-error' ];

		yield 'No error code in the response' => [
			$this->getJsonReqMock( [ 'no_error_code_here' => true ] ),
			'campaignevents-tracking-tool-http-error'
		];

		yield 'Invalid secret' => [
			$this->getJsonReqMock( [ 'error_code' => 'invalid_secret' ] ),
			'campaignevents-tracking-tool-wikiedu-config-error'
		];

		yield 'Course not found' => [
			$this->getJsonReqMock( [ 'error_code' => 'course_not_found' ] ),
			'campaignevents-tracking-tool-wikiedu-course-not-found-error'
		];

		yield 'Invalid organizers' => [
			$this->getJsonReqMock( [ 'error_code' => 'not_organizer' ] ),
			'campaignevents-tracking-tool-wikiedu-not-organizer-error'
		];

		yield 'Already in use' => [
			$this->getJsonReqMock( [ 'error_code' => 'already_in_use' ] ),
			'campaignevents-tracking-tool-wikiedu-already-in-use-error'
		];

		yield 'Sync already enabled' => [
			$this->getJsonReqMock( [ 'error_code' => 'sync_already_enabled' ] ),
			'campaignevents-tracking-tool-wikiedu-already-connected-error'
		];
	}

	/**
	 * @covers ::addToEvent
	 */
	public function testAddToEvent() {
		$actual = $this->getTool()->addToEvent(
			$this->createMock( ExistingEventRegistration::class ),
			[],
			'something'
		);
		$this->assertEquals( StatusValue::newGood(), $actual );
	}

	/**
	 * @covers ::validateToolRemoval
	 */
	public function testValidateToolRemoval() {
		$actual = $this->getTool()->validateToolRemoval(
			$this->createMock( ExistingEventRegistration::class ),
			'something'
		);
		$this->assertEquals( StatusValue::newGood(), $actual );
	}

	/**
	 * @covers ::removeFromEvent
	 */
	public function testRemoveFromEvent() {
		$actual = $this->getTool()->removeFromEvent(
			$this->createMock( ExistingEventRegistration::class ),
			'something'
		);
		$this->assertEquals( StatusValue::newGood(), $actual );
	}

	/**
	 * @covers ::validateEventDeletion
	 */
	public function testValidateEventDeletion() {
		$actual = $this->getTool()->validateEventDeletion(
			$this->createMock( ExistingEventRegistration::class ),
			'something'
		);
		$this->assertEquals( StatusValue::newGood(), $actual );
	}

	/**
	 * @covers ::onEventDeleted
	 */
	public function testOnEventDeleted() {
		$actual = $this->getTool()->onEventDeleted(
			$this->createMock( ExistingEventRegistration::class ),
			'something'
		);
		$this->assertEquals( StatusValue::newGood(), $actual );
	}

	/**
	 * @covers ::validateParticipantAdded
	 */
	public function testValidateParticipantAdded() {
		$actual = $this->getTool()->validateParticipantAdded(
			$this->createMock( ExistingEventRegistration::class ),
			'something',
			$this->createMock( CentralUser::class ),
			false
		);
		$this->assertEquals( StatusValue::newGood(), $actual );
	}

	/**
	 * @covers ::addParticipant
	 */
	public function testAddParticipant() {
		$actual = $this->getTool()->addParticipant(
			$this->createMock( ExistingEventRegistration::class ),
			'something',
			$this->createMock( CentralUser::class ),
			false
		);
		$this->assertEquals( StatusValue::newGood(), $actual );
	}

	/**
	 * @covers ::validateParticipantsRemoved
	 */
	public function testValidateParticipantsRemoved() {
		$actual = $this->getTool()->validateParticipantsRemoved(
			$this->createMock( ExistingEventRegistration::class ),
			'something',
			null,
			false
		);
		$this->assertEquals( StatusValue::newGood(), $actual );
	}

	/**
	 * @covers ::removeParticipants
	 */
	public function testRemoveParticipants() {
		$actual = $this->getTool()->removeParticipants(
			$this->createMock( ExistingEventRegistration::class ),
			'something',
			null,
			false
		);
		$this->assertEquals( StatusValue::newGood(), $actual );
	}
}
