<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Special;

use Html;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventNotFoundException;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\FrontendModules\EventDetailsModule;
use MediaWiki\Extension\CampaignEvents\FrontendModules\EventDetailsParticipantsModule;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWUserProxy;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageURLResolver;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use OOUI\ButtonWidget;
use OOUI\Tag;
use SpecialPage;
use Wikimedia\Message\IMessageFormatterFactory;
use Wikimedia\Message\MessageValue;

class SpecialEventDetails extends SpecialPage {
	public const PAGE_NAME = 'EventDetails';
	private const PARTICIPANTS_LIMIT = 20;
	private const MODULE_STYLES = [ 'oojs-ui.styles.icons-movement', 'ext.campaignEvents.specialeventdetails.styles' ];

	/** @var IEventLookup */
	protected $eventLookup;
	/** @var ExistingEventRegistration|null */
	protected $event;
	/** @var ParticipantsStore */
	private $participantsStore;
	/** @var OrganizersStore */
	private $organizersStore;
	/** @var PageURLResolver */
	private $pageURLResolver;
	/** @var CampaignsCentralUserLookup */
	private $centralUserLookup;
	/** @var IMessageFormatterFactory */
	private $messageFormatterFactory;

	/**
	 * @param IEventLookup $eventLookup
	 * @param ParticipantsStore $participantsStore
	 * @param OrganizersStore $organizersStore
	 * @param PageURLResolver $pageURLResolver
	 * @param CampaignsCentralUserLookup $centralUserLookup
	 * @param IMessageFormatterFactory $messageFormatterFactory
	 */
	public function __construct(
		IEventLookup $eventLookup,
		ParticipantsStore $participantsStore,
		OrganizersStore $organizersStore,
		PageURLResolver $pageURLResolver,
		CampaignsCentralUserLookup $centralUserLookup,
		IMessageFormatterFactory $messageFormatterFactory
	) {
		parent::__construct( self::PAGE_NAME );
		$this->eventLookup = $eventLookup;
		$this->participantsStore = $participantsStore;
		$this->organizersStore = $organizersStore;
		$this->pageURLResolver = $pageURLResolver;
		$this->centralUserLookup = $centralUserLookup;
		$this->messageFormatterFactory = $messageFormatterFactory;
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
		$viewingUser = $this->getUser();

		$out->enableOOUI();
		$out->addModuleStyles(
			array_merge(
				self::MODULE_STYLES,
				EventDetailsModule::MODULE_STYLES,
				EventDetailsParticipantsModule::MODULE_STYLES
			)
		);

		$this->addHelpLink( 'Extension:CampaignEvents' );

		$out->addModules( [ 'ext.campaignEvents.specialeventdetails' ] );

		$eventID = $this->event->getID();
		$msgFormatter = $this->messageFormatterFactory->getTextFormatter( $language->getCode() );

		$userProxy = new MWUserProxy( $viewingUser, $this->getAuthority() );
		$isOrganizer = $this->organizersStore->isEventOrganizer( $eventID, $userProxy );
		$isParticipant = $this->participantsStore->userParticipatesToEvent( $eventID, $userProxy );

		$participants = $this->participantsStore->getEventParticipants( $eventID, self::PARTICIPANTS_LIMIT );
		$totalParticipants = $this->participantsStore->getParticipantCountForEvent( $eventID );
		$organizersCount = $this->organizersStore->getOrganizerCountForEvent( $eventID );

		$out->addJsConfigVars( [
			'wgCampaignEventsEventID' => $eventID,
			'wgCampaignEventsEventDetailsParticipantsTotal' => $totalParticipants,
		] );
		$out->setPageTitle(
			$msgFormatter->format(
				MessageValue::new( 'campaignevents-event-details-event' )
					->params( $this->event->getName() )
			)
		);

		$backLink = ( new ButtonWidget( [
			'framed' => false,
			'flags' => [ 'progressive' ],
			'label' => $msgFormatter->format(
				MessageValue::new( 'campaignevents-back-to-your-events' )
			),
			'href' => SpecialPage::getTitleFor(
					SpecialMyEvents::PAGE_NAME
				)->getLocalURL(),
			'icon' => 'arrowPrevious'
		] ) );

		$out->addHTML( $backLink );

		$main = new Tag( 'div' );
		$main->appendContent(
			( new EventDetailsParticipantsModule() )->createContent(
				$language,
				$this->event,
				$userProxy,
				$participants,
				$totalParticipants,
				$msgFormatter,
				$isOrganizer,
				$this->centralUserLookup
			)
		);

		$main->appendContent(
			( new EventDetailsModule() )->createContent(
				$language,
				$this->event,
				$userProxy,
				$msgFormatter,
				$isOrganizer,
				$isParticipant,
				$organizersCount,
				$this->pageURLResolver
			)
		);
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
}
