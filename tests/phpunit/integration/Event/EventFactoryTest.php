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
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsPage;
use MediaWiki\Extension\CampaignEvents\MWEntity\InvalidTitleStringException;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageNotFoundException;
use MediaWiki\Extension\CampaignEvents\MWEntity\UnexpectedInterwikiException;
use MediaWiki\Extension\CampaignEvents\MWEntity\UnexpectedVirtualNamespaceException;
use MediaWikiIntegrationTestCase;
use MWTimestamp;

/**
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Event\EventFactory
 * @covers ::__construct
 * @todo Make this a unit test once it's possible to use namespace constants (T310375)
 */
class EventFactoryTest extends MediaWikiIntegrationTestCase {

	// Feb 27, 2022
	private const TEST_TIME = 1646000000;
	private const VALID_DEFAULT_DATA = [
		'id' => 42,
		'page' => 'Event:Some event page title',
		'chat' => 'https://chaturl.example.org',
		'trackingid' => null,
		'trackingeventid' => null,
		'status' => EventRegistration::STATUS_OPEN,
		'timezone' => 'UTC',
		'start' => '20220308120000',
		'end' => '20220308150000',
		'type' => EventRegistration::TYPE_GENERIC,
		'meetingtype' => EventRegistration::MEETING_TYPE_ONLINE_AND_IN_PERSON,
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
		if ( !$campaignsPageFactory ) {
			$campaignsPageFactory = $this->createMock( CampaignsPageFactory::class );
			$page = $this->createMock( ICampaignsPage::class );
			$page->method( 'getNamespace' )->willReturn( NS_EVENT );
			$campaignsPageFactory->method( 'newLocalExistingPageFromString' )->willReturn( $page );
		}
		return new EventFactory(
			$campaignsPageFactory,
			$this->createMock( CampaignsPageFormatter::class )
		);
	}

	/**
	 * @param string|null $expectedErrorKey
	 * @param array $factoryArgs
	 * @param CampaignsPageFactory|null $campaignsPageFactory
	 * @covers ::newEvent
	 * @covers ::validatePage
	 * @covers ::validateTimezone
	 * @covers ::validateLocalDates
	 * @covers ::isValidURL
	 * @covers ::validateLocation
	 * @dataProvider provideEventData
	 */
	public function testNewEvent(
		?string $expectedErrorKey,
		array $factoryArgs,
		CampaignsPageFactory $campaignsPageFactory = null
	) {
		$factory = $this->getEventFactory( $campaignsPageFactory );
		$ex = null;

		try {
			$factory->newEvent( ...$factoryArgs );
		} catch ( InvalidEventDataException $ex ) {
		}

		if ( !$expectedErrorKey ) {
			$this->assertNull(
				$ex,
				'Should have succeeded, got exception with status: ' . ( $ex ? $ex->getStatus() : '' )
			);
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

		$nonEventPageStr = 'This page is not in the event namespace';
		$nonEventPageObj = $this->createMock( ICampaignsPage::class );
		$nonEventPageObj->method( 'getNamespace' )->willReturn( NS_MAIN );
		$nonEventCampaignsPageFactory = $this->createMock( CampaignsPageFactory::class );
		$nonEventCampaignsPageFactory->expects( $this->atLeastOnce() )
			->method( 'newLocalExistingPageFromString' )
			->with( $nonEventPageStr )
			->willReturn( $nonEventPageObj );
		yield 'Page not in the event namespace' => [
			'campaignevents-error-page-not-event-namespace',
			$this->getTestDataWithDefault( [ 'page' => $nonEventPageStr ] ),
			$nonEventCampaignsPageFactory
		];

		$specialPageStr = 'Special:SomeSpecialPage';
		$specialCampaignsPageFactory = $this->createMock( CampaignsPageFactory::class );
		$specialCampaignsPageFactory->expects( $this->atLeastOnce() )
			->method( 'newLocalExistingPageFromString' )
			->with( $specialPageStr )
			->willThrowException( $this->createMock( UnexpectedVirtualNamespaceException::class ) );
		yield 'Page in a virtual namespace' => [
			'campaignevents-error-page-not-event-namespace',
			$this->getTestDataWithDefault( [ 'page' => $specialPageStr ] ),
			$specialCampaignsPageFactory
		];

		yield 'Invalid chat URL' => [
			'campaignevents-error-invalid-chat-url',
			$this->getTestDataWithDefault( [ 'chat' => 'not-an-url' ] )
		];
		yield 'Invalid status' => [
			'campaignevents-error-invalid-status',
			$this->getTestDataWithDefault( [ 'status' => 'Some invalid status' ] )
		];

		// Timezone tested more extensively in testNewEvent__invalidTimezone, too
		yield 'Invalid timezone' => [
			'campaignevents-error-invalid-timezone',
			$this->getTestDataWithDefault( [ 'timezone' => 'Some invalid timezone' ] )
		];
		yield 'Empty start timestamp' => [
			'campaignevents-error-empty-start',
			$this->getTestDataWithDefault( [ 'start' => '' ] )
		];
		yield 'Invalid start timestamp' => [
			'campaignevents-error-invalid-start',
			$this->getTestDataWithDefault( [ 'start' => 'Not a timestamp' ] )
		];
		yield 'Start timestamp not in TS_MW format' => [
			'campaignevents-error-invalid-start',
			$this->getTestDataWithDefault( [ 'start' => '1661199533' ] )
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
		yield 'End timestamp not in TS_MW format' => [
			'campaignevents-error-invalid-end',
			$this->getTestDataWithDefault( [ 'end' => '1661199533' ] )
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
				'meetingurl' => null,
				'country' => null,
				'address' => null,
			] )
		];
		yield 'Online meeting with invalid URL' => [
			'campaignevents-error-invalid-meeting-url',
			$this->getTestDataWithDefault( [
				'meetingtype' => EventRegistration::MEETING_TYPE_ONLINE,
				'meetingurl' => 'Not a URL',
				'country' => null,
				'address' => null,
			] )
		];
		yield 'In person meeting without country, successful' => [
			null,
			$this->getTestDataWithDefault( [
				'meetingtype' => EventRegistration::MEETING_TYPE_IN_PERSON,
				'country' => null,
				'meetingurl' => null,
			] )
		];
		yield 'In person meeting without address, successful' => [
			null,
			$this->getTestDataWithDefault( [
				'meetingtype' => EventRegistration::MEETING_TYPE_IN_PERSON,
				'address' => null,
				'meetingurl' => null,
			] )
		];
		yield 'In person meeting with invalid country' => [
			'campaignevents-error-invalid-country',
			$this->getTestDataWithDefault( [
				'meetingtype' => EventRegistration::MEETING_TYPE_IN_PERSON,
				'country' => '',
				'meetingurl' => null,
			] )
		];
		yield 'In person meeting with invalid address' => [
			'campaignevents-error-invalid-address',
			$this->getTestDataWithDefault( [
				'meetingtype' => EventRegistration::MEETING_TYPE_IN_PERSON,
				'address' => '',
				'meetingurl' => null,
			] )
		];
		yield 'Online meeting with country' => [
			'campaignevents-error-countryoraddress-not-in-person',
			$this->getTestDataWithDefault( [
				'meetingtype' => EventRegistration::MEETING_TYPE_ONLINE,
				'address' => 'Explicitly set',
				'country' => null,
			] )
		];
		yield 'Online meeting with address' => [
			'campaignevents-error-countryoraddress-not-in-person',
			$this->getTestDataWithDefault( [
				'meetingtype' => EventRegistration::MEETING_TYPE_ONLINE,
				'address' => null,
				'country' => 'Explicitly set',
			] )
		];
		yield 'In-person meeting with meeting URL' => [
			'campaignevents-error-meeting-url-not-online',
			$this->getTestDataWithDefault( [
				'meetingtype' => EventRegistration::MEETING_TYPE_IN_PERSON,
				'meetingurl' => 'https://explicitly-set.example.org',
			] )
		];
	}

	/**
	 * This test is specifically about validating the timezone, to make sure that there are no weird bugs like
	 * T315692#8306011.
	 * @param string $timezone
	 * @covers ::newEvent
	 * @covers ::validateTimezone
	 * @dataProvider provideInvalidTimezones
	 */
	public function testNewEvent__invalidTimezone( string $timezone ) {
		$factory = $this->getEventFactory();
		$factoryArgs = $this->getTestDataWithDefault( [ 'timezone' => $timezone ] );

		try {
			$factory->newEvent( ...$factoryArgs );
			$this->fail( 'Should throw an exception' );
		} catch ( InvalidEventDataException $ex ) {
			$statusErrorKeys = array_column( $ex->getStatus()->getErrors(), 'message' );
			$this->assertCount( 1, $statusErrorKeys, 'Should only have 1 error' );
			$this->assertSame( 'campaignevents-error-invalid-timezone', $statusErrorKeys[0] );
		}
	}

	public function provideInvalidTimezones(): array {
		return [
			'Letters only' => [ 'SomethingInvalid' ],
			'Letters, starting with a number' => [ '1SomethingInvalid' ],
			'Alphanumeric with spaces' => [ 'Invalid timezone 1' ],
			'Starting with +' => [ '+ThisIsNotValid' ],
			'Starting with -' => [ '-ThisIsNotValid' ],
			'Offset larger than 60*100 minutes' => [ '+99:99' ],
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
