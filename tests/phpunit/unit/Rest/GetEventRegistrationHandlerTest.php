<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Rest;

use DateTimeZone;
use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Extension\CampaignEvents\Address\Address;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\EventTypesRegistry;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventNotFoundException;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWPageProxy;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\Organizers\Organizer;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\Rest\GetEventRegistrationHandler;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolAssociation;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolRegistry;
use MediaWiki\Page\PageIdentityValue;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiUnitTestCase;

/**
 * @group Test
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\GetEventRegistrationHandler
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\EventIDParamTrait
 */
class GetEventRegistrationHandlerTest extends MediaWikiUnitTestCase {
	use HandlerTestTrait;

	private const REQ_DATA = [
		'pathParams' => [ 'id' => 42 ]
	];

	private const TRACKING_TOOL_DB_ID = 1;
	private const TRACKING_TOOL_USER_ID = 'some-tool';

	private function newHandler(
		?IEventLookup $eventLookup = null,
		?PermissionChecker $permissionChecker = null,
		?CampaignsCentralUserLookup $centralUserLookup = null,
		?OrganizersStore $organizersStore = null,
		?ParticipantsStore $participantStore = null,
	): GetEventRegistrationHandler {
		$trackingToolRegistry = $this->createMock( TrackingToolRegistry::class );
		$trackingToolRegistry->method( 'getUserInfo' )
			->with( self::TRACKING_TOOL_DB_ID )
			->willReturn( [ 'user-id' => self::TRACKING_TOOL_USER_ID ] );
		if ( !$permissionChecker ) {
			$permissionChecker = $this->createMock( PermissionChecker::class );
			$permissionChecker->method( 'userCanViewSensitiveEventData' )->willReturn( true );
		}
		if ( !$organizersStore ) {
			$organizersStore = $this->createMock( OrganizersStore::class );
			$organizersStore->method( 'getEventOrganizer' )->willReturn( $this->createMock( Organizer::class ) );
		}
		return new GetEventRegistrationHandler(
			$eventLookup ?? $this->createMock( IEventLookup::class ),
			$trackingToolRegistry,
			$permissionChecker,
			$centralUserLookup ?? $this->createMock( CampaignsCentralUserLookup::class ),
			$organizersStore,
			$participantStore ?? $this->createMock( ParticipantsStore::class ),
		);
	}

	public function testRun() {
		$eventPageStr = 'Project:Foo bar';
		$eventPage = new MWPageProxy(
			new PageIdentityValue( 1, NS_PROJECT, 'Foo_bar', WikiAwareEntity::LOCAL ),
			$eventPageStr
		);
		$timezoneName = 'UTC';
		$eventData = [
			'id' => 1,
			'name' => 'Some name',
			'event_page' => $eventPageStr,
			'event_page_wiki' => WikiMap::getCurrentWikiId(),
			'status' => EventRegistration::STATUS_OPEN,
			'timezone' => new DateTimeZone( $timezoneName ),
			'start_time' => '20220220200220',
			'end_time' => '20220220200222',
			'types' => [ EventTypesRegistry::EVENT_TYPE_OTHER ],
			'tracks_contributions' => true,
			'wikis' => [ 'awiki', 'bwiki' ],
			'topics' => [ 'atopic', 'btopic' ],
			'tracking_tool_id' => self::TRACKING_TOOL_USER_ID,
			'tracking_tool_event_id' => 'bar',
			'online_meeting' => true,
			'inperson_meeting' => true,
			'meeting_url' => 'https://meeting-url.example.org',
			'meeting_country_code' => 'FR',
			'meeting_address' => 'My address 123',
			'chat_url' => 'https://some-chat.example.org',
			'is_test_event' => false,
			'questions' => [],
		];
		$participationOptions = ( $eventData['online_meeting'] ? EventRegistration::PARTICIPATION_OPTION_ONLINE : 0 )
			| ( $eventData['inperson_meeting'] ? EventRegistration::PARTICIPATION_OPTION_IN_PERSON : 0 );
		$registration = new ExistingEventRegistration(
			$eventData['id'],
			$eventData['name'],
			$eventPage,
			$eventData['status'],
			$eventData['timezone'],
			wfTimestamp( TS_MW, $eventData['start_time'] ),
			wfTimestamp( TS_MW, $eventData['end_time'] ),
			$eventData['types'],
			$eventData['wikis'],
			$eventData['topics'],
			$participationOptions,
			$eventData['meeting_url'],
			new Address( $eventData['meeting_address'], $eventData['meeting_country_code'] ),
			$eventData['tracks_contributions'],
			[
				new TrackingToolAssociation(
					self::TRACKING_TOOL_DB_ID,
					$eventData['tracking_tool_event_id'],
					TrackingToolAssociation::SYNC_STATUS_UNKNOWN,
					null
				)
			],
			$eventData['chat_url'],
			$eventData['is_test_event'],
			$eventData['questions'],
			'1646000000',
			'1646000000',
			null
		);
		$eventLookup = $this->createMock( IEventLookup::class );
		$eventLookup->expects( $this->once() )
			->method( 'getEventByID' )
			->willReturn( $registration );

		$handler = $this->newHandler( $eventLookup );
		$respData = $this->executeHandlerAndGetBodyData( $handler, new RequestData( self::REQ_DATA ) );

		$expected = array_diff_key(
			$eventData,
			[ 'timezone' => 1, 'tracking_tool_id' => 1, 'tracking_tool_event_id' => 1 ],
		);
		$expected['timezone'] = $timezoneName;
		$expected['tracking_tools'] = [
			[
				'tool_id' => $eventData['tracking_tool_id'],
				'tool_event_id' => $eventData['tracking_tool_event_id']
			],
		];

		ksort( $respData );
		ksort( $expected );
		$this->assertSame( $expected, $respData );
	}

	public function testRun__invalidEvent() {
		$eventLookup = $this->createMock( IEventLookup::class );
		$eventLookup->expects( $this->once() )
			->method( 'getEventByID' )
			->willThrowException( $this->createMock( EventNotFoundException::class ) );
		$handler = $this->newHandler( $eventLookup );
		try {
			$this->executeHandler( $handler, new RequestData( self::REQ_DATA ) );
			$this->fail( 'No exception thrown' );
		} catch ( LocalizedHttpException $e ) {
			$this->assertSame(
				'campaignevents-rest-event-not-found',
				$e->getMessageValue()->getKey()
			);
			$this->assertSame( 404, $e->getCode() );
		}
	}

	public function testRun__deletedEvent() {
		$deletedEvent = $this->createMock( ExistingEventRegistration::class );
		$deletedEvent->method( 'getDeletionTimestamp' )->willReturn( '1654000000' );
		$eventLookup = $this->createMock( IEventLookup::class );
		$eventLookup->expects( $this->once() )->method( 'getEventByID' )->willReturn( $deletedEvent );
		$handler = $this->newHandler( $eventLookup );
		try {
			$this->executeHandler( $handler, new RequestData( self::REQ_DATA ) );
			$this->fail( 'No exception thrown' );
		} catch ( LocalizedHttpException $e ) {
			$this->assertSame(
				'campaignevents-rest-get-registration-deleted',
				$e->getMessageValue()->getKey()
			);
			$this->assertSame( 404, $e->getCode() );
		}
	}

	/** @dataProvider provideRunSensitiveData */
	public function testRun__sensitiveData(
		bool $eventIsLocal,
		bool $canViewSensitiveData,
		bool $hasGlobalAccount,
		bool $isOrganizer,
		bool $isParticipant,
		?string $dataValue,
		bool $expectsResponseField,
	) {
		$event = $this->createMock( ExistingEventRegistration::class );
		$event->method( 'isOnLocalWiki' )->willReturn( $eventIsLocal );
		$event->method( 'getMeetingURL' )->willReturn( $dataValue );
		$event->method( 'getChatURL' )->willReturn( $dataValue );
		$eventLookup = $this->createMock( IEventLookup::class );
		$eventLookup->expects( $this->atLeastOnce() )->method( 'getEventByID' )->willReturn( $event );

		$permissionChecker = $this->createMock( PermissionChecker::class );
		$permissionChecker->method( 'userCanViewSensitiveEventData' )->willReturn( $canViewSensitiveData );

		$centralUserLookup = $this->createMock( CampaignsCentralUserLookup::class );
		if ( $hasGlobalAccount ) {
			$centralUserLookup->method( 'newFromAuthority' )->willReturn( new CentralUser( 234 ) );
		} else {
			$centralUserLookup->method( 'newFromAuthority' )->willThrowException( new UserNotGlobalException( 234 ) );
		}

		$organizersStore = $this->createMock( OrganizersStore::class );
		$organizersStore->method( 'getEventOrganizer' )
			->willReturn( $isOrganizer ? $this->createMock( Organizer::class ) : null );

		$participantsStore = $this->createMock( ParticipantsStore::class );
		$participantsStore->method( 'userParticipatesInEvent' )->willReturn( $isParticipant );

		$handler = $this->newHandler(
			$eventLookup,
			$permissionChecker,
			$centralUserLookup,
			$organizersStore,
			$participantsStore
		);
		$respData = $this->executeHandlerAndGetBodyData( $handler, new RequestData( self::REQ_DATA ) );

		if ( $expectsResponseField ) {
			$this->assertArrayHasKey( 'meeting_url', $respData );
			$this->assertSame( $dataValue, $respData['meeting_url'], 'Meeting URL' );
			$this->assertArrayHasKey( 'chat_url', $respData );
			$this->assertSame( $dataValue, $respData['chat_url'], 'Chat URL' );
		} else {
			$this->assertArrayNotHasKey( 'meeting_url', $respData );
			$this->assertArrayNotHasKey( 'chat_url', $respData );
		}
	}

	public static function provideRunSensitiveData() {
		$localEvent = $canViewSensitiveData = $hasGlobalAccount = true;
		$foreignEvent = $cannotViewSensitiveData = $doesNotHaveGlobalAccount = false;
		$isOrganizer = $isParticipant = true;
		$isNotOrganizer = $isNotParticipant = false;
		$setValue = 'some-random-value';
		$unsetValue = null;

		yield 'Event not local' => [
			$foreignEvent,
			$canViewSensitiveData,
			$hasGlobalAccount,
			$isOrganizer,
			$isParticipant,
			$setValue,
			false
		];
		yield 'User cannot view sensitive data' => [
			$localEvent,
			$cannotViewSensitiveData,
			$hasGlobalAccount,
			$isOrganizer,
			$isParticipant,
			$setValue,
			false
		];
		yield 'User does not have global account' => [
			$localEvent,
			$canViewSensitiveData,
			$doesNotHaveGlobalAccount,
			$isOrganizer,
			$isParticipant,
			$setValue,
			false
		];
		yield 'User is neither participant nor organizer' => [
			$localEvent,
			$canViewSensitiveData,
			$hasGlobalAccount,
			$isNotOrganizer,
			$isNotParticipant,
			$setValue,
			false
		];
		yield 'User is participant but not organizer' => [
			$localEvent,
			$canViewSensitiveData,
			$hasGlobalAccount,
			$isNotOrganizer,
			$isParticipant,
			$setValue,
			true
		];
		yield 'User is organizer but not participant' => [
			$localEvent,
			$canViewSensitiveData,
			$hasGlobalAccount,
			$isOrganizer,
			$isNotParticipant,
			$setValue,
			true
		];
		yield 'User is participant and organizer' => [
			$localEvent,
			$canViewSensitiveData,
			$hasGlobalAccount,
			$isOrganizer,
			$isParticipant,
			$setValue,
			true
		];
		yield 'User is participant and organizer, sensitive data not set' => [
			$localEvent,
			$canViewSensitiveData,
			$hasGlobalAccount,
			$isOrganizer,
			$isParticipant,
			$unsetValue,
			true
		];
	}
}
