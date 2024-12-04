<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Event;

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
use MediaWiki\Extension\CampaignEvents\MWEntity\WikiLookup;
use MediaWiki\Extension\CampaignEvents\Questions\EventQuestionsRegistry;
use MediaWiki\Extension\CampaignEvents\Topics\ITopicRegistry;
use MediaWiki\Extension\CampaignEvents\TrackingTool\ToolNotFoundException;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolRegistry;
use MediaWiki\Utils\MWTimestamp;
use MediaWikiIntegrationTestCase;

/**
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Event\EventFactory
 * @covers ::__construct
 * @todo Make this a unit test once it's possible to use namespace constants (T310375)
 */
class EventFactoryTest extends MediaWikiIntegrationTestCase {

	// Feb 27, 2022
	private const TEST_TIME = 1646000000;
	private const VALID_TRACKING_TOOL = 'my-tracking-tool';
	private const VALID_DEFAULT_DATA = [
		'id' => 42,
		'page' => 'Event:Some event page title',
		'chat' => 'https://chaturl.example.org',
		'wikis' => [ 'aawiki' ],
		'topics' => [ 'atopic', 'btopic' ],
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
		'questions' => [ 'age' ],
		'creation' => '20220308100000',
		'lastedit' => '20220308100000',
		'deletion' => null,
		false,
		'validationFlags' => EventFactory::VALIDATE_ALL
	];

	/**
	 * @inheritDoc
	 */
	protected function setUp(): void {
		parent::setUp();
		MWTimestamp::setFakeTime( self::TEST_TIME );
	}

	/**
	 * @return string[] A list of wiki IDs that are considered valid within this test class. This is guaranteed to be
	 * a list of all possible "XYwiki" combinations, and is provided for convenience.
	 */
	private static function getValidWikis(): array {
		$validWikis = [];
		for ( $prefix = 'aa'; $prefix !== 'aaa'; $prefix++ ) {
			$validWikis[] = $prefix . 'wiki';
		}
		return $validWikis;
	}

	/**
	 * @return string[] A list of wiki IDs that are considered valid within this test class. This is guaranteed to be
	 * a list of all possible "XYwiki" combinations, and is provided for convenience.
	 */
	private static function getValidTopics(): array {
		$validWikis = [];
		for ( $prefix = 'a'; $prefix !== 'aa'; $prefix++ ) {
			$validWikis[] = $prefix . 'topic';
		}
		return $validWikis;
	}

	private function getEventFactory(
		?CampaignsPageFactory $campaignsPageFactory = null
	): EventFactory {
		if ( !$campaignsPageFactory ) {
			$campaignsPageFactory = $this->createMock( CampaignsPageFactory::class );
			$page = $this->createMock( ICampaignsPage::class );
			$page->method( 'getNamespace' )->willReturn( NS_EVENT );
			$campaignsPageFactory->method( 'newLocalExistingPageFromString' )->willReturn( $page );
		}
		$trackingToolRegistry = $this->createMock( TrackingToolRegistry::class );
		$trackingToolRegistry
			->method( 'newFromUserIdentifier' )
			->with( $this->logicalNot( $this->equalTo( self::VALID_TRACKING_TOOL ) ) )
			->willThrowException( $this->createMock( ToolNotFoundException::class ) );
		$questionsRegistry = new EventQuestionsRegistry( true );

		$wikiLookup = $this->createMock( WikiLookup::class );
		$wikiLookup->method( 'getAllWikis' )->willReturn( self::getValidWikis() );

		$topicLookup = $this->createMock( ITopicRegistry::class );
		$topicLookup->method( 'getAllTopics' )->willReturn( self::getValidTopics() );

		return new EventFactory(
			$campaignsPageFactory,
			$this->createMock( CampaignsPageFormatter::class ),
			$trackingToolRegistry,
			$questionsRegistry,
			$wikiLookup,
			$topicLookup
		);
	}

	/**
	 * Internal helper for testing ::newEvent.
	 *
	 * @param array $factoryArgs
	 * @param array|null $expectedErrors Array of expected error keys, or null to expect success.
	 * @param CampaignsPageFactory|null $campaignsPageFactory
	 * @return EventRegistration The newly created object when successful, else null.
	 */
	private function doTestWithArgs(
		array $factoryArgs,
		?array $expectedErrors,
		?CampaignsPageFactory $campaignsPageFactory = null
	): ?EventRegistration {
		$factory = $this->getEventFactory( $campaignsPageFactory );
		$ex = null;

		try {
			$event = $factory->newEvent( ...$factoryArgs );
		} catch ( InvalidEventDataException $ex ) {
		}

		if ( !$expectedErrors ) {
			$this->assertNull(
				$ex,
				'Should have succeeded, got exception with status: ' . ( $ex ? $ex->getStatus() : '' )
			);
		} else {
			$this->assertNotNull( $ex, 'Should throw an exception' );
			$statusErrorKeys = array_map( static fn ( $msg ) => $msg->getKey(), $ex->getStatus()->getMessages() );
			$this->assertSame( $expectedErrors, $statusErrorKeys, 'Error messages should match' );
		}

		return $event ?? null;
	}

	/**
	 * @param string|null $expectedErrorKey
	 * @param array $factoryArgs
	 * @param CampaignsPageFactory|null $campaignsPageFactory
	 * @covers ::newEvent
	 * @covers ::validatePage
	 * @covers ::validateTrackingTool
	 * @covers ::validateTimezone
	 * @covers ::validateLocalDates
	 * @covers ::isValidURL
	 * @covers ::validateLocation
	 * @dataProvider provideEventData
	 */
	public function testNewEvent(
		?string $expectedErrorKey,
		array $factoryArgs,
		?CampaignsPageFactory $campaignsPageFactory = null
	) {
		$expectedErrors = $expectedErrorKey ? [ $expectedErrorKey ] : null;
		$this->doTestWithArgs( $factoryArgs, $expectedErrors, $campaignsPageFactory );
	}

	public function provideEventData(): Generator {
		yield 'Successful' => [ null, self::getTestDataWithDefault() ];
		yield 'Negative ID' => [ 'campaignevents-error-invalid-id', self::getTestDataWithDefault( [ 'id' => -2 ] ) ];

		yield 'Empty title string' =>
			[ 'campaignevents-error-empty-title', self::getTestDataWithDefault( [ 'page' => '' ] ) ];

		$invalidTitleStr = 'a|b';
		$invalidTitlePageFactory = $this->createMock( CampaignsPageFactory::class );
		$invalidTitlePageFactory->expects( $this->atLeastOnce() )
			->method( 'newLocalExistingPageFromString' )
			->with( $invalidTitleStr )
			->willThrowException( $this->createMock( InvalidTitleStringException::class ) );
		yield 'Invalid title string' => [
			'campaignevents-error-invalid-title',
			self::getTestDataWithDefault( [ 'page' => $invalidTitleStr ] ),
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
			self::getTestDataWithDefault( [ 'page' => $interwikiStr ] ),
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
			self::getTestDataWithDefault( [ 'page' => $nonExistingPageStr ] ),
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
			self::getTestDataWithDefault( [ 'page' => $nonEventPageStr ] ),
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
			self::getTestDataWithDefault( [ 'page' => $specialPageStr ] ),
			$specialCampaignsPageFactory
		];

		yield 'Invalid chat URL' => [
			'campaignevents-error-invalid-chat-url',
			self::getTestDataWithDefault( [ 'chat' => 'not-an-url' ] )
		];

		yield 'Tracking tool without its event ID' => [
			'campaignevents-error-trackingtool-without-eventid',
			self::getTestDataWithDefault( [ 'trackingid' => self::VALID_TRACKING_TOOL, 'trackingeventid' => null ] )
		];
		yield 'Tracking tool event ID without tracking tool' => [
			'campaignevents-error-trackingtool-eventid-without-toolid',
			self::getTestDataWithDefault( [ 'trackingid' => null, 'trackingeventid' => 'foo' ] )
		];
		yield 'Invalid tracking tool ID' => [
			'campaignevents-error-invalid-trackingtool',
			self::getTestDataWithDefault( [ 'trackingid' => 'invalid-tracking-tool', 'trackingeventid' => 'foo' ] )
		];

		yield 'Invalid status' => [
			'campaignevents-error-invalid-status',
			self::getTestDataWithDefault( [ 'status' => 'Some invalid status' ] )
		];

		// Timezone tested more extensively in testNewEvent__invalidTimezone, too
		yield 'Invalid timezone' => [
			'campaignevents-error-invalid-timezone',
			self::getTestDataWithDefault( [ 'timezone' => 'Some invalid timezone' ] )
		];
		yield 'Empty start timestamp' => [
			'campaignevents-error-empty-start',
			self::getTestDataWithDefault( [ 'start' => '' ] )
		];
		yield 'Invalid start timestamp' => [
			'campaignevents-error-invalid-start',
			self::getTestDataWithDefault( [ 'start' => 'Not a timestamp' ] )
		];
		yield 'Start timestamp not in TS_MW format' => [
			'campaignevents-error-invalid-start',
			self::getTestDataWithDefault( [ 'start' => '1661199533' ] )
		];
		yield 'Start timestamp in the past, validated' => [
			'campaignevents-error-start-past',
			self::getTestDataWithDefault( [
				'id' => null,
				'start' => '19700101120000',
			] )
		];
		yield 'Start timestamp in the past, not validated' => [
			null,
			self::getTestDataWithDefault( [
				'start' => '19700101120000',
				'validationFlags' => EventFactory::VALIDATE_SKIP_DATES_PAST
			] )
		];
		yield 'Empty end timestamp' => [
			'campaignevents-error-empty-end',
			self::getTestDataWithDefault( [ 'end' => '' ] )
		];
		yield 'Invalid end timestamp' => [
			'campaignevents-error-invalid-end',
			self::getTestDataWithDefault( [ 'end' => 'Not a timestamp' ] )
		];
		yield 'End timestamp not in TS_MW format' => [
			'campaignevents-error-invalid-end',
			self::getTestDataWithDefault( [ 'end' => '1661199533' ] )
		];
		yield 'Start after end' => [
			'campaignevents-error-start-after-end',
			self::getTestDataWithDefault( [ 'start' => '20220308160000', 'end' => '20220308120000' ] )
		];
		yield 'Invalid type' => [
			'campaignevents-error-invalid-type',
			self::getTestDataWithDefault( [ 'type' => 'Some invalid type' ] )
		];
		yield 'No meeting type' => [
			'campaignevents-error-no-meeting-type',
			self::getTestDataWithDefault( [ 'meetingtype' => 0 ] )
		];
		yield 'Invalid meeting type' => [
			'campaignevents-error-no-meeting-type',
			self::getTestDataWithDefault( [ 'meetingtype' => 123 ] )
		];
		yield 'Online meeting without URL, successful' => [
			null,
			self::getTestDataWithDefault( [
				'meetingtype' => EventRegistration::MEETING_TYPE_ONLINE,
				'meetingurl' => null,
				'country' => null,
				'address' => null,
			] )
		];
		yield 'Online meeting with invalid URL' => [
			'campaignevents-error-invalid-meeting-url',
			self::getTestDataWithDefault( [
				'meetingtype' => EventRegistration::MEETING_TYPE_ONLINE,
				'meetingurl' => 'Not a URL',
				'country' => null,
				'address' => null,
			] )
		];
		yield 'In person meeting without country, successful' => [
			null,
			self::getTestDataWithDefault( [
				'meetingtype' => EventRegistration::MEETING_TYPE_IN_PERSON,
				'country' => null,
				'meetingurl' => null,
			] )
		];
		yield 'In person meeting without address, successful' => [
			null,
			self::getTestDataWithDefault( [
				'meetingtype' => EventRegistration::MEETING_TYPE_IN_PERSON,
				'address' => null,
				'meetingurl' => null,
			] )
		];
		yield 'In person meeting with invalid country' => [
			'campaignevents-error-invalid-country',
			self::getTestDataWithDefault( [
				'meetingtype' => EventRegistration::MEETING_TYPE_IN_PERSON,
				'country' => '',
				'meetingurl' => null,
			] )
		];
		yield 'In person meeting with invalid address' => [
			'campaignevents-error-invalid-address',
			self::getTestDataWithDefault( [
				'meetingtype' => EventRegistration::MEETING_TYPE_IN_PERSON,
				'address' => '',
				'meetingurl' => null,
			] )
		];
		yield 'Online meeting with country' => [
			'campaignevents-error-countryoraddress-not-in-person',
			self::getTestDataWithDefault( [
				'meetingtype' => EventRegistration::MEETING_TYPE_ONLINE,
				'address' => 'Explicitly set',
				'country' => null,
			] )
		];
		yield 'Online meeting with address' => [
			'campaignevents-error-countryoraddress-not-in-person',
			self::getTestDataWithDefault( [
				'meetingtype' => EventRegistration::MEETING_TYPE_ONLINE,
				'address' => null,
				'country' => 'Explicitly set',
			] )
		];
		yield 'In-person meeting with meeting URL' => [
			'campaignevents-error-meeting-url-not-online',
			self::getTestDataWithDefault( [
				'meetingtype' => EventRegistration::MEETING_TYPE_IN_PERSON,
				'meetingurl' => 'https://explicitly-set.example.org',
			] )
		];

		yield 'Invalid participant question' => [
			'campaignevents-error-invalid-question names',
			self::getTestDataWithDefault( [
				'questions' => [ 'this-name-definitely-does-not-exist' ]
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
		$factoryArgs = self::getTestDataWithDefault( [ 'timezone' => $timezone ] );
		$this->doTestWithArgs( $factoryArgs, [ 'campaignevents-error-invalid-timezone' ] );
	}

	public static function provideInvalidTimezones(): array {
		return [
			'Letters only' => [ 'SomethingInvalid' ],
			'Letters, starting with a number' => [ '1SomethingInvalid' ],
			'Alphanumeric with spaces' => [ 'Invalid timezone 1' ],
			'Starting with +' => [ '+ThisIsNotValid' ],
			'Starting with -' => [ '-ThisIsNotValid' ],
			'Positive offset with hours = 100' => [ '+100:00' ],
			'Positive offset with hours > 100' => [ '+147:32' ],
			'Positive offset with minutes > 100' => [ '+02:130' ],
			'Positive offset larger than 60*100 minutes' => [ '+99:99' ],
			'Positive offset equal to 60*100 minutes' => [ '+99:60' ],
			'Negative offset with hours = 100' => [ '-100:00' ],
			'Negative offset with hours > 100' => [ '-147:32' ],
			'Negative offset with minutes > 100' => [ '-02:130' ],
			'Negative offset larger than 60*100 minutes' => [ '-99:99' ],
			'Negative offset equal to 60*100 minutes' => [ '-99:60' ],
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

	public static function provideEventDataWithInvalidInternalTimestamps(): Generator {
		yield 'Invalid creation' => [ self::getTestDataWithDefault( [ 'creation' => 'foobar' ] ) ];
		yield 'Invalid last edit' => [ self::getTestDataWithDefault( [ 'lastedit' => 'foobar' ] ) ];
		yield 'Invalid deletion' => [ self::getTestDataWithDefault( [ 'deletion' => 'foobar' ] ) ];
	}

	/**
	 * @param string $url
	 * @param bool $expectedValid
	 * @covers ::newEvent
	 * @covers ::isValidURL
	 * @dataProvider provideURLs
	 */
	public function testURLValidation( string $url, bool $expectedValid ) {
		$args = self::getTestDataWithDefault( [ 'chat' => $url ] );
		$expectedErrors = $expectedValid ? null : [ 'campaignevents-error-invalid-chat-url' ];
		$this->doTestWithArgs( $args, $expectedErrors );
	}

	public static function provideURLs(): array {
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
			'Valid, diacritics url ' => [ "https://testchat.com/Iñtërnâtiônàlizætiønمثال字ッ", true ],
		];
	}

	/**
	 * @covers ::validateWikis
	 * @dataProvider provideWikis
	 */
	public function testValidateWikis( $wikis, ?array $expectedErrors, $expectedWikis = null ) {
		$args = self::getTestDataWithDefault( [ 'wikis' => $wikis ] );
		$event = $this->doTestWithArgs( $args, $expectedErrors );
		if ( $event ) {
			$this->assertSame( $expectedWikis, $event->getWikis() );
		}
	}

	public static function provideWikis(): Generator {
		yield 'All wikis, valid' => [ EventRegistration::ALL_WIKIS, null, EventRegistration::ALL_WIKIS ];
		yield 'One wiki, valid' => [ [ 'aawiki' ], null, [ 'aawiki' ] ];
		yield 'Multiple unique wikis, valid' => [ [ 'aawiki', 'abwiki' ], null, [ 'aawiki', 'abwiki' ] ];
		yield 'Multiple wikis with duplicates, valid' => [
			[ 'aawiki', 'abwiki', 'aawiki', 'abwiki' ],
			null,
			[ 'aawiki', 'abwiki' ]
		];
		yield 'Duplicates are only counted once' => [
			array_fill( 0, EventFactory::MAX_WIKIS * 2, 'aawiki' ),
			null,
			[ 'aawiki' ]
		];
		yield 'Too many wikis' => [
			self::getValidWikis(),
			[ 'campaignevents-error-too-many-wikis' ]
		];
		yield 'Valid and invalid wikis' => [
			[ 'aawiki', 'doesnotexistwiki' ],
			[ 'campaignevents-error-invalid-wikis' ]
		];
		yield 'Valid and invalid wikis with duplicates' => [
			[ 'aawiki', 'doesnotexistwiki', 'aawiki', 'doesnotexistwiki' ],
			[ 'campaignevents-error-invalid-wikis' ]
		];
		yield 'Only invalid wikis' => [
			[ 'doesnotexistwiki', 'alsodoesnotexistwiki' ],
			[ 'campaignevents-error-invalid-wikis' ]
		];
		yield 'Invalid and too many wikis' => [
			[ 'doesnotexistwiki', ...self::getValidWikis() ],
			[ 'campaignevents-error-too-many-wikis', 'campaignevents-error-invalid-wikis' ]
		];
	}

	/**
	 * @covers ::validateTopics
	 * @dataProvider provideTopics
	 */
	public function testValidateTopics( $topics, ?array $expectedErrors, $expectedTopics = null ) {
		$args = self::getTestDataWithDefault( [ 'topics' => $topics ] );
		$event = $this->doTestWithArgs( $args, $expectedErrors );
		if ( $event ) {
			$this->assertSame( $expectedTopics, $event->gettopics() );
		}
	}

	public static function provideTopics(): Generator {
		yield 'One topic, valid' => [ [ 'atopic' ], null, [ 'atopic' ] ];
		yield 'Multiple unique topics, valid' => [ [ 'atopic', 'btopic' ], null, [ 'atopic', 'btopic' ] ];
		yield 'Multiple topics with duplicates, valid' => [
			[ 'atopic', 'btopic', 'atopic', 'btopic' ],
			null,
			[ 'atopic', 'btopic' ]
		];
		yield 'Duplicates are only counted once' => [
			array_fill( 0, EventFactory::MAX_TOPICS * 2, 'atopic' ),
			null,
			[ 'atopic' ]
		];
		yield 'Too many topics' => [
			self::getValidtopics(),
			[ 'campaignevents-error-too-many-topics' ]
		];
		yield 'Valid and invalid topics' => [
			[ 'atopic', 'doesnotexisttopic' ],
			[ 'campaignevents-error-invalid-topics' ]
		];
		yield 'Valid and invalid topics with duplicates' => [
			[ 'atopic', 'doesnotexisttopic', 'atopic', 'doesnotexisttopic' ],
			[ 'campaignevents-error-invalid-topics' ]
		];
		yield 'Only invalid topics' => [
			[ 'doesnotexisttopic', 'alsodoesnotexisttopic' ],
			[ 'campaignevents-error-invalid-topics' ]
		];
		yield 'Invalid and too many topics' => [
			[ 'doesnotexisttopic', ...self::getValidtopics() ],
			[ 'campaignevents-error-too-many-topics', 'campaignevents-error-invalid-topics' ]
		];
	}

	private static function getTestDataWithDefault( array $specificData = [] ): array {
		return array_values( array_replace( self::VALID_DEFAULT_DATA, $specificData ) );
	}
}
