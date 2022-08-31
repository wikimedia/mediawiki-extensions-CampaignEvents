<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Rest;

use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventNotFoundException;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWPageProxy;
use MediaWiki\Extension\CampaignEvents\Rest\GetEventRegistrationHandler;
use MediaWiki\Page\PageIdentityValue;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWikiUnitTestCase;
use WikiMap;

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

	private function newHandler(
		IEventLookup $eventLookup = null
	): GetEventRegistrationHandler {
		return new GetEventRegistrationHandler(
			$eventLookup ?? $this->createMock( IEventLookup::class )
		);
	}

	public function testRun() {
		$eventPageStr = 'Event:Foo bar';
		// NOTE: We can't use the NS_EVENT constant in unit tests
		$eventPage = new MWPageProxy(
			new PageIdentityValue( 1, 1728, 'Foo_bar', WikiAwareEntity::LOCAL ),
			$eventPageStr
		);
		$eventData = [
			'id' => 1,
			'name' => 'Some name',
			'event_page' => $eventPageStr,
			'event_page_wiki' => WikiMap::getCurrentWikiId(),
			'chat_url' => 'https://some-chat.example.org',
			'tracking_tool_name' => 'Tracking tool',
			'tracking_tool_url' => 'https://tracking-tool.example.org',
			'status' => EventRegistration::STATUS_OPEN,
			'start_time' => '20220220200220',
			'end_time' => '20220220200222',
			'type' => EventRegistration::TYPE_GENERIC,
			'online_meeting' => true,
			'inperson_meeting' => true,
			'meeting_url' => 'https://meeting-url.example.org',
			'meeting_country' => 'My country',
			'meeting_address' => 'My address 123',
		];
		$meetingType = ( $eventData['online_meeting'] ? EventRegistration::MEETING_TYPE_ONLINE : 0 )
			| ( $eventData['inperson_meeting'] ? EventRegistration::MEETING_TYPE_IN_PERSON : 0 );
		$registration = new ExistingEventRegistration(
			$eventData['id'],
			$eventData['name'],
			$eventPage,
			$eventData['chat_url'],
			$eventData['tracking_tool_name'],
			$eventData['tracking_tool_url'],
			$eventData['status'],
			wfTimestamp( TS_MW, $eventData['start_time'] ),
			wfTimestamp( TS_MW, $eventData['end_time'] ),
			$eventData['type'],
			$meetingType,
			$eventData['meeting_url'],
			$eventData['meeting_country'],
			$eventData['meeting_address'],
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
		$this->assertSame(
			// TODO MVP Check these too
			array_diff_key( $eventData, [ 'tracking_tool_name' => 1, 'tracking_tool_url' => 1, 'type' => 1 ] ),
			$respData
		);
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
}
