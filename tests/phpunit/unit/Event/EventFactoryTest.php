<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Event;

use Generator;
use InvalidArgumentException;
use MediaWiki\Extension\CampaignEvents\Event\EventFactory;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\InvalidEventDataException;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFactory;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFormatter;
use MediaWiki\Extension\CampaignEvents\MWEntity\InvalidTitleStringException;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageNotFoundException;
use MediaWiki\Extension\CampaignEvents\MWEntity\UnexpectedInterwikiException;
use MediaWikiUnitTestCase;
use MWTimestamp;

/**
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Event\EventFactory
 * @covers ::__construct
 */
class EventFactoryTest extends MediaWikiUnitTestCase {

	// Feb 27, 2022
	private const TEST_TIME = 1646000000;
	private const VALID_DEFAULT_DATA = [
		'id' => 42,
		'page' => 'Some event page title',
		'chat' => 'https://chaturl.example.org',
		'trackingname' => 'Tracking tool',
		'trackingurl' => 'https://trackingtool.example.org',
		'status' => EventRegistration::STATUS_OPEN,
		'start' => '20220308120000',
		'end' => '20220308150000',
		'type' => EventRegistration::TYPE_GENERIC,
		'meetingtype' => EventRegistration::MEETING_TYPE_ONLINE_AND_PHYSICAL,
		'meetingurl' => 'https://meetingurl.example.org',
		'country' => 'Country',
		'address' => 'Address',
		'creation' => '20220308100000',
		'lastedit' => '20220308100000',
		'deletion' => null,
		'validationFlags' => EventFactory::VALIDATE_ALL
	];

	/**
	 * @inheritDoc
	 */
	protected function setUp(): void {
		parent::setUp();
		MWTimestamp::setFakeTime( self::TEST_TIME );
	}

	private function getEventFactory(
		CampaignsPageFactory $campaignsPageFactory = null
	): EventFactory {
		return new EventFactory(
			$campaignsPageFactory ?? $this->createMock( CampaignsPageFactory::class ),
			$this->createMock( CampaignsPageFormatter::class )
		);
	}

	/**
	 * @param string|null $expectedErrorKey
	 * @param array $factoryArgs
	 * @param CampaignsPageFactory|null $campaignsPageFactory
	 * @covers ::newEvent
	 * @covers ::validatePage
	 * @covers ::validateDates
	 * @covers ::isValidURL
	 * @covers ::validateTrackingTool
	 * @covers ::validateLocation
	 * @dataProvider provideEventData
	 */
	public function testNewEvent(
		?string $expectedErrorKey,
		array $factoryArgs,
		CampaignsPageFactory $campaignsPageFactory = null
	) {
		$factory = $this->getEventFactory( $campaignsPageFactory );
		$event = null;
		$ex = null;

		try {
			$event = $factory->newEvent( ...$factoryArgs );
		} catch ( InvalidEventDataException $ex ) {
		}

		if ( !$expectedErrorKey ) {
			$this->assertInstanceOf( EventRegistration::class, $event, 'Should create or update an event' );
		} else {
			$this->assertNotNull( $ex, 'Should throw an exception' );
			$statusErrorKeys = array_column( $ex->getStatus()->getErrors(), 'message' );
			$this->assertCount( 1, $statusErrorKeys, 'Should only have 1 error' );
			$this->assertSame( $expectedErrorKey, $statusErrorKeys[0], 'Error message should match' );
		}
	}

	public function provideEventData(): Generator {
		yield 'Successful' => [ null, $this->getTestDataWithDefault() ];
		yield 'Negative ID' => [ 'campaignevents-error-invalid-id', $this->getTestDataWithDefault( [ 'id' => -2 ] ) ];

		yield 'Empty title string' =>
			[ 'campaignevents-error-empty-title', $this->getTestDataWithDefault( [ 'page' => '' ] ) ];

		$invalidTitleStr = 'a|b';
		$invalidTitlePageFactory = $this->createMock( CampaignsPageFactory::class );
		$invalidTitlePageFactory->expects( $this->atLeastOnce() )
			->method( 'newLocalExistingPageFromString' )
			->with( $invalidTitleStr )
			->willThrowException( $this->createMock( InvalidTitleStringException::class ) );
		yield 'Invalid title string' => [
			'campaignevents-error-invalid-title',
			$this->getTestDataWithDefault( [ 'page' => $invalidTitleStr ] ),
			$invalidTitlePageFactory
		];

		$interwikiStr = 'nonexistinginterwiki:Interwiki page';
		$interwikiPageFactory = $this->createMock( CampaignsPageFactory::class );
		$interwikiPageFactory->expects( $this->atLeastOnce() )
			->method( 'newLocalExistingPageFromString' )
			->with( $interwikiStr )
			->willThrowException( $this->createMock( UnexpectedInterwikiException::class ) );
		yield 'Invalid title interwiki' => [
			'campaignevents-error-invalid-title-interwiki',
			$this->getTestDataWithDefault( [ 'page' => $interwikiStr ] ),
			$interwikiPageFactory
		];

		$nonExistingPageStr = 'This page does not exist';
		$nonExistingCampaignsPageFactory = $this->createMock( CampaignsPageFactory::class );
		$nonExistingCampaignsPageFactory->expects( $this->atLeastOnce() )
			->method( 'newLocalExistingPageFromString' )
			->with( $nonExistingPageStr )
			->willThrowException( $this->createMock( PageNotFoundException::class ) );
		yield 'Non-existing page' => [
			'campaignevents-error-page-not-found',
			$this->getTestDataWithDefault( [ 'page' => $nonExistingPageStr ] ),
			$nonExistingCampaignsPageFactory
		];

		yield 'Invalid chat URL' => [
			'campaignevents-error-invalid-chat-url',
			$this->getTestDataWithDefault( [ 'chat' => 'not-an-url' ] )
		];
		yield 'Tracking tool URL without name' => [
			'campaignevents-error-tracking-tool-url-without-name',
			$this->getTestDataWithDefault( [ 'trackingname' => null ] )
		];
		yield 'Tracking tool name without URL' => [
			'campaignevents-error-tracking-tool-name-without-url',
			$this->getTestDataWithDefault( [ 'trackingurl' => null ] )
		];
		yield 'Invalid tracking tool URL' => [
			'campaignevents-error-invalid-trackingtool-url',
			$this->getTestDataWithDefault( [ 'trackingurl' => 'not-an-url' ] )
		];
		yield 'Invalid status' => [
			'campaignevents-error-invalid-status',
			$this->getTestDataWithDefault( [ 'status' => 'Some invalid status' ] )
		];
		yield 'Empty start timestamp' => [
			'campaignevents-error-empty-start',
			$this->getTestDataWithDefault( [ 'start' => '' ] )
		];
		yield 'Invalid start timestamp' => [
			'campaignevents-error-invalid-start',
			$this->getTestDataWithDefault( [ 'start' => 'Not a timestamp' ] )
		];
		yield 'Start timestamp in the past, validated' => [
			'campaignevents-error-start-past',
			$this->getTestDataWithDefault( [
				'id' => null,
				'start' => '19700101120000',
			] )
		];
		yield 'Start timestamp in the past, not validated' => [
			null,
			$this->getTestDataWithDefault( [
				'start' => '19700101120000',
				'validationFlags' => EventFactory::VALIDATE_SKIP_DATES_PAST
			] )
		];
		yield 'Empty end timestamp' => [
			'campaignevents-error-empty-end',
			$this->getTestDataWithDefault( [ 'end' => '' ] )
		];
		yield 'Invalid end timestamp' => [
			'campaignevents-error-invalid-end',
			$this->getTestDataWithDefault( [ 'end' => 'Not a timestamp' ] )
		];
		yield 'Start after end' => [
			'campaignevents-error-start-after-end',
			$this->getTestDataWithDefault( [ 'start' => '20220308160000', 'end' => '20220308120000' ] )
		];
		yield 'Invalid type' => [
			'campaignevents-error-invalid-type',
			$this->getTestDataWithDefault( [ 'type' => 'Some invalid type' ] )
		];
		yield 'No meeting type' => [
			'campaignevents-error-no-meeting-type',
			$this->getTestDataWithDefault( [ 'meetingtype' => 0 ] )
		];
		yield 'Invalid meeting type' => [
			'campaignevents-error-no-meeting-type',
			$this->getTestDataWithDefault( [ 'meetingtype' => 123 ] )
		];
		yield 'Online meeting without URL, successful' => [
			null,
			$this->getTestDataWithDefault( [
				'meetingtype' => EventRegistration::MEETING_TYPE_ONLINE,
				'meetingurl' => null
			] )
		];
		yield 'Online meeting with invalid URL' => [
			'campaignevents-error-invalid-meeting-url',
			$this->getTestDataWithDefault( [
				'meetingtype' => EventRegistration::MEETING_TYPE_ONLINE,
				'meetingurl' => 'Not a URL'
			] )
		];
		yield 'Physical meeting without country' => [
			'campaignevents-error-physical-no-country',
			$this->getTestDataWithDefault( [
				'meetingtype' => EventRegistration::MEETING_TYPE_PHYSICAL,
				'country' => null
			] )
		];
		yield 'Physical meeting without address' => [
			'campaignevents-error-physical-no-address',
			$this->getTestDataWithDefault( [
				'meetingtype' => EventRegistration::MEETING_TYPE_PHYSICAL,
				'address' => null
			] )
		];
		yield 'Physical meeting with invalid country' => [
			'campaignevents-error-invalid-country',
			$this->getTestDataWithDefault( [
				'meetingtype' => EventRegistration::MEETING_TYPE_PHYSICAL,
				'country' => ''
			] )
		];
		yield 'Physical meeting with invalid address' => [
			'campaignevents-error-invalid-address',
			$this->getTestDataWithDefault( [
				'meetingtype' => EventRegistration::MEETING_TYPE_PHYSICAL,
				'address' => ''
			] )
		];
	}

	/**
	 * @param array $factoryArgs
	 * @covers ::newEvent
	 * @dataProvider provideEventDataWithInvalidInternalTimestamps
	 */
	public function testNewEvent__invalidTimestampsInternal( array $factoryArgs ) {
		$factory = $this->getEventFactory();
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Invalid timestamps' );
		$factory->newEvent( ...$factoryArgs );
	}

	public function provideEventDataWithInvalidInternalTimestamps(): Generator {
		yield 'Invalid creation' => [ $this->getTestDataWithDefault( [ 'creation' => 'foobar' ] ) ];
		yield 'Invalid last edit' => [ $this->getTestDataWithDefault( [ 'lastedit' => 'foobar' ] ) ];
		yield 'Invalid deletion' => [ $this->getTestDataWithDefault( [ 'deletion' => 'foobar' ] ) ];
	}

	/**
	 * @param string $url
	 * @param bool $expectedValid
	 * @covers ::newEvent
	 * @covers ::isValidURL
	 * @dataProvider provideURLs
	 */
	public function testURLValidation( string $url, bool $expectedValid ) {
		$factory = $this->getEventFactory();
		$args = $this->getTestDataWithDefault( [ 'chat' => $url ] );
		$ex = null;

		try {
			$factory->newEvent( ...$args );
		} catch ( InvalidEventDataException $ex ) {
		}

		if ( $expectedValid ) {
			$this->assertNull(
				$ex,
				'Should have succeeded; got exception with status: ' . ( $ex ? $ex->getStatus() : '' )
			);
		} else {
			$this->assertNotNull( $ex, 'Should throw an exception' );
			$statusErrorKeys = array_column( $ex->getStatus()->getErrors(), 'message' );
			$this->assertCount( 1, $statusErrorKeys, 'Should only have 1 error' );
			$this->assertSame(
				'campaignevents-error-invalid-chat-url',
				$statusErrorKeys[0],
				'Error message should match'
			);
		}
	}

	public function provideURLs(): array {
		return [
			'Random characters' => [ '24hà°(W!^§*', false ],
			'Invalid protocol' => [ 'foo://abc.org', false ],
			'Invalid protocol 2' => [ 'iaergboyuiberg://abc.org', false ],
			'Invalid characters with HTTPS' => [ 'https://$%&/()=', false ],
			'Invalid characters with invalid protocol' => [ "htp://f('_%$&...)", false ],
			'Invalid characters with relative protocol' => [ "//f('_%$&...)", false ],
			'Valid, HTTP' => [ "http://example.org", true ],
			'Valid, HTTPS' => [ "https://example.org", true ],
			'Valid, relative protocol' => [ "//example.org", true ],
		];
	}

	private function getTestDataWithDefault( array $specificData = [] ): array {
		return array_values( array_replace( self::VALID_DEFAULT_DATA, $specificData ) );
	}
}
