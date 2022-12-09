<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Special;

use Html;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventNotFoundException;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\FrontendModules\EventDetailsModule;
use MediaWiki\Extension\CampaignEvents\FrontendModules\EventDetailsParticipantsModule;
use MediaWiki\Extension\CampaignEvents\FrontendModules\FrontendModulesFactory;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWAuthorityProxy;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserLinker;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use OOUI\ButtonWidget;
use OOUI\IndexLayout;
use OOUI\TabPanelLayout;
use OOUI\Tag;
use SpecialPage;
use Wikimedia\Message\IMessageFormatterFactory;
use Wikimedia\Message\MessageValue;

class SpecialEventDetails extends SpecialPage {
	public const PAGE_NAME = 'EventDetails';
	private const MODULE_STYLES = [
		'oojs-ui.styles.icons-movement',
		'ext.campaignEvents.specialeventdetails.styles',
		'oojs-ui-widgets.styles' ];
	public const EVENT_DETAILS_PANEL = 'EventDetailsPanel';
	public const PARTICIPANTS_PANEL = 'ParticipantsPanel';

	/** @var IEventLookup */
	protected $eventLookup;
	/** @var ExistingEventRegistration|null */
	protected $event;
	/** @var ParticipantsStore */
	private $participantsStore;
	/** @var OrganizersStore */
	private $organizersStore;
	/** @var IMessageFormatterFactory */
	private $messageFormatterFactory;
	/** @var CampaignsCentralUserLookup */
	private $centralUserLookup;
	/** @var FrontendModulesFactory */
	private $frontendModulesFactory;

	/**
	 * @param IEventLookup $eventLookup
	 * @param ParticipantsStore $participantsStore
	 * @param OrganizersStore $organizersStore
	 * @param IMessageFormatterFactory $messageFormatterFactory
	 * @param CampaignsCentralUserLookup $centralUserLookup
	 * @param FrontendModulesFactory $frontendModulesFactory
	 */
	public function __construct(
		IEventLookup $eventLookup,
		ParticipantsStore $participantsStore,
		OrganizersStore $organizersStore,
		IMessageFormatterFactory $messageFormatterFactory,
		CampaignsCentralUserLookup $centralUserLookup,
		FrontendModulesFactory $frontendModulesFactory
	) {
		parent::__construct( self::PAGE_NAME );
		$this->eventLookup = $eventLookup;
		$this->participantsStore = $participantsStore;
		$this->organizersStore = $organizersStore;
		$this->messageFormatterFactory = $messageFormatterFactory;
		$this->centralUserLookup = $centralUserLookup;
		$this->frontendModulesFactory = $frontendModulesFactory;
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $par ) {
		$this->setHeaders();
		if ( $par === null ) {
			$this->outputErrorBox( 'campaignevents-event-details-no-event-id-provided' );
			return;
		}
		$eventID = (int)$par;
		if ( (string)$eventID !== $par ) {
			$this->outputErrorBox( 'campaignevents-event-details-invalid-id' );
			return;
		}
		try {
			$this->event = $this->eventLookup->getEventByID( $eventID );
		} catch ( EventNotFoundException $_ ) {
			$this->outputErrorBox( 'campaignevents-event-details-not-found' );
			return;
		}

		if ( $this->event->getDeletionTimestamp() !== null ) {
			$this->outputErrorBox( 'campaignevents-event-details-event-deleted' );
			return;
		}

		$out = $this->getOutput();
		$language = $this->getLanguage();

		$out->enableOOUI();
		$out->addModuleStyles(
			array_merge(
				self::MODULE_STYLES,
				EventDetailsModule::MODULE_STYLES,
				EventDetailsParticipantsModule::MODULE_STYLES,
				UserLinker::MODULE_STYLES
			)
		);

		$this->addHelpLink( 'Extension:CampaignEvents' );

		$out->addModules( [ 'ext.campaignEvents.specialeventdetails', 'oojs-ui-widgets' ] );

		$eventID = $this->event->getID();
		$msgFormatter = $this->messageFormatterFactory->getTextFormatter( $language->getCode() );

		try {
			$centralUser = $this->centralUserLookup->newFromAuthority( new MWAuthorityProxy( $this->getAuthority() ) );
			$isOrganizer = $this->organizersStore->isEventOrganizer( $eventID, $centralUser );
			$isParticipant = $this->participantsStore->userParticipatesInEvent( $eventID, $centralUser, true );
		} catch ( UserNotGlobalException $_ ) {
			$isOrganizer = $isParticipant = false;
		}

		$out->addJsConfigVars( [
			'wgCampaignEventsEventID' => $eventID,
		] );
		$out->setPageTitle(
			$msgFormatter->format(
				MessageValue::new( 'campaignevents-event-details-event' )
					->params( $this->event->getName() )
			)
		);

		if ( $isOrganizer ) {
			// Special:MyEvents is not meaningful for participants, see T314879
			$backLink = new ButtonWidget( [
				'framed' => false,
				'flags' => [ 'progressive' ],
				'label' => $msgFormatter->format( MessageValue::new( 'campaignevents-back-to-your-events' ) ),
				'href' => SpecialPage::getTitleFor( SpecialMyEvents::PAGE_NAME )->getLocalURL(),
				'icon' => 'arrowPrevious',
				'classes' => [ 'ext-campaignevents-eventdetails-back-btn' ]
			] );

			$out->addHTML( $backLink );
		}

		$main = new IndexLayout( [
			'infusable' => true,
			'expanded' => false,
			'framed' => false,
			'id' => 'ext-campaignevents-eventdetails-tabs'
		] );
		$eventDetailsModule = $this->frontendModulesFactory->newEventDetailsModule();
		$eventParticipantsModule = $this->frontendModulesFactory->newEventDetailsParticipantsModule();
		$tabs = [];
		$tabs[] = $this->createTab(
			self::EVENT_DETAILS_PANEL,
			$msgFormatter->format( MessageValue::new( 'campaignevents-event-details-tab-event-details' ) ),
			$eventDetailsModule->createContent(
				$language,
				$this->event,
				$this->getUser(),
				$isOrganizer,
				$isParticipant,
				$out
			)
		);
		$tabs[] = $this->createTab(
			self::PARTICIPANTS_PANEL,
			$msgFormatter->format( MessageValue::new( 'campaignevents-event-details-tab-participants' ) ),
			$eventParticipantsModule->createContent(
				$language,
				$this->event,
				$this->getUser(),
				new MWAuthorityProxy( $this->getAuthority() ),
				$isOrganizer,
				$out )
		);

		$main->addTabPanels( $tabs );
		$selectedTab = $this->getRequest()->getRawVal( 'tab', self::EVENT_DETAILS_PANEL );
		$main->setTabPanel( $selectedTab );
		$out->addHTML( $main );
	}

	/**
	 * @param string $errorMsg
	 * @param mixed ...$msgParams
	 * @return void
	 */
	protected function outputErrorBox( string $errorMsg, ...$msgParams ): void {
		$this->getOutput()->addHTML( Html::errorBox(
			$this->msg( $errorMsg )->params( ...$msgParams )->parseAsBlock()
		) );
	}

	/**
	 * @param string $name
	 * @param string $label
	 * @param Tag $content
	 * @return TabPanelLayout
	 */
	private function createTab( string $name, string $label, Tag $content ): TabPanelLayout {
		return new TabPanelLayout(
			$name,
			[
				'id' => $name,
				'label' => $label,
				'expanded' => false,
				'tabItemConfig' => [
					'href' => $this->getFullTitle()->getLinkURL( [ 'tab' => $name ] )
				],
				'content' => $content
			]
		);
	}
}
