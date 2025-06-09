<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Special;

use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventNotFoundException;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\FrontendModules\EventDetailsModule;
use MediaWiki\Extension\CampaignEvents\FrontendModules\EventDetailsParticipantsModule;
use MediaWiki\Extension\CampaignEvents\FrontendModules\FrontendModulesFactory;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserLinker;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\Utils;
use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\WikiMap\WikiMap;
use OOUI\ButtonWidget;
use OOUI\HtmlSnippet;
use OOUI\IndexLayout;
use OOUI\MessageWidget;
use OOUI\TabPanelLayout;
use OOUI\Tag;
use Wikimedia\Message\IMessageFormatterFactory;
use Wikimedia\Message\MessageValue;

class SpecialEventDetails extends SpecialPage {
	public const PAGE_NAME = 'EventDetails';
	private const MODULE_STYLES = [
		'oojs-ui.styles.icons-movement',
		'oojs-ui.styles.icons-wikimedia',
		'ext.campaignEvents.specialPages.styles',
		'oojs-ui-widgets.styles'
	];

	public const EVENT_DETAILS_PANEL = 'EventDetailsPanel';
	public const PARTICIPANTS_PANEL = 'ParticipantsPanel';
	public const EMAIL_PANEL = 'EmailPanel';
	public const STATS_PANEL = 'StatsPanel';

	protected IEventLookup $eventLookup;
	protected ?ExistingEventRegistration $event = null;
	private ParticipantsStore $participantsStore;
	private OrganizersStore $organizersStore;
	private IMessageFormatterFactory $messageFormatterFactory;
	private CampaignsCentralUserLookup $centralUserLookup;
	private FrontendModulesFactory $frontendModulesFactory;
	private PermissionChecker $permissionChecker;

	public function __construct(
		IEventLookup $eventLookup,
		ParticipantsStore $participantsStore,
		OrganizersStore $organizersStore,
		IMessageFormatterFactory $messageFormatterFactory,
		CampaignsCentralUserLookup $centralUserLookup,
		FrontendModulesFactory $frontendModulesFactory,
		PermissionChecker $permissionChecker
	) {
		parent::__construct( self::PAGE_NAME );
		$this->eventLookup = $eventLookup;
		$this->participantsStore = $participantsStore;
		$this->organizersStore = $organizersStore;
		$this->messageFormatterFactory = $messageFormatterFactory;
		$this->centralUserLookup = $centralUserLookup;
		$this->frontendModulesFactory = $frontendModulesFactory;
		$this->permissionChecker = $permissionChecker;
	}

	/**
	 * @inheritDoc
	 * @param string|null $par
	 */
	public function execute( $par ): void {
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

		$out->addModules( [ 'ext.campaignEvents.specialPages', 'oojs-ui-widgets' ] );

		$eventID = $this->event->getID();
		$msgFormatter = $this->messageFormatterFactory->getTextFormatter( $language->getCode() );

		try {
			$centralUser = $this->centralUserLookup->newFromAuthority( $this->getAuthority() );
			$organizer = $this->organizersStore->getEventOrganizer( $eventID, $centralUser );
			$isParticipant = $this->participantsStore->userParticipatesInEvent( $eventID, $centralUser, true );
		} catch ( UserNotGlobalException $_ ) {
			$organizer = null;
			$isParticipant = false;
		}
		$isOrganizer = $organizer !== null;
		$userCanEmailParticipants = $userCanViewAggregatedAnswers = false;
		$wikiID = $this->event->getPage()->getWikiId();
		$isLocalWiki = $wikiID === WikiAwareEntity::LOCAL;
		if ( $isLocalWiki ) {
			$userCanEmailParticipants = $this->permissionChecker->userCanEmailParticipants(
				$this->getAuthority(),
				$this->event
			);
			$userCanViewAggregatedAnswers = $this->permissionChecker->userCanViewAggregatedAnswers(
				$this->getAuthority(),
				$this->event
			);
		}
		$out->addJsConfigVars( [
			'wgCampaignEventsEventID' => $eventID,
			'wgCampaignEventsShowEmailTab' => $userCanEmailParticipants,
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

		if ( $isOrganizer && !$isLocalWiki ) {
			$foreignDetailsURL = WikiMap::getForeignURL(
				$wikiID, 'Special:' . self::PAGE_NAME . "/$eventID"
			);

			$messageWidget = new MessageWidget( [
				'type' => 'notice',
				'label' => new HtmlSnippet(
					$this->msg( 'campaignevents-event-details-page-nonlocal' )
						->params( [
							$foreignDetailsURL,
							WikiMap::getWikiName( Utils::getWikiIDString( $wikiID ) )
						] )->parse()
				)
			] );
			$out->addHTML( $messageWidget );
		}

		$main = new IndexLayout( [
			'infusable' => true,
			'expanded' => false,
			'framed' => false,
			'autoFocus' => false,
			'id' => 'ext-campaignevents-eventdetails-tabs'
		] );

		$eventDetailsModule = $this->frontendModulesFactory->newEventDetailsModule( $this->event, $language );
		$tabs = [];
		$tabs[] = $this->createTab(
			self::EVENT_DETAILS_PANEL,
			$msgFormatter->format( MessageValue::new( 'campaignevents-event-details-tab-event-details' ) ),
			$eventDetailsModule->createContent(
				$this->getUser(),
				$this->getAuthority(),
				$isOrganizer,
				$isParticipant,
				$wikiID,
				$out,
				$this->getLinkRenderer()
			)
		);
		$eventParticipantsModule = $this->frontendModulesFactory->newEventDetailsParticipantsModule(
			$language,
			$this->getFullTitle()->getFullURL( [ 'tab' => self::STATS_PANEL ] )
		);
		$tabs[] = $this->createTab(
			self::PARTICIPANTS_PANEL,
			$msgFormatter->format( MessageValue::new( 'campaignevents-event-details-tab-participants' ) ),
			$eventParticipantsModule->createContent(
				$this->event,
				$this->getUser(),
				$this->getAuthority(),
				$isOrganizer,
				$userCanEmailParticipants,
				$isLocalWiki,
				$out
			)
		);
		if ( $userCanEmailParticipants ) {
			$emailModule = $this->frontendModulesFactory->newEmailParticipantsModule();
			$tabs[] = $this->createTab(
				self::EMAIL_PANEL,
				$msgFormatter->format( MessageValue::new( 'campaignevents-event-details-tab-email' ) ),
				$emailModule->createContent( $language )
			);
		}

		if (
			$organizer &&
			$userCanViewAggregatedAnswers &&
			$this->event->isPast() &&
			$this->event->getParticipantQuestions()
		) {
			$totalParticipants = $this->participantsStore->getFullParticipantCountForEvent( $this->event->getID() );
			if ( $totalParticipants > 0 ) {
				$statsModule = $this->frontendModulesFactory->newResponseStatisticsModule( $this->event, $language );
				$pageURL = $this->getPageTitle( (string)$this->event->getID() )
					->getLocalURL( [ 'tab' => $this::STATS_PANEL ] );
				$tabs[] = $this->createTab(
					self::STATS_PANEL,
					$msgFormatter->format( MessageValue::new( 'campaignevents-event-details-tab-stats' ) ),
					$statsModule->createContent( $organizer, $totalParticipants, $this->getContext(), $pageURL )
				);
			}
		}

		$main->addTabPanels( $tabs );
		$selectedTab = $this->getRequest()->getRawVal( 'tab' ) ?? self::EVENT_DETAILS_PANEL;
		$main->setTabPanel( $selectedTab );
		$footer = ( new Tag() )->addClasses( [ 'ext-campaignevents-eventdetails-footer' ] )->appendContent(
			new HtmlSnippet( $this->msg( 'campaignevents-event-details-footer' )->parse() )
		);

		$out->addHTML( $main );
		$out->addHTML( $footer );
	}

	/**
	 * @param string $errorMsg
	 * @param mixed ...$msgParams
	 * @suppress PhanPluginUnknownArrayMethodParamType,UnusedSuppression https://github.com/phan/phan/issues/4927
	 */
	protected function outputErrorBox( string $errorMsg, ...$msgParams ): void {
		// phan-suppress-previous-line PhanPluginUnknownArrayMethodParamType
		$this->getOutput()->addModuleStyles( [
			'mediawiki.codex.messagebox.styles',
		] );
		$this->getOutput()->addHTML( Html::errorBox(
			$this->msg( $errorMsg )->params( ...$msgParams )->parseAsBlock()
		) );
	}

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

	/**
	 * @inheritDoc
	 */
	public function doesWrites(): bool {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	protected function getGroupName(): string {
		return 'campaignevents';
	}
}
