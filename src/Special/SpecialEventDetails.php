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
use MediaWiki\Extension\CampaignEvents\MWEntity\MWAuthorityProxy;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageURLResolver;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserLinker;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Participants\UnregisterParticipantCommand;
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
	/** @var UserLinker */
	private $userLinker;
	/** @var IMessageFormatterFactory */
	private $messageFormatterFactory;
	/** @var CampaignsCentralUserLookup */
	private $centralUserLookup;

	/**
	 * @param IEventLookup $eventLookup
	 * @param ParticipantsStore $participantsStore
	 * @param OrganizersStore $organizersStore
	 * @param PageURLResolver $pageURLResolver
	 * @param IMessageFormatterFactory $messageFormatterFactory
	 * @param CampaignsCentralUserLookup $centralUserLookup
	 * @param UserLinker $userLinker
	 */
	public function __construct(
		IEventLookup $eventLookup,
		ParticipantsStore $participantsStore,
		OrganizersStore $organizersStore,
		PageURLResolver $pageURLResolver,
		IMessageFormatterFactory $messageFormatterFactory,
		CampaignsCentralUserLookup $centralUserLookup,
		UserLinker $userLinker
	) {
		parent::__construct( self::PAGE_NAME );
		$this->eventLookup = $eventLookup;
		$this->participantsStore = $participantsStore;
		$this->organizersStore = $organizersStore;
		$this->pageURLResolver = $pageURLResolver;
		$this->userLinker = $userLinker;
		$this->messageFormatterFactory = $messageFormatterFactory;
		$this->centralUserLookup = $centralUserLookup;
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
				EventDetailsParticipantsModule::MODULE_STYLES
			)
		);

		$this->addHelpLink( 'Extension:CampaignEvents' );

		$out->addModules( [ 'ext.campaignEvents.specialeventdetails' ] );

		$eventID = $this->event->getID();
		$msgFormatter = $this->messageFormatterFactory->getTextFormatter( $language->getCode() );

		try {
			$centralUser = $this->centralUserLookup->newFromAuthority( new MWAuthorityProxy( $this->getAuthority() ) );
			$isOrganizer = $this->organizersStore->isEventOrganizer( $eventID, $centralUser );
			$isParticipant = $this->participantsStore->userParticipatesToEvent( $eventID, $centralUser );
		} catch ( UserNotGlobalException $_ ) {
			$isOrganizer = $isParticipant = false;
		}

		$participants = $this->participantsStore->getEventParticipants( $eventID, self::PARTICIPANTS_LIMIT );
		$totalParticipants = $this->participantsStore->getParticipantCountForEvent( $eventID );
		$organizersCount = $this->organizersStore->getOrganizerCountForEvent( $eventID );

		if ( $isOrganizer ) {
			$canRemoveParticipants = UnregisterParticipantCommand::checkIsUnregistrationAllowed( $this->event ) ===
				UnregisterParticipantCommand::CAN_UNREGISTER;
		} else {
			$canRemoveParticipants = false;
		}

		$out->addJsConfigVars( [
			'wgCampaignEventsEventID' => $eventID,
			// TO DO This may change when we add the feature to send messages
			'wgCampaignEventsShowParticipantCheckboxes' => $canRemoveParticipants,
			'wgCampaignEventsEventDetailsParticipantsTotal' => $totalParticipants,
			'wgCampaignEventsLastParticipantID' => !$participants ? null : end( $participants )->getParticipantID(),
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

		$main = ( new Tag( 'div' ) )
			->addClasses( [ 'ext-campaignevents-eventdetails-panels' ] );
		$main->appendContent(
			( new EventDetailsParticipantsModule() )->createContent(
				$language,
				$this->getUser(),
				$this->userLinker,
				$participants,
				$totalParticipants,
				$msgFormatter,
				$canRemoveParticipants
			)
		);

		$main->appendContent(
			( new EventDetailsModule() )->createContent(
				$language,
				$this->event,
				$this->getUser(),
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
