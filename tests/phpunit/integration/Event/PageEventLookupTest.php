<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Event;

use DateTimeZone;
use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\PageEventLookup;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFactory;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWPageProxy;
use MediaWiki\Extension\Translate\MessageGroupProcessing\MessageGroups;
use MediaWiki\Extension\Translate\PageTranslation\TranslatablePage;
use MediaWiki\Page\PageIdentityValue;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\CampaignEvents\Event\PageEventLookup
 * @group Database
 */
class PageEventLookupTest extends MediaWikiIntegrationTestCase {
	private function getLookup( ?IEventLookup $eventLookup = null ): PageEventLookup {
		return new PageEventLookup(
			$eventLookup ?? $this->createMock( IEventLookup::class ),
			$this->createMock( CampaignsPageFactory::class ),
			$this->createMock( TitleFactory::class ),
			false
		);
	}

	public function testGetRegistrationForLocalPage__notInEventNamespace() {
		$page = new PageIdentityValue( 42, NS_PROJECT, 'Some_title', WikiAwareEntity::LOCAL );
		$eventLookup = $this->createNoOpMock( IEventLookup::class );
		$this->assertNull( $this->getLookup( $eventLookup )->getRegistrationForLocalPage( $page ) );
	}

	public function testGetRegistrationForLocalPage__translatablePage() {
		$this->markTestSkippedIfExtensionNotLoaded( 'Translate' );
		[ $translationSubpage, $eventID ] = $this->createTranslationPageWithEventAndSubpage();
		$pageEventLookup = CampaignEventsServices::getPageEventLookup();

		$subpageEvent = $pageEventLookup->getRegistrationForLocalPage( $translationSubpage );
		$this->assertNotNull( $subpageEvent, 'Finds event when canonicalizing' );
		$this->assertSame( $eventID, $subpageEvent->getID() );

		$directEvent = $pageEventLookup->getRegistrationForLocalPage(
			$translationSubpage,
			PageEventLookup::GET_DIRECT
		);
		$this->assertNull( $directEvent, 'No event found when not canonicalizing' );
	}

	public function testGetRegistrationForPage__notInEventNamespace() {
		$page = new PageIdentityValue( 42, NS_PROJECT, 'Some_title', WikiAwareEntity::LOCAL );
		$pageProxy = new MWPageProxy( $page, 'DoesNotMatter' );
		$eventLookup = $this->createNoOpMock( IEventLookup::class );
		$this->assertNull( $this->getLookup( $eventLookup )->getRegistrationForPage( $pageProxy ) );
	}

	public function testGetRegistrationForPage__translatablePage() {
		$this->markTestSkippedIfExtensionNotLoaded( 'Translate' );
		[ $translationSubpage, $eventID ] = $this->createTranslationPageWithEventAndSubpage();
		$pageEventLookup = CampaignEventsServices::getPageEventLookup();
		$campaignsTranslationSubpage = CampaignEventsServices::getPageFactory()
			->newFromLocalMediaWikiPage( $translationSubpage );

		$subpageEvent = $pageEventLookup->getRegistrationForPage( $campaignsTranslationSubpage );
		$this->assertNotNull( $subpageEvent, 'Finds event when canonicalizing' );
		$this->assertSame( $eventID, $subpageEvent->getID() );

		$directEvent = $pageEventLookup->getRegistrationForPage(
			$campaignsTranslationSubpage,
			PageEventLookup::GET_DIRECT
		);
		$this->assertNull( $directEvent, 'No event found when not canonicalizing' );
	}

	/**
	 * @return array With [ translation subpage, event ID ]
	 */
	private function createTranslationPageWithEventAndSubpage(): array {
		$title = Title::makeTitle( NS_EVENT, __METHOD__ );
		$page = $this->getNonexistingTestPage( $title );
		$translatablePage = TranslatablePage::newFromTitle( $page->getTitle() );
		$editStatus = $this->editPage( $page, '<translate>Foo</translate>' );
		$translatablePage->addMarkedTag( $editStatus->getNewRevision()->getId() );
		MessageGroups::singleton()->recache();

		$sourceLang = $translatablePage->getMessageGroup()->getSourceLanguage();
		$transLang = $sourceLang !== 'it' ? 'it' : 'en';
		$transTitle = $title->getSubpage( $transLang );
		$subpage = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle( $transTitle );

		$event = new EventRegistration(
			null,
			$page->getTitle()->getText(),
			new MWPageProxy( $page, $page->getTitle()->getPrefixedText() ),
			null,
			[],
			[],
			EventRegistration::STATUS_OPEN,
			new DateTimeZone( 'UTC' ),
			'20240229120000',
			'20240301120000',
			EventRegistration::TYPE_GENERIC,
			EventRegistration::MEETING_TYPE_ONLINE,
			null,
			null,
			null,
			[],
			null,
			null,
			null
		);
		$eventID = CampaignEventsServices::getEventStore()->saveRegistration( $event );

		return [ $subpage, $eventID ];
	}
}
