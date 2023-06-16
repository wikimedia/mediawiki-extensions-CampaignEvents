<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\TrackingTool\Tool;

use Generator;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
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
	private function getTool(
		MWHttpRequest $request = null,
		ParticipantsStore $participantsStore = null
	): WikiEduDashboard {
		if ( !$request ) {
			$request = $this->getJsonReqMock( [ 'success' => true ], false );
			$request->method( 'execute' )->willReturn( StatusValue::newGood() );
		}
		$httpRequestFactory = $this->createMock( HttpRequestFactory::class );
		$httpRequestFactory->method( 'create' )->willReturn( $request );

		return new WikiEduDashboard(
			$httpRequestFactory,
			$this->createMock( CampaignsCentralUserLookup::class ),
			$participantsStore ?? $this->createMock( ParticipantsStore::class ),
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
	 * @covers ::makeNewEventRequest
	 * @covers ::makePostRequest
	 * @covers ::makeErrorStatus
	 * @dataProvider provideAddTool
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

	/**
	 * @covers ::addToNewEvent
	 * @covers ::makeNewEventRequest
	 * @covers ::makePostRequest
	 * @covers ::makeErrorStatus
	 * @dataProvider provideAddTool
	 */
	public function testAddToNewEvent(
		?MWHttpRequest $request,
		?string $expectedError
	) {
		$actual = $this->getTool( $request )->addToNewEvent(
			42,
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

	/**
	 * @covers ::addToExistingEvent
	 * @covers ::makeNewEventRequest
	 * @covers ::makePostRequest
	 * @covers ::makeErrorStatus
	 * @dataProvider provideAddTool
	 */
	public function testAddToExistingEvent(
		?MWHttpRequest $request,
		?string $expectedError
	) {
		$actual = $this->getTool( $request )->addToExistingEvent(
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

	public function provideAddTool(): Generator {
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
	 * @covers ::validateToolRemoval
	 * @covers ::makePostRequest
	 * @covers ::makeErrorStatus
	 * @dataProvider provideRemoveTool
	 */
	public function testValidateToolRemoval(
		?MWHttpRequest $request,
		?string $expectedError
	) {
		$actual = $this->getTool( $request )->validateToolRemoval(
			$this->createMock( ExistingEventRegistration::class ),
			'something'
		);
		if ( $expectedError === null ) {
			$this->assertStatusGood( $actual );
		} else {
			$this->assertStatusError( $expectedError, $actual );
		}
	}

	/**
	 * @covers ::removeFromEvent
	 * @covers ::makePostRequest
	 * @covers ::makeErrorStatus
	 * @dataProvider provideRemoveTool
	 */
	public function testRemoveFromEvent(
		?MWHttpRequest $request,
		?string $expectedError
	) {
		$actual = $this->getTool( $request )->removeFromEvent(
			$this->createMock( ExistingEventRegistration::class ),
			'something'
		);
		if ( $expectedError === null ) {
			$this->assertStatusGood( $actual );
		} else {
			$this->assertStatusError( $expectedError, $actual );
		}
	}

	/**
	 * @covers ::validateEventDeletion
	 * @covers ::makePostRequest
	 * @covers ::makeErrorStatus
	 * @dataProvider provideRemoveTool
	 */
	public function testValidateEventDeletion(
		?MWHttpRequest $request,
		?string $expectedError
	) {
		$actual = $this->getTool( $request )->validateEventDeletion(
			$this->createMock( ExistingEventRegistration::class ),
			'something'
		);
		if ( $expectedError === null ) {
			$this->assertStatusGood( $actual );
		} else {
			$this->assertStatusError( $expectedError, $actual );
		}
	}

	/**
	 * @covers ::onEventDeleted
	 * @covers ::makePostRequest
	 * @covers ::makeErrorStatus
	 * @dataProvider provideRemoveTool
	 */
	public function testOnEventDeleted(
		?MWHttpRequest $request,
		?string $expectedError
	) {
		$actual = $this->getTool( $request )->onEventDeleted(
			$this->createMock( ExistingEventRegistration::class ),
			'something'
		);
		if ( $expectedError === null ) {
			$this->assertStatusGood( $actual );
		} else {
			$this->assertStatusError( $expectedError, $actual );
		}
	}

	public function provideRemoveTool(): Generator {
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

		yield 'Sync not enabled' => [
			$this->getJsonReqMock( [ 'error_code' => 'sync_not_enabled' ] ),
			'campaignevents-tracking-tool-wikiedu-not-connected-error'
		];
	}

	/**
	 * @covers ::validateParticipantAdded
	 * @covers ::syncParticipants
	 * @covers ::makePostRequest
	 * @covers ::makeErrorStatus
	 * @dataProvider provideParticipantsChange
	 */
	public function testValidateParticipantAdded(
		?MWHttpRequest $request,
		?string $expectedError
	) {
		$actual = $this->getTool( $request )->validateParticipantAdded(
			$this->createMock( ExistingEventRegistration::class ),
			'something',
			$this->createMock( CentralUser::class ),
			false
		);
		if ( $expectedError === null ) {
			$this->assertStatusGood( $actual );
		} else {
			$this->assertStatusError( $expectedError, $actual );
		}
	}

	/**
	 * @covers ::validateParticipantAdded
	 */
	public function testValidateParticipantAdded__private() {
		// Verify that we don't do anything if the participant is private.
		$noopParticipantStore = $this->createNoOpMock( ParticipantsStore::class );
		$actual = $this->getTool( null, $noopParticipantStore )->validateParticipantAdded(
			$this->createMock( ExistingEventRegistration::class ),
			'something',
			$this->createMock( CentralUser::class ),
			true
		);
		$this->assertStatusGood( $actual );
	}

	/**
	 * @covers ::addParticipant
	 * @covers ::syncParticipants
	 * @covers ::makePostRequest
	 * @covers ::makeErrorStatus
	 * @dataProvider provideParticipantsChange
	 */
	public function testAddParticipant(
		?MWHttpRequest $request,
		?string $expectedError
	) {
		$actual = $this->getTool( $request )->addParticipant(
			$this->createMock( ExistingEventRegistration::class ),
			'something',
			$this->createMock( CentralUser::class ),
			false
		);
		if ( $expectedError === null ) {
			$this->assertStatusGood( $actual );
		} else {
			$this->assertStatusError( $expectedError, $actual );
		}
	}

	/**
	 * @covers ::addParticipant
	 */
	public function testAddParticipant__private() {
		// Verify that we don't do anything if the participant is private.
		$noopParticipantStore = $this->createNoOpMock( ParticipantsStore::class );
		$actual = $this->getTool( null, $noopParticipantStore )->addParticipant(
			$this->createMock( ExistingEventRegistration::class ),
			'something',
			$this->createMock( CentralUser::class ),
			true
		);
		$this->assertStatusGood( $actual );
	}

	/**
	 * @covers ::validateParticipantsRemoved
	 * @covers ::syncParticipants
	 * @covers ::makePostRequest
	 * @covers ::makeErrorStatus
	 * @dataProvider provideParticipantsChange
	 */
	public function testValidateParticipantsRemoved(
		?MWHttpRequest $request,
		?string $expectedError
	) {
		$actual = $this->getTool( $request )->validateParticipantsRemoved(
			$this->createMock( ExistingEventRegistration::class ),
			'something',
			null,
			false
		);
		if ( $expectedError === null ) {
			$this->assertStatusGood( $actual );
		} else {
			$this->assertStatusError( $expectedError, $actual );
		}
	}

	/**
	 * @covers ::removeParticipants
	 * @covers ::syncParticipants
	 * @covers ::makePostRequest
	 * @covers ::makeErrorStatus
	 * @dataProvider provideParticipantsChange
	 */
	public function testRemoveParticipants(
		?MWHttpRequest $request,
		?string $expectedError
	) {
		$actual = $this->getTool( $request )->removeParticipants(
			$this->createMock( ExistingEventRegistration::class ),
			'something',
			null,
			false
		);
		if ( $expectedError === null ) {
			$this->assertStatusGood( $actual );
		} else {
			$this->assertStatusError( $expectedError, $actual );
		}
	}

	public function provideParticipantsChange(): Generator {
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

		yield 'Sync not enabled' => [
			$this->getJsonReqMock( [ 'error_code' => 'sync_not_enabled' ] ),
			'campaignevents-tracking-tool-wikiedu-not-connected-error'
		];
	}
}
