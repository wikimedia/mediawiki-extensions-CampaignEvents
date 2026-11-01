<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Special;

use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWPageProxy;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Participants\RegisterParticipantCommand;
use MediaWiki\Extension\CampaignEvents\PolicyMessagesLookup;
use MediaWiki\Extension\CampaignEvents\Questions\EventQuestionsRegistry;
use MediaWiki\Extension\CampaignEvents\Special\SpecialRegisterForEvent;
use MediaWiki\Page\PageIdentityValue;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Tests\Specials\SpecialPageTestBase;
use StatusValue;

/**
 * @group Database
 * @covers \MediaWiki\Extension\CampaignEvents\Special\SpecialRegisterForEvent
 */
class SpecialRegisterForEventTest extends SpecialPageTestBase {
	private const EVENT_ID = 42;

	private MWPageProxy $eventPage;

	protected function setUp(): void {
		parent::setUp();
		$this->eventPage = new MWPageProxy(
			new PageIdentityValue( 1, NS_PROJECT, 'Some_event', WikiAwareEntity::LOCAL ),
			'Project:Some event'
		);
	}

	protected function newSpecialPage(): SpecialRegisterForEvent {
		$event = $this->createMock( ExistingEventRegistration::class );
		$event->method( 'getID' )->willReturn( self::EVENT_ID );
		$event->method( 'getName' )->willReturn( 'Some event' );
		$event->method( 'getPage' )->willReturn( $this->eventPage );
		$event->method( 'getStatus' )->willReturn( EventRegistration::STATUS_OPEN );
		$eventLookup = $this->createMock( IEventLookup::class );
		$eventLookup->method( 'getEventByID' )->with( self::EVENT_ID )->willReturn( $event );

		$centralUserLookup = $this->createMock( CampaignsCentralUserLookup::class );
		$centralUserLookup->method( 'newFromAuthority' )->willReturn( new CentralUser( 1 ) );

		$registerCommand = $this->createMock( RegisterParticipantCommand::class );
		$registerCommand->method( 'registerIfAllowed' )->willReturn( StatusValue::newGood( true ) );

		return new SpecialRegisterForEvent(
			$eventLookup,
			$centralUserLookup,
			$registerCommand,
			$this->createMock( ParticipantsStore::class ),
			$this->createMock( PolicyMessagesLookup::class ),
			new EventQuestionsRegistry( false ),
			CampaignEventsServices::getPageURLResolver()
		);
	}

	public function testBackToEventPageLink() {
		[ $html ] = $this->executeSpecialPage(
			(string)self::EVENT_ID,
			null,
			null,
			$this->getTestUser()->getAuthority()
		);
		$eventPageURL = CampaignEventsServices::getPageURLResolver()->getUrl( $this->eventPage );

		$this->assertStringContainsString( 'ext-campaignevents-registerforevent-back-btn', $html );
		$this->assertStringContainsString( '(campaignevents-register-back-to-event-page)', $html );
		$this->assertStringContainsString( "href='" . htmlspecialchars( $eventPageURL ) . "'", $html );
	}

	public function testBackToEventPageLinkAboveSuccessMessage() {
		// The edit token is added by SpecialPageExecutor
		[ $html ] = $this->executeSpecialPage(
			(string)self::EVENT_ID,
			new FauxRequest( [ 'wpIsPrivate' => '' ], true ),
			null,
			$this->getTestUser()->getAuthority()
		);

		$linkPos = strpos( $html, 'ext-campaignevents-registerforevent-back-btn' );
		$successPos = strpos( $html, '(campaignevents-register-success)' );
		$this->assertNotFalse( $successPos, 'The success message is shown' );
		$this->assertNotFalse( $linkPos, 'The link is shown' );
		$this->assertLessThan( $successPos, $linkPos, 'The link is shown above the success message' );
	}
}
