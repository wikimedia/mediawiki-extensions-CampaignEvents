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
use MediaWiki\Extension\Translate\PageTranslation\TranslatablePage;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\PageIdentityValue;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\CampaignEvents\Event\PageEventLookup
 * @group Database
 * @todo The Translate tests aren't run in CI due to T358985
 */
class PageEventLookupTest extends MediaWikiIntegrationTestCase {
	private function getLookup( IEventLookup $eventLookup = null ): PageEventLookup {
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
		$event = $pageEventLookup->getRegistrationForLocalPage( $translationSubpage );
		$this->assertNotNull( $event );
		$this->assertSame( $eventID, $event->getID() );
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
		$event = $pageEventLookup->getRegistrationForPage( $campaignsTranslationSubpage );
		$this->assertNotNull( $event );
		$this->assertSame( $eventID, $event->getID() );
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

		$sourceLang = $translatablePage->getMessageGroup()->getSourceLanguage();
		$transLang = $sourceLang !== 'it' ? 'it' : 'en';
		$transTitle = $title->getSubpage( $transLang );
		$subpage = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $transTitle );

		$event = new EventRegistration(
			null,
			$page->getTitle()->getText(),
			new MWPageProxy( $page, $page->getTitle()->getPrefixedText() ),
			null,
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
