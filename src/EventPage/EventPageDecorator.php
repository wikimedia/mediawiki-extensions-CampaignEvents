<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\EventPage;

use Html;
use Language;
use Linker;
use LogicException;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventNotFoundException;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUserNotFoundException;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsAuthority;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsPage;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWAuthorityProxy;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWPageProxy;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserLinker;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Participants\Participant;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Participants\RegisterParticipantCommand;
use MediaWiki\Extension\CampaignEvents\Participants\UnregisterParticipantCommand;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\Special\SpecialCancelEventRegistration;
use MediaWiki\Extension\CampaignEvents\Special\SpecialEnableEventRegistration;
use MediaWiki\Extension\CampaignEvents\Special\SpecialEventDetails;
use MediaWiki\Extension\CampaignEvents\Special\SpecialRegisterForEvent;
use MediaWiki\Extension\CampaignEvents\Time\EventTimeFormatter;
use MediaWiki\Extension\CampaignEvents\Utils;
use MediaWiki\Extension\CampaignEvents\Widget\TextWithIconWidget;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Permissions\Authority;
use MediaWiki\User\UserIdentity;
use MessageLocalizer;
use OOUI\ButtonWidget;
use OOUI\Element;
use OOUI\HorizontalLayout;
use OOUI\HtmlSnippet;
use OOUI\IconWidget;
use OOUI\MessageWidget;
use OOUI\PanelLayout;
use OOUI\Tag;
use OutputPage;
use SpecialPage;
use TitleFormatter;
use UnexpectedValueException;
use Wikimedia\Message\IMessageFormatterFactory;
use Wikimedia\Message\ITextFormatter;
use Wikimedia\Message\MessageValue;

/**
 * This service is used to adds some widgets to the event page, like the registration header.
 */
class EventPageDecorator {
	public const SERVICE_NAME = 'CampaignEventsEventPageDecorator';

	private const ADDRESS_MAX_LENGTH = 30;
	// See T304719#7909758 for how these numbers were chosen
	private const ORGANIZERS_LIMIT = 4;
	private const PARTICIPANTS_LIMIT = 10;

	// Constants for the different statuses of a user wrt a given event registration
	private const USER_STATUS_BLOCKED = 1;
	private const USER_STATUS_ORGANIZER = 2;
	private const USER_STATUS_PARTICIPANT_CAN_UNREGISTER = 3;
	private const USER_STATUS_CAN_REGISTER = 4;
	private const USER_STATUS_CANNOT_REGISTER_ENDED = 5;
	private const USER_STATUS_CANNOT_REGISTER_CLOSED = 6;

	/** @var IEventLookup */
	private $eventLookup;
	/** @var ParticipantsStore */
	private $participantsStore;
	/** @var OrganizersStore */
	private $organizersStore;
	/** @var PermissionChecker */
	private $permissionChecker;
	/** @var IMessageFormatterFactory */
	private $messageFormatterFactory;
	/** @var LinkRenderer */
	private $linkRenderer;
	/** @var TitleFormatter */
	private $titleFormatter;
	/** @var CampaignsCentralUserLookup */
	private $centralUserLookup;
	/** @var UserLinker */
	private $userLinker;
	/** @var EventTimeFormatter */
	private EventTimeFormatter $eventTimeFormatter;

	/**
	 * @param IEventLookup $eventLookup
	 * @param ParticipantsStore $participantsStore
	 * @param OrganizersStore $organizersStore
	 * @param PermissionChecker $permissionChecker
	 * @param IMessageFormatterFactory $messageFormatterFactory
	 * @param LinkRenderer $linkRenderer
	 * @param TitleFormatter $titleFormatter
	 * @param CampaignsCentralUserLookup $centralUserLookup
	 * @param UserLinker $userLinker
	 * @param EventTimeFormatter $eventTimeFormatter
	 */
	public function __construct(
		IEventLookup $eventLookup,
		ParticipantsStore $participantsStore,
		OrganizersStore $organizersStore,
		PermissionChecker $permissionChecker,
		IMessageFormatterFactory $messageFormatterFactory,
		LinkRenderer $linkRenderer,
		TitleFormatter $titleFormatter,
		CampaignsCentralUserLookup $centralUserLookup,
		UserLinker $userLinker,
		EventTimeFormatter $eventTimeFormatter
	) {
		$this->eventLookup = $eventLookup;
		$this->participantsStore = $participantsStore;
		$this->organizersStore = $organizersStore;
		$this->permissionChecker = $permissionChecker;
		$this->messageFormatterFactory = $messageFormatterFactory;
		$this->linkRenderer = $linkRenderer;
		$this->titleFormatter = $titleFormatter;
		$this->centralUserLookup = $centralUserLookup;
		$this->userLinker = $userLinker;
		$this->eventTimeFormatter = $eventTimeFormatter;
	}

	/**
	 * This is the main entry point for this class. It adds all the necessary HTML (registration header, popup etc.)
	 * to the given OutputPage, as well as loading some JS/CSS resources.
	 *
	 * @param OutputPage $out
	 * @param ProperPageIdentity $page
	 * @param Language $language
	 * @param UserIdentity $viewingUser
	 * @param Authority $viewingAuthority
	 */
	public function decoratePage(
		OutputPage $out,
		ProperPageIdentity $page,
		Language $language,
		UserIdentity $viewingUser,
		Authority $viewingAuthority
	): void {
		$campaignsPage = new MWPageProxy( $page, $this->titleFormatter->getPrefixedText( $page ) );
		try {
			$registration = $this->eventLookup->getEventByPage( $campaignsPage );
		} catch ( EventNotFoundException $_ ) {
			$registration = null;
		}

		if ( $registration && $registration->getDeletionTimestamp() !== null ) {
			return;
		}

		$msgFormatter = $this->messageFormatterFactory->getTextFormatter( $language->getCode() );
		$authority = new MWAuthorityProxy( $viewingAuthority );
		if ( $registration ) {
			$this->addRegistrationHeader(
				$registration, $out, $viewingUser, $authority, $msgFormatter, $language );
		} else {
			$this->maybeAddEnableRegistrationHeader( $out, $msgFormatter, $language, $authority, $campaignsPage );
		}
	}

	/**
	 * @param OutputPage $out
	 * @param ITextFormatter $msgFormatter
	 * @param Language $language
	 * @param ICampaignsAuthority $authority
	 * @param ICampaignsPage $eventPage
	 */
	private function maybeAddEnableRegistrationHeader(
		OutputPage $out,
		ITextFormatter $msgFormatter,
		Language $language,
		ICampaignsAuthority $authority,
		ICampaignsPage $eventPage
	): void {
		if ( !$this->permissionChecker->userCanEnableRegistration( $authority, $eventPage ) ) {
			return;
		}

		$out->enableOOUI();
		$out->addModuleStyles( [
			'ext.campaignEvents.eventpage.styles',
			'oojs-ui.styles.icons-editing-advanced',
		] );
		$out->addModules( [ 'ext.campaignEvents.eventpage' ] );
		// We pass this to the client to avoid hardcoding the name of the page field in JS. Apparently we can't use
		// a RL callback for this because it doesn't provide the current page.
		$enableRegistrationURL = SpecialPage::getTitleFor( SpecialEnableEventRegistration::PAGE_NAME )->getLocalURL( [
			SpecialEnableEventRegistration::PAGE_FIELD_NAME => $eventPage->getPrefixedText()
		] );
		$out->addJsConfigVars( [ 'wgCampaignEventsEnableRegistrationURL' => $enableRegistrationURL ] );
		$out->addHTML( $this->getEnableRegistrationHeader( $out, $msgFormatter, $language, $enableRegistrationURL ) );
	}

	/**
	 * @param MessageLocalizer $messageLocalizer
	 * @param ITextFormatter $msgFormatter
	 * @param Language $language
	 * @param string $enableRegistrationURL
	 * @return Tag
	 */
	private function getEnableRegistrationHeader(
		MessageLocalizer $messageLocalizer,
		ITextFormatter $msgFormatter,
		Language $language,
		string $enableRegistrationURL
	): Tag {
		$organizerText = ( new Tag( 'div' ) )->appendContent(
			$msgFormatter->format( MessageValue::new( 'campaignevents-eventpage-enableheader-organizer' ) )
		)->setAttributes( [ 'class' => 'ext-campaignevents-eventpage-organizer-label' ] );

		// Wrap it in a span for use inside a flex container, since the message contains HTML.
		// XXX Can't use $msgFormatter here because the message contains HTML, see T260689
		$infoMsg = ( new Tag( 'span' ) )->appendContent(
			new HtmlSnippet( $messageLocalizer->msg( 'campaignevents-eventpage-enableheader-eventpage-desc' )->parse() )
		);
		$infoText = ( new Tag( 'div' ) )->appendContent(
			new IconWidget( [ 'icon' => 'calendar', 'classes' => [ 'ext-campaignevents-eventpage-icon' ] ] ),
			$infoMsg
		)->setAttributes( [ 'class' => 'ext-campaignevents-eventpage-enableheader-message' ] );
		$infoElement = ( new Tag( 'div' ) )->appendContent( $organizerText, $infoText );

		$enableRegistrationBtn = new ButtonWidget( [
			'flags' => [ 'primary', 'progressive' ],
			'label' => $msgFormatter->format(
				MessageValue::new( 'campaignevents-eventpage-enableheader-button-label' )
			),
			'classes' => [ 'ext-campaignevents-eventpage-enable-registration-btn' ],
			'href' => $enableRegistrationURL,
		] );

		$layout = new PanelLayout( [
			'content' => [ $infoElement, $enableRegistrationBtn ],
			'padded' => true,
			'framed' => true,
			'expanded' => false,
			'classes' => [ 'ext-campaignevents-eventpage-enableheader' ],
		] );

		$layout->setAttributes( [
			// Set the lang/dir explicitly, otherwise it will use that of the site/page language,
			// not that of the interface.
			'dir' => $language->getDir(),
			'lang' => $language->getHtmlCode()
		] );
		return $layout;
	}

	/**
	 * @param ExistingEventRegistration $registration
	 * @param OutputPage $out
	 * @param UserIdentity $viewingUser
	 * @param ICampaignsAuthority $authority
	 * @param ITextFormatter $msgFormatter
	 * @param Language $language
	 */
	private function addRegistrationHeader(
		ExistingEventRegistration $registration,
		OutputPage $out,
		UserIdentity $viewingUser,
		ICampaignsAuthority $authority,
		ITextFormatter $msgFormatter,
		Language $language
	): void {
		$out->setPreventClickjacking( true );
		$out->enableOOUI();
		$out->addModuleStyles( array_merge(
			[
				'ext.campaignEvents.eventpage.styles',
				'oojs-ui.styles.icons-location',
				'oojs-ui.styles.icons-interactions',
				'oojs-ui.styles.icons-moderation',
				'oojs-ui.styles.icons-user',
				'oojs-ui.styles.icons-editing-core',
				'oojs-ui.styles.icons-alerts',
			],
			UserLinker::MODULE_STYLES
		) );
		$out->addModules( [ 'ext.campaignEvents.eventpage' ] );
		$out->addJsConfigVars( [
			'wgCampaignEventsEventID' => $registration->getID()
		] );

		$userStatus = $this->getUserStatus( $registration, $authority );
		try {
			$centralUser = $this->centralUserLookup->newFromAuthority( $authority );
		} catch ( UserNotGlobalException $_ ) {
			$centralUser = null;
		}
		$out->addHTML(
			$this->getHeaderElement( $registration, $msgFormatter, $language, $viewingUser, $userStatus, $out )
		);
		$out->addHTML(
			$this->getDetailsDialogContent(
				$registration,
				$msgFormatter,
				$language,
				$viewingUser,
				$userStatus,
				$centralUser,
				$authority,
				$out )
		);
	}

	/**
	 * Returns the header element.
	 *
	 * @param ExistingEventRegistration $registration
	 * @param ITextFormatter $msgFormatter
	 * @param Language $language
	 * @param UserIdentity $viewingUser
	 * @param int $userStatus One of the self::USER_STATUS_* constants
	 * @param OutputPage $out
	 * @return Tag
	 */
	private function getHeaderElement(
		ExistingEventRegistration $registration,
		ITextFormatter $msgFormatter,
		Language $language,
		UserIdentity $viewingUser,
		int $userStatus,
		OutputPage $out
	): Tag {
		$eventID = $registration->getID();
		$items = [];

		$meetingType = $registration->getMeetingType();
		if ( $meetingType === EventRegistration::MEETING_TYPE_ONLINE_AND_IN_PERSON ) {
			$locationContent = $msgFormatter->format(
				MessageValue::new( 'campaignevents-eventpage-header-type-online-and-in-person' )
			);
		} elseif ( $meetingType & EventRegistration::MEETING_TYPE_ONLINE ) {
			$locationContent = $msgFormatter->format(
				MessageValue::new( 'campaignevents-eventpage-header-type-online' )
			);
		} else {
			// In-person event
			$address = $registration->getMeetingAddress();
			if ( $address !== null ) {
				$locationContent = new Tag( 'div' );
				$locationContent->setAttributes( [
					'dir' => Utils::guessStringDirection( $address )
				] );
				$locationContent->addClasses( [ 'ext-campaignevents-eventpage-header-address' ] );
				$locationContent->appendContent( $language->truncateForVisual( $address, self::ADDRESS_MAX_LENGTH ) );
			} else {
				$locationContent = $msgFormatter->format(
					MessageValue::new( 'campaignevents-eventpage-header-type-in-person' )
				);
			}
		}
		$items[] = new TextWithIconWidget( [
			'icon' => 'mapPin',
			'content' => $locationContent,
			'label' => $msgFormatter->format( MessageValue::new( 'campaignevents-eventpage-header-location-label' ) ),
			'icon_classes' => [ 'ext-campaignevents-eventpage-icon' ],
		] );

		$formattedStart = $this->eventTimeFormatter->formatStart( $registration, $language, $viewingUser );
		$formattedEnd = $this->eventTimeFormatter->formatEnd( $registration, $language, $viewingUser );
		$datesMsg = $msgFormatter->format(
			MessageValue::new( 'campaignevents-eventpage-header-dates' )->params(
				$formattedStart->getTimeAndDate(),
				$formattedStart->getDate(),
				$formattedStart->getTime(),
				$formattedEnd->getTimeAndDate(),
				$formattedEnd->getDate(),
				$formattedEnd->getTime()
			)
		);
		$formattedTimezone = $this->eventTimeFormatter->formatTimezone( $registration, $viewingUser );
		// XXX Can't use $msgFormatter due to parse()
		$timezoneMsg = $out->msg( 'campaignevents-eventpage-header-timezone' )
			->params( $formattedTimezone )
			->parse();
		$items[] = new TextWithIconWidget( [
			'icon' => 'clock',
			'content' => [
				$datesMsg,
				( new Tag( 'div' ) )->appendContent( new HtmlSnippet( $timezoneMsg ) )
			],
			'label' => $msgFormatter->format( MessageValue::new( 'campaignevents-eventpage-header-dates-label' ) ),
			'icon_classes' => [ 'ext-campaignevents-eventpage-icon' ],
		] );

		$items[] = new TextWithIconWidget( [
			'icon' => 'userGroup',
			'content' => $msgFormatter->format(
				MessageValue::new( 'campaignevents-eventpage-header-participants' )
					->numParams( $this->participantsStore->getFullParticipantCountForEvent( $eventID ) )
			),
			'label' => $msgFormatter->format(
				MessageValue::new( 'campaignevents-eventpage-header-participants-label' )
			),
			'icon_classes' => [ 'ext-campaignevents-eventpage-icon' ],
		] );

		$btnContainer = ( new Tag( 'div' ) )
			->addClasses( [ 'ext-campaignevents-eventpage-header-buttons' ] );
		$btnContainer->appendContent( new ButtonWidget( [
			'framed' => false,
			'flags' => [ 'progressive' ],
			'label' => $msgFormatter->format( MessageValue::new( 'campaignevents-eventpage-header-details' ) ),
			'classes' => [ 'ext-campaignevents-event-details-btn' ],
			'href' => SpecialPage::getTitleFor( SpecialEventDetails::PAGE_NAME, (string)$eventID )->getLocalURL(),
		] ) );

		$actionElement = $this->getActionElement( $eventID, $msgFormatter, $userStatus );
		if ( $actionElement ) {
			$btnContainer->appendContent( $actionElement );
		}

		$items[] = $btnContainer;

		$layout = new PanelLayout( [
			'content' => $items,
			'padded' => true,
			'framed' => true,
			'expanded' => false,
			'classes' => [ 'ext-campaignevents-eventpage-header' ],
		] );

		$layout->setAttributes( [
			// Set the lang/dir explicitly, otherwise it will use that of the site/page language,
			// not that of the interface.
			'dir' => $language->getDir(),
			'lang' => $language->getHtmlCode()
		] );

		return $layout;
	}

	/**
	 * Returns the content of the "more details" dialog. Unfortunately, we have to build it here rather then on the
	 * client side, for the following reasons:
	 * - There's no way to format dates according to the user preferences (T21992)
	 * - There's no easy way to get the directionality of a language (T181684)
	 * - Other utilities are missing (e.g., generating user links)
	 * - Secondarily, no need to make 3 API requests and worry about them failing.
	 *
	 * @param ExistingEventRegistration $registration
	 * @param ITextFormatter $msgFormatter
	 * @param Language $language
	 * @param UserIdentity $viewingUser
	 * @param int $userStatus One of the self::USER_STATUS_* constants
	 * @param CentralUser|null $centralUser
	 * @param ICampaignsAuthority $authority
	 * @param OutputPage $out
	 * @return string
	 */
	private function getDetailsDialogContent(
		ExistingEventRegistration $registration,
		ITextFormatter $msgFormatter,
		Language $language,
		UserIdentity $viewingUser,
		int $userStatus,
		?CentralUser $centralUser,
		ICampaignsAuthority $authority,
		OutputPage $out
	): string {
		$eventID = $registration->getID();

		$organizersCount = $this->organizersStore->getOrganizerCountForEvent( $eventID );
		$partialOrganizers = $this->organizersStore->getEventOrganizers( $eventID, self::ORGANIZERS_LIMIT );
		$langCode = $msgFormatter->getLangCode();
		$organizerElements = [];
		foreach ( $partialOrganizers as $organizer ) {
			$organizerElements[] = $this->userLinker->generateUserLinkWithFallback( $organizer->getUser(), $langCode );
		}
		// XXX We need to use OutputPage here because there's no supported way to change the format of
		// MessageFormatterFactory...
		$organizersStr = $out->msg( 'campaignevents-eventpage-dialog-organizers' )
			->rawParams( $language->commaList( $organizerElements ) )
			->escaped();
		if ( count( $partialOrganizers ) < $organizersCount ) {
			$organizersStr .= Html::rawElement(
				'p',
				[],
				$this->linkRenderer->makeKnownLink(
					SpecialPage::getTitleFor( SpecialEventDetails::PAGE_NAME, (string)$eventID ),
					$msgFormatter->format(
						MessageValue::new( 'campaignevents-eventpage-dialog-organizers-view-all' )
					)
				)
			);
		}
		$organizersAndDetails = Html::rawElement( 'div', [], $organizersStr );
		$formattedStart = $this->eventTimeFormatter->formatStart( $registration, $language, $viewingUser );
		$formattedEnd = $this->eventTimeFormatter->formatEnd( $registration, $language, $viewingUser );
		$datesMsg = $msgFormatter->format(
			MessageValue::new( 'campaignevents-eventpage-dialog-dates' )->params(
				$formattedStart->getTimeAndDate(),
				$formattedStart->getDate(),
				$formattedStart->getTime(),
				$formattedEnd->getTimeAndDate(),
				$formattedEnd->getDate(),
				$formattedEnd->getTime()
			)
		);
		$formattedTimezone = $this->eventTimeFormatter->formatTimezone( $registration, $viewingUser );
		// XXX Can't use $msgFormatter due to parse()
		$timezoneMsg = $out->msg( 'campaignevents-eventpage-dialog-timezone' )
			->params( $formattedTimezone )
			->parse();
		$datesWidget = new EventDetailsWidget( [
			'icon' => 'clock',
			'label' => $msgFormatter->format(
				MessageValue::new( 'campaignevents-eventpage-dialog-dates-label' )
			),
			'content' => [
				$datesMsg,
				( new Tag( 'div' ) )->appendContent( new HtmlSnippet( $timezoneMsg ) )
			],
		] );
		$organizersAndDetails .= Html::rawElement( 'div', [], $datesWidget );

		$locationElements = [];
		$onlineLocationElements = [];
		if ( $registration->getMeetingType() & EventRegistration::MEETING_TYPE_ONLINE ) {
			$onlineLocationElements[] = ( new Tag( 'span' ) )
				->addClasses( [ 'ext-campaignevents-eventpage-detailsdialog-location-header' ] )
				->appendContent(
					$msgFormatter->format(
						MessageValue::new( 'campaignevents-eventpage-dialog-online-label' )
					) );
			$meetingURL = $registration->getMeetingURL();
			if ( $meetingURL === null ) {
				$linkContent = $msgFormatter->format(
					MessageValue::new( 'campaignevents-eventpage-dialog-link-not-available' )
						->numParams( $organizersCount )
				);
			} elseif (
				$userStatus === self::USER_STATUS_ORGANIZER ||
				$userStatus === self::USER_STATUS_PARTICIPANT_CAN_UNREGISTER
			) {
				$linkIcon = new IconWidget( [ 'icon' => 'link' ] );
				$linkContent = new HtmlSnippet(
					$linkIcon . '&nbsp;' . Linker::makeExternalLink( $meetingURL, $meetingURL )
				);
			} elseif ( $userStatus === self::USER_STATUS_CAN_REGISTER ) {
				$linkContent = $msgFormatter->format(
					MessageValue::new( 'campaignevents-eventpage-dialog-link-register' )
				);
			} elseif (
				$userStatus === self::USER_STATUS_BLOCKED ||
				$userStatus === self::USER_STATUS_CANNOT_REGISTER_CLOSED ||
				$userStatus === self::USER_STATUS_CANNOT_REGISTER_ENDED
			) {
				$linkContent = '';
			} else {
				throw new LogicException( "Unexpected user status $userStatus" );
			}
			$onlineLocationElements[] = ( new Tag( 'p' ) )->appendContent( $linkContent );
		}
		if ( $registration->getMeetingType() & EventRegistration::MEETING_TYPE_IN_PERSON ) {
			$rawAddress = $registration->getMeetingAddress();
			$rawCountry = $registration->getMeetingCountry();
			$addressElement = new Tag( 'p' );
			$addressElement->addClasses( [ 'ext-campaignevents-eventpage-details-address' ] );
			if ( $rawAddress || $rawCountry ) {
				$address = $rawAddress . "\n" . $rawCountry;
				$addressElement->setAttributes( [
					'dir' => Utils::guessStringDirection( $address )
				] );
				$addressElement->appendContent( $address );
			} else {
				$addressElement->appendContent( $msgFormatter->format(
					MessageValue::new( 'campaignevents-eventpage-dialog-venue-not-available' )
						->numParams( $organizersCount )
				) );
			}
			if ( $onlineLocationElements ) {
				$inPersonLabel = ( new Tag( 'span' ) )
					->addClasses( [ 'ext-campaignevents-eventpage-detailsdialog-location-header' ] )
					->appendContent( $msgFormatter->format(
						MessageValue::new( 'campaignevents-eventpage-dialog-in-person-label' )
					) );
				$locationElements[] = $inPersonLabel;
				$locationElements[] = $addressElement;
				$locationElements = array_merge( $locationElements, $onlineLocationElements );
			} else {
				$locationElements[] = $addressElement;
			}
		} else {
			$locationElements = array_merge( $locationElements, $onlineLocationElements );
		}
		$locationWidget = new EventDetailsWidget( [
			'icon' => 'mapPin',
			'label' => $msgFormatter->format(
				MessageValue::new( 'campaignevents-eventpage-dialog-location-label' )
			),
			'content' => $locationElements
		] );
		$organizersAndDetails .= Html::rawElement( 'div', [], $locationWidget );

		$chatURL = $registration->getChatURL();
		if ( $chatURL === null ) {
			$chatURLContent = $msgFormatter->format(
				MessageValue::new( 'campaignevents-eventpage-dialog-chat-not-available' )
			);
		} elseif (
			$userStatus === self::USER_STATUS_ORGANIZER ||
			$userStatus === self::USER_STATUS_PARTICIPANT_CAN_UNREGISTER
		) {
			$chatURLContent = new HtmlSnippet( Linker::makeExternalLink( $chatURL, $chatURL ) );
		} elseif ( $userStatus === self::USER_STATUS_CAN_REGISTER ) {
			$chatURLContent = $msgFormatter->format(
				MessageValue::new( 'campaignevents-eventpage-dialog-chat-register' )
			);
		} elseif (
			$userStatus === self::USER_STATUS_BLOCKED ||
			$userStatus === self::USER_STATUS_CANNOT_REGISTER_CLOSED ||
			$userStatus === self::USER_STATUS_CANNOT_REGISTER_ENDED
		) {
			$chatURLContent = '';
		} else {
			throw new LogicException( "Unexpected user status $userStatus" );
		}
		$chatURLWidget = new EventDetailsWidget( [
			'icon' => 'speechBubbles',
			'label' => $msgFormatter->format(
				MessageValue::new( 'campaignevents-eventpage-dialog-chat-label' )
			),
			'content' => $chatURLContent
		] );
		$organizersAndDetails .= Html::rawElement( 'div', [], $chatURLWidget );

		$organizersAndDetailsContainer = Html::rawElement(
			'div',
			[ 'class' => 'ext-campaignevents-detailsdialog-organizeranddetails-container' ],
			$organizersAndDetails
		);

		$showPrivateParticipants = $this->permissionChecker->userCanViewPrivateParticipants( $authority, $eventID );
		$participantsCount = $this->participantsStore->getFullParticipantCountForEvent( $eventID );
		$privateCount = $this->participantsStore->getPrivateParticipantCountForEvent( $eventID );
		$participantsList = $this->getParticipantRows(
			$eventID,
			$language,
			$centralUser,
			$msgFormatter,
			$showPrivateParticipants
		);
		$participantsFooter = '';
		if ( self::PARTICIPANTS_LIMIT < $participantsCount ) {
			$participantsFooter = $this->getParticipantFooter( $eventID, $msgFormatter );
		}
		$participantsWidget = new EventDetailsWidget( [
			'icon' => 'userGroup',
			'label' => $msgFormatter->format(
				MessageValue::new( 'campaignevents-eventpage-dialog-participants' )
					->numParams( $participantsCount )
			),
			'content' => [ $participantsList ?: '', $participantsFooter ],
			'classes' => [ 'ext-campaignevents-detailsdialog-participants' ]
		] );
		$privateCountWidget = '';

		if ( $privateCount > 0 ) {
			$privateCountWidget = new Tag();
			$privateCountWidget->addClasses( [
				'ext-campaignevents-detailsdialog-private-participants'
			] );
			$privateCountIcon = new IconWidget( [
				'icon' => 'lock'
				] );
			$privateCountText = new Tag( 'span' );
			$privateCountText->appendContent(
				$msgFormatter->format(
					MessageValue::new( 'campaignevents-eventpage-dialog-participants-private' )
					->numParams( $privateCount )
				)
			);

			$privateCountWidget->appendContent( [ $privateCountIcon,$privateCountText ] );
		}
		$participantsContainer = Html::rawElement(
			'div',
			[ 'class' => 'ext-campaignevents-detailsdialog-participants-container' ],
			$participantsWidget . $privateCountWidget
		);
		$dialogContent = Html::element( 'h2', [], $registration->getName() );
		$dialogContent .= Html::rawElement(
			'div',
			[ 'class' => 'ext-campaignevents-detailsdialog-body-container' ],
			$organizersAndDetailsContainer . $participantsContainer
		);

		return Html::rawElement( 'div', [ 'id' => 'ext-campaignEvents-detailsDialog-content' ], $dialogContent );
	}

	/**
	 * @param int $eventID
	 * @param Language $language
	 * @param CentralUser|null $centralUser
	 * @param ITextFormatter $msgFormatter
	 * @param bool $showPrivateParticipants
	 *
	 * @return Tag|null
	 */
	private function getParticipantRows(
		int $eventID,
		Language $language,
		?CentralUser $centralUser,
		ITextFormatter $msgFormatter,
		bool $showPrivateParticipants
	): ?Tag {
		$curUserParticipant = null;
		$participantsList = new Tag( 'ul' );
		if ( $centralUser ) {
			$curUserParticipant = $this->participantsStore->getEventParticipant( $eventID, $centralUser, true );
		}
		$partialParticipants = $this->participantsStore->getEventParticipants(
			$eventID,
			$curUserParticipant ?
				self::PARTICIPANTS_LIMIT - 1 :
				self::PARTICIPANTS_LIMIT,
			null,
			null,
			$showPrivateParticipants,
			isset( $centralUser ) ? $centralUser->getCentralID() : null );

		if ( !$curUserParticipant && !$partialParticipants ) {
			return null;
		}

		if ( $curUserParticipant ) {
			$participantsList->appendContent(
				$this->getParticipantRow(
					$curUserParticipant,
					$language,
					$msgFormatter
				)
			);
		}
		foreach ( $partialParticipants as $participant ) {
			$participantsList->appendContent(
				$this->getParticipantRow( $participant, $language, $msgFormatter )
			);
		}
		return $participantsList;
	}

	/**
	 * Returns the "action" element for the header (that are also cloned into the popup). This can be a button for
	 * managing the event, or one to register for it. Or it can be a widget informing the user that they are already
	 * registered, with a button to unregister. There can also be no element if the user is not allowed to register.
	 *
	 * @param int $eventID
	 * @param ITextFormatter $msgFormatter
	 * @param int $userStatus
	 * @return Element|null
	 */
	private function getActionElement( int $eventID, ITextFormatter $msgFormatter, int $userStatus ): ?Element {
		if ( $userStatus === self::USER_STATUS_BLOCKED ) {
			return null;
		}

		if (
			$userStatus === self::USER_STATUS_CANNOT_REGISTER_CLOSED ||
			$userStatus === self::USER_STATUS_CANNOT_REGISTER_ENDED
		) {
			$msgKey = $userStatus === self::USER_STATUS_CANNOT_REGISTER_CLOSED
				? 'campaignevents-eventpage-btn-registration-closed'
				: 'campaignevents-eventpage-btn-event-ended';
			return new ButtonWidget( [
				'disabled' => true,
				'label' => $msgFormatter->format( MessageValue::new( $msgKey ) ),
				'classes' => [
					'ext-campaignevents-eventpage-cloneable-element-for-dialog'
				],
			] );
		}

		if ( $userStatus === self::USER_STATUS_ORGANIZER ) {
			return new ButtonWidget( [
				'flags' => [ 'progressive' ],
				'label' => $msgFormatter->format( MessageValue::new( 'campaignevents-eventpage-btn-manage' ) ),
				'classes' => [
					'ext-campaignevents-eventpage-manage-btn',
					'ext-campaignevents-eventpage-cloneable-element-for-dialog'
				],
				'href' => SpecialPage::getTitleFor(
					SpecialEventDetails::PAGE_NAME,
					(string)$eventID
				)->getLocalURL(),
			] );
		}

		if ( $userStatus === self::USER_STATUS_PARTICIPANT_CAN_UNREGISTER ) {
			$unregisterURL = SpecialPage::getTitleFor(
				SpecialCancelEventRegistration::PAGE_NAME,
				(string)$eventID
			)->getLocalURL();

			return new HorizontalLayout( [
				'items' => [
					new MessageWidget( [
						'type' => 'success',
						'label' => $msgFormatter->format(
							MessageValue::new( 'campaignevents-eventpage-header-attending' )
						),
						'inline' => true,
					] ),
					new ButtonWidget( [
						'flags' => [ 'destructive' ],
						'icon' => 'trash',
						'framed' => false,
						'href' => $unregisterURL,
						'classes' => [ 'ext-campaignevents-event-unregister-btn' ],
					] )
				],
				'classes' => [
					'ext-campaignevents-eventpage-unregister-layout',
					'ext-campaignevents-eventpage-cloneable-element-for-dialog'
				]
			] );
		}

		if ( $userStatus === self::USER_STATUS_CAN_REGISTER ) {
			return new ButtonWidget( [
				'flags' => [ 'primary', 'progressive' ],
				'label' => $msgFormatter->format( MessageValue::new( 'campaignevents-eventpage-btn-register' ) ),
				'classes' => [
					'ext-campaignevents-eventpage-register-btn',
					'ext-campaignevents-eventpage-cloneable-element-for-dialog'
				],
				'href' => SpecialPage::getTitleFor( SpecialRegisterForEvent::PAGE_NAME, (string)$eventID )
					->getLocalURL(),
			] );
		}
		throw new LogicException( "Unexpected user status $userStatus" );
	}

	/**
	 * @param ExistingEventRegistration $event
	 * @param ICampaignsAuthority $performer
	 * @return int One of the SELF::USER_STATUS_* constants
	 */
	private function getUserStatus( ExistingEventRegistration $event, ICampaignsAuthority $performer ): int {
		try {
			$centralUser = $this->centralUserLookup->newFromAuthority( $performer );
		} catch ( UserNotGlobalException $_ ) {
			$centralUser = null;
		}

		// Do not check user blocks or other user-dependent conditions for logged-out users, so that we can serve the
		// same (cached) version of the page to everyone. Also, even if the IP is blocked, the user might have an
		// account that they can log into, so showing the button is fine.
		if ( $centralUser ) {
			if ( $performer->isSitewideBlocked() ) {
				return self::USER_STATUS_BLOCKED;
			}

			if ( $this->organizersStore->isEventOrganizer( $event->getID(), $centralUser ) ) {
				return self::USER_STATUS_ORGANIZER;
			}

			if ( $this->participantsStore->userParticipatesInEvent( $event->getID(), $centralUser, true ) ) {
				$checkUnregistrationAllowedVal = UnregisterParticipantCommand::checkIsUnregistrationAllowed( $event );
				switch ( $checkUnregistrationAllowedVal ) {
					case UnregisterParticipantCommand::CANNOT_UNREGISTER_DELETED:
						throw new UnexpectedValueException( "Registration should not be deleted at this point." );
					case UnregisterParticipantCommand::CAN_UNREGISTER:
						return self::USER_STATUS_PARTICIPANT_CAN_UNREGISTER;
					default:
						throw new UnexpectedValueException( "Unexpected value $checkUnregistrationAllowedVal" );
				}
			}
		}

		// User is logged-in and not already participating, or logged-out, in which case we'll know better
		// once they log in.
		$checkRegistrationAllowedVal = RegisterParticipantCommand::checkIsRegistrationAllowed( $event );
		switch ( $checkRegistrationAllowedVal ) {
			case RegisterParticipantCommand::CANNOT_REGISTER_DELETED:
				throw new UnexpectedValueException( "Registration should not be deleted at this point." );
			case RegisterParticipantCommand::CANNOT_REGISTER_ENDED:
				return self::USER_STATUS_CANNOT_REGISTER_ENDED;
			case RegisterParticipantCommand::CANNOT_REGISTER_CLOSED:
				return self::USER_STATUS_CANNOT_REGISTER_CLOSED;
			case RegisterParticipantCommand::CAN_REGISTER:
				return self::USER_STATUS_CAN_REGISTER;
			default:
				throw new UnexpectedValueException( "Unexpected value $checkRegistrationAllowedVal" );
		}
	}

	/**
	 * @param int $eventID
	 * @param ITextFormatter $msgFormatter
	 *
	 * @return Tag
	 */
	private function getParticipantFooter( int $eventID, ITextFormatter $msgFormatter ): Tag {
		$tag = new Tag( 'div' );

		$tag->appendContent(
				new HtmlSnippet( $this->linkRenderer->makeKnownLink(
					SpecialPage::getTitleFor( SpecialEventDetails::PAGE_NAME, (string)$eventID ),
					$msgFormatter->format(
						MessageValue::new( 'campaignevents-eventpage-dialog-participants-view-list' )
					),
					[],
					[ 'tab' => SpecialEventDetails::PARTICIPANTS_PANEL ]
				)
			) );

		return $tag;
	}

	/**
	 * @param Participant $participant
	 * @param Language $language
	 * @param ITextFormatter $formatter
	 * @return array
	 */
	private function getParticipantRow(
		Participant $participant, Language $language, ITextFormatter $formatter ): array {
		$usernameElement = new HtmlSnippet(
			$this->userLinker->generateUserLinkWithFallback(
				$participant->getUser(),
				$language->getCode()
			)
		);
		try {
			$userName = $this->centralUserLookup->getUserName( $participant->getUser() );
		} catch ( CentralUserNotFoundException | UserNotGlobalException $_ ) {
			// Hack: use an invalid username to force unspecified gender
			$userName = '@';
		}
		$elements = [];
		$tag = ( new Tag( 'li' ) )
			->appendContent( $usernameElement );
		$labelText = $formatter->format(
			MessageValue::new( 'campaignevents-eventpage-dialog-participant-private-registration-label' )
			->params( $userName )
		);
		if ( $participant->isPrivateRegistration() ) {
			$tag->appendContent( new IconWidget( [
					'icon' => 'lock',
					'title' => $labelText,
					'label' => $labelText,
					'classes' => [ 'ext-campaignevents-event-details-participants-private-icon' ]
				] )
			);
		}
		$elements[] = $tag;
		return $elements;
	}
}
