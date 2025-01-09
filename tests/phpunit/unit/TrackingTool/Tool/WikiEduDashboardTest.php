<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\TrackingTool\Tool;

use Generator;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\TrackingTool\InvalidToolURLException;
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
		MWHttpRequest $request,
		?ParticipantsStore $participantsStore = null
	): WikiEduDashboard {
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
	 * @return MWHttpRequest&MockObject
	 */
	private function getJsonReqMock( ?string $contentType, ?string $content, bool $hasError ): MWHttpRequest {
		$request = $this->createMock( MWHttpRequest::class );
		$request->method( 'getResponseHeader' )
			->with( 'Content-Type' )
			->willReturn( $contentType );
		$request->method( 'getContent' )->willReturn( $content );
		$status = $hasError ? StatusValue::newFatal( 'unused' ) : StatusValue::newGood();
		$request->method( 'execute' )->willReturn( $status );
		return $request;
	}

	/**
	 * @covers ::validateToolAddition
	 * @covers ::makeNewEventRequest
	 * @covers ::makePostRequest
	 * @covers ::parseResponseJSON
	 * @covers ::makeErrorStatus
	 * @dataProvider provideAddTool
	 */
	public function testValidateToolAddition(
		?string $responseContentType,
		?string $responseContent,
		?string $expectedError
	) {
		$request = $this->getJsonReqMock( $responseContentType, $responseContent, $expectedError !== null );
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
	 * @covers ::parseResponseJSON
	 * @covers ::makeErrorStatus
	 * @dataProvider provideAddTool
	 */
	public function testAddToNewEvent(
		?string $responseContentType,
		?string $responseContent,
		?string $expectedError
	) {
		$request = $this->getJsonReqMock( $responseContentType, $responseContent, $expectedError !== null );
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
	 * @covers ::parseResponseJSON
	 * @covers ::makeErrorStatus
	 * @dataProvider provideAddTool
	 */
	public function testAddToExistingEvent(
		?string $responseContentType,
		?string $responseContent,
		?string $expectedError
	) {
		$request = $this->getJsonReqMock( $responseContentType, $responseContent, $expectedError !== null );
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

	private static function provideSuccessfulResponse(): Generator {
		yield 'Success' => [ 'application/json', json_encode( [ 'success' => true ] ), null ];
	}

	private static function provideInvalidResponseCases(): Generator {
		yield 'No Content-Type header' => [ null, null, 'campaignevents-tracking-tool-http-error' ];
		yield 'Response is not JSON' => [ 'definitely-not-json', null, 'campaignevents-tracking-tool-http-error' ];
		yield 'Response is invalid JSON' => [ 'application/json', '{', 'campaignevents-tracking-tool-http-error' ];
		yield 'JSON response is not an object' => [
			'application/json',
			'false',
			'campaignevents-tracking-tool-http-error'
		];
	}

	public static function provideAddTool(): Generator {
		yield from self::provideSuccessfulResponse();

		yield from self::provideInvalidResponseCases();

		yield 'No error code in the response' => [
			'application/json',
			json_encode( [ 'no_error_code_here' => true ] ),
			'campaignevents-tracking-tool-http-error'
		];

		yield 'Invalid secret' => [
			'application/json',
			json_encode( [ 'error_code' => 'invalid_secret' ] ),
			'campaignevents-tracking-tool-wikiedu-config-error'
		];

		yield 'Course not found' => [
			'application/json',
			json_encode( [ 'error_code' => 'course_not_found' ] ),
			'campaignevents-tracking-tool-wikiedu-course-not-found-error'
		];

		yield 'Invalid organizers' => [
			'application/json',
			json_encode( [ 'error_code' => 'not_organizer' ] ),
			'campaignevents-tracking-tool-wikiedu-not-organizer-error'
		];

		yield 'Already in use' => [
			'application/json',
			json_encode( [ 'error_code' => 'already_in_use' ] ),
			'campaignevents-tracking-tool-wikiedu-already-in-use-error'
		];

		yield 'Sync already enabled' => [
			'application/json',
			json_encode( [ 'error_code' => 'sync_already_enabled' ] ),
			'campaignevents-tracking-tool-wikiedu-already-connected-error'
		];
	}

	/**
	 * @covers ::validateToolRemoval
	 * @covers ::makePostRequest
	 * @covers ::parseResponseJSON
	 * @covers ::makeErrorStatus
	 * @dataProvider provideRemoveTool
	 */
	public function testValidateToolRemoval(
		?string $responseContentType,
		?string $responseContent,
		?string $expectedError
	) {
		$request = $this->getJsonReqMock( $responseContentType, $responseContent, $expectedError !== null );
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
	 * @covers ::parseResponseJSON
	 * @covers ::makeErrorStatus
	 * @dataProvider provideRemoveTool
	 */
	public function testRemoveFromEvent(
		?string $responseContentType,
		?string $responseContent,
		?string $expectedError
	) {
		$request = $this->getJsonReqMock( $responseContentType, $responseContent, $expectedError !== null );
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
	 * @covers ::parseResponseJSON
	 * @covers ::makeErrorStatus
	 * @dataProvider provideRemoveTool
	 */
	public function testValidateEventDeletion(
		?string $responseContentType,
		?string $responseContent,
		?string $expectedError
	) {
		$request = $this->getJsonReqMock( $responseContentType, $responseContent, $expectedError !== null );
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
	 * @covers ::parseResponseJSON
	 * @covers ::makeErrorStatus
	 * @dataProvider provideRemoveTool
	 */
	public function testOnEventDeleted(
		?string $responseContentType,
		?string $responseContent,
		?string $expectedError
	) {
		$request = $this->getJsonReqMock( $responseContentType, $responseContent, $expectedError !== null );
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

	public static function provideRemoveTool(): Generator {
		yield from self::provideSuccessfulResponse();

		yield from self::provideInvalidResponseCases();

		yield 'No error code in the response' => [
			'application/json',
			json_encode( [ 'no_error_code_here' => true ] ),
			'campaignevents-tracking-tool-http-error'
		];

		yield 'Invalid secret' => [
			'application/json',
			json_encode( [ 'error_code' => 'invalid_secret' ] ),
			'campaignevents-tracking-tool-wikiedu-config-error'
		];

		yield 'Course not found' => [
			'application/json',
			json_encode( [ 'error_code' => 'course_not_found' ] ),
			null
		];

		yield 'Sync not enabled' => [
			'application/json',
			json_encode( [ 'error_code' => 'sync_not_enabled' ] ),
			null
		];
	}

	/**
	 * @covers ::validateParticipantAdded
	 * @covers ::syncParticipants
	 * @covers ::makePostRequest
	 * @covers ::parseResponseJSON
	 * @covers ::makeErrorStatus
	 * @dataProvider provideParticipantsChange
	 */
	public function testValidateParticipantAdded(
		?string $responseContentType,
		?string $responseContent,
		?string $expectedError
	) {
		$request = $this->getJsonReqMock( $responseContentType, $responseContent, $expectedError !== null );
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
	 * @covers ::addParticipant
	 * @covers ::syncParticipants
	 * @covers ::makePostRequest
	 * @covers ::parseResponseJSON
	 * @covers ::makeErrorStatus
	 * @dataProvider provideParticipantsChange
	 */
	public function testAddParticipant(
		?string $responseContentType,
		?string $responseContent,
		?string $expectedError
	) {
		$request = $this->getJsonReqMock( $responseContentType, $responseContent, $expectedError !== null );
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
	 * @covers ::validateParticipantsRemoved
	 * @covers ::syncParticipants
	 * @covers ::makePostRequest
	 * @covers ::parseResponseJSON
	 * @covers ::makeErrorStatus
	 * @dataProvider provideParticipantsChange
	 */
	public function testValidateParticipantsRemoved(
		?string $responseContentType,
		?string $responseContent,
		?string $expectedError
	) {
		$request = $this->getJsonReqMock( $responseContentType, $responseContent, $expectedError !== null );
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
	 * @covers ::parseResponseJSON
	 * @covers ::makeErrorStatus
	 * @dataProvider provideParticipantsChange
	 */
	public function testRemoveParticipants(
		?string $responseContentType,
		?string $responseContent,
		?string $expectedError
	) {
		$request = $this->getJsonReqMock( $responseContentType, $responseContent, $expectedError !== null );
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

	public static function provideParticipantsChange(): Generator {
		yield from self::provideSuccessfulResponse();

		yield from self::provideInvalidResponseCases();

		yield 'No error code in the response' => [
			'application/json',
			json_encode( [ 'no_error_code_here' => true ] ),
			'campaignevents-tracking-tool-http-error'
		];

		yield 'Invalid secret' => [
			'application/json',
			json_encode( [ 'error_code' => 'invalid_secret' ] ),
			'campaignevents-tracking-tool-wikiedu-config-error'
		];

		yield 'Course not found' => [
			'application/json',
			json_encode( [ 'error_code' => 'course_not_found' ] ),
			'campaignevents-tracking-tool-wikiedu-course-not-found-error'
		];

		yield 'Sync not enabled' => [
			'application/json',
			json_encode( [ 'error_code' => 'sync_not_enabled' ] ),
			'campaignevents-tracking-tool-wikiedu-not-connected-error'
		];
	}

	/**
	 * @dataProvider provideBuildToolEventURL
	 * @covers ::buildToolEventURL
	 */
	public function testBuildToolEventURL( string $baseURL, string $toolEventID, string $expected ) {
		$this->assertSame( $expected, WikiEduDashboard::buildToolEventURL( $baseURL, $toolEventID ) );
	}

	public static function provideBuildToolEventURL(): array {
		$courseSlug = 'SomePerson/SomeCourse';
		return [
			'Staging instance, with slash' => [
				'https://dashboard-testing.wikiedu.org/',
				$courseSlug,
				"https://dashboard-testing.wikiedu.org/courses/$courseSlug"
			],
			'Staging instance, without slash' => [
				'https://dashboard-testing.wikiedu.org',
				$courseSlug,
				"https://dashboard-testing.wikiedu.org/courses/$courseSlug"
			],
			'Production instance, with slash' => [
				'https://outreachdashboard.wmflabs.org/',
				$courseSlug,
				"https://outreachdashboard.wmflabs.org/courses/$courseSlug"
			],
			'Production instance, without slash' => [
				'https://outreachdashboard.wmflabs.org',
				$courseSlug,
				"https://outreachdashboard.wmflabs.org/courses/$courseSlug"
			]
		];
	}

	/**
	 * @dataProvider provideExtractEventIDFromURL
	 * @covers ::extractEventIDFromURL
	 */
	public function testExtractEventIDFromURL( string $baseURL, string $url, ?string $expected ) {
		if ( $expected === null ) {
			$this->expectException( InvalidToolURLException::class );
		}
		$actual = WikiEduDashboard::extractEventIDFromURL( $baseURL, $url );
		if ( $expected !== null ) {
			$this->assertSame( $expected, $actual );
		}
	}

	public static function provideExtractEventIDFromURL(): array {
		$baseUrl = 'https://dashboard-testing.wikiedu.org/';
		return [
			'Malformed value' => [
				$baseUrl,
				':',
				null,
			],
			'Not an URL' => [
				$baseUrl,
				'foo',
				null,
			],
			'Not a known Dashboard instance' => [
				$baseUrl,
				'https://example.org',
				null,
			],
			'Good URL, no course ID, invalid protocol' => [
				$baseUrl,
				'irc://dashboard-testing.wikiedu.org',
				null,
			],
			'Good URL, no course ID, no protocol' => [
				$baseUrl,
				'dashboard-testing.wikiedu.org',
				null,
			],
			'Good URL, HTTPS, site homepage' => [
				$baseUrl,
				'https://dashboard-testing.wikiedu.org',
				null,
			],
			'Good URL, HTTPS, campaign page' => [
				$baseUrl,
				'https://dashboard-testing.wikiedu.org/campaigns/test_campaign/overview',
				null,
			],
			'Good URL, HTTPS, missing course ID' => [
				$baseUrl,
				'https://dashboard-testing.wikiedu.org/courses/',
				null,
			],
			'Good URL, HTTPS, only first part of course ID' => [
				$baseUrl,
				'https://dashboard-testing.wikiedu.org/courses/Organization',
				null,
			],
			'Good URL, HTTPS, missing second part of course ID' => [
				$baseUrl,
				'https://dashboard-testing.wikiedu.org/courses/Organization/',
				null,
			],
			'Good URL, HTTPS, course ID with too many parts' => [
				$baseUrl,
				'https://dashboard-testing.wikiedu.org/courses/Organization/Course/XYZ',
				null,
			],
			'Wrong path letter case' => [
				$baseUrl,
				'https://dashboard-testing.wikiedu.org/Courses/Organization/Course',
				null,
			],
			'Valid, https' => [
				$baseUrl,
				'https://dashboard-testing.wikiedu.org/courses/Organization/Course',
				'Organization/Course',
			],
			'Valid, http' => [
				$baseUrl,
				'http://dashboard-testing.wikiedu.org/courses/Organization/Course',
				'Organization/Course',
			],
			'Valid, protocol-relative' => [
				$baseUrl,
				'//dashboard-testing.wikiedu.org/courses/Organization/Course',
				'Organization/Course',
			],
			'Valid, no protocol' => [
				$baseUrl,
				'dashboard-testing.wikiedu.org/courses/Organization/Course',
				'Organization/Course',
			],
			'Valid, weird scheme and host letter case' => [
				$baseUrl,
				'Https://DASHBOARD-TESTING.wikiedu.ORG/courses/Organization/Course',
				'Organization/Course',
			],
			'Valid, additional query parameters' => [
				$baseUrl,
				'https://dashboard-testing.wikiedu.org/courses/Organization/Course?foo=bar&baz=quux',
				'Organization/Course',
			],
			'Valid, URL needs decoding' => [
				$baseUrl,
				'https://dashboard-testing.wikiedu.org/courses/Org/Per%C3%B2_pi%C3%B1a_crian%C3%A7a_No%C3%ABl_' .
					's%C3%BC%C3%9F_%E7%BB%9D%E4%B8%8D_%D1%8F_%D0%B1%D1%83%D0%B4%D1%83_%D7%9C%D7%95%D7%95%D7%AA%D7%A8_' .
					'%D7%9C%D7%9A',
				'Org/Però_piña_criança_Noël_süß_绝不_я_буду_לוותר_לך',
			],
		];
	}
}
