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
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsPage;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWPageProxy;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWUserProxy;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserBlockChecker;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Participants\RegisterParticipantCommand;
use MediaWiki\Extension\CampaignEvents\Participants\UnregisterParticipantCommand;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\Special\SpecialCancelEventRegistration;
use MediaWiki\Extension\CampaignEvents\Special\SpecialEnableEventRegistration;
use MediaWiki\Extension\CampaignEvents\Special\SpecialEventDetails;
use MediaWiki\Extension\CampaignEvents\Special\SpecialRegisterForEvent;
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
	private const USER_STATUS_PARTICIPANT_CANNOT_UNREGISTER = 4;
	private const USER_STATUS_CAN_REGISTER = 5;
	private const USER_STATUS_CANNOT_REGISTER = 6;

	/** @var IEventLookup */
	private $eventLookup;
	/** @var ParticipantsStore */
	private $participantsStore;
	/** @var OrganizersStore */
	private $organizersStore;
	/** @var UserBlockChecker */
	private $userBlockChecker;
	/** @var PermissionChecker */
	private $permissionChecker;
	/** @var IMessageFormatterFactory */
	private $messageFormatterFactory;
	/** @var LinkRenderer */
	private $linkRenderer;
	/** @var TitleFormatter */
	private $titleFormatter;

	/**
	 * @param IEventLookup $eventLookup
	 * @param ParticipantsStore $participantsStore
	 * @param OrganizersStore $organizersStore
	 * @param UserBlockChecker $userBlockChecker
	 * @param PermissionChecker $permissionChecker
	 * @param IMessageFormatterFactory $messageFormatterFactory
	 * @param LinkRenderer $linkRenderer
	 * @param TitleFormatter $titleFormatter
	 */
	public function __construct(
		IEventLookup $eventLookup,
		ParticipantsStore $participantsStore,
		OrganizersStore $organizersStore,
		UserBlockChecker $userBlockChecker,
		PermissionChecker $permissionChecker,
		IMessageFormatterFactory $messageFormatterFactory,
		LinkRenderer $linkRenderer,
		TitleFormatter $titleFormatter
	) {
		$this->eventLookup = $eventLookup;
		$this->participantsStore = $participantsStore;
		$this->organizersStore = $organizersStore;
		$this->userBlockChecker = $userBlockChecker;
		$this->permissionChecker = $permissionChecker;
		$this->messageFormatterFactory = $messageFormatterFactory;
		$this->linkRenderer = $linkRenderer;
		$this->titleFormatter = $titleFormatter;
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
		$userProxy = new MWUserProxy( $viewingUser, $viewingAuthority );
		if ( $registration ) {
			$this->addRegistrationHeader(
				$registration, $out, $viewingUser, $userProxy, $msgFormatter, $language );
		} else {
			$this->maybeAddEnableRegistrationHeader( $out, $msgFormatter, $language, $userProxy, $campaignsPage );
		}
	}

	/**
	 * @param OutputPage $out
	 * @param ITextFormatter $msgFormatter
	 * @param Language $language
	 * @param ICampaignsUser $user
	 * @param ICampaignsPage $eventPage
	 */
	private function maybeAddEnableRegistrationHeader(
		OutputPage $out,
		ITextFormatter $msgFormatter,
		Language $language,
		ICampaignsUser $user,
		ICampaignsPage $eventPage
	): void {
		if ( !$this->permissionChecker->userCanEnableRegistration( $user, $eventPage ) ) {
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
		)->setAttributes( [ 'class' => 'ext-campaignevents-eventpage-info-label' ] );
		$infoElement = ( new Tag( 'div' ) )->appendContent( $organizerText, $infoText );

		$enableRegistrationBtn = new ButtonWidget( [
			'flags' => [ 'primary', 'progressive' ],
			'label' => $msgFormatter->format(
				MessageValue::new( 'campaignevents-eventpage-enableheader-button-label' )
			),
			'classes' => [ 'ext-campaignevents-eventpage-action-element' ],
			'href' => $enableRegistrationURL,
		] );

		$layout = new PanelLayout( [
			'content' => [ $infoElement, $enableRegistrationBtn ],
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
	 * @param ExistingEventRegistration $registration
	 * @param OutputPage $out
	 * @param UserIdentity $viewingUser
	 * @param ICampaignsUser $userProxy
	 * @param ITextFormatter $msgFormatter
	 * @param Language $language
	 */
	private function addRegistrationHeader(
		ExistingEventRegistration $registration,
		OutputPage $out,
		UserIdentity $viewingUser,
		ICampaignsUser $userProxy,
		ITextFormatter $msgFormatter,
		Language $language
	): void {
		$out->setPreventClickjacking( true );
		$out->enableOOUI();
		$out->addModuleStyles( [
			'ext.campaignEvents.eventpage.styles',
			// Needed by Linker::userLink
			'mediawiki.interface.helpers.styles',
			'oojs-ui.styles.icons-location',
			'oojs-ui.styles.icons-interactions',
			'oojs-ui.styles.icons-moderation',
			'oojs-ui.styles.icons-user'
		] );
		$out->addModules( [ 'ext.campaignEvents.eventpage' ] );
		$out->addJsConfigVars( [
			'wgCampaignEventsEventID' => $registration->getID()
		] );

		$userStatus = $this->getUserStatus( $registration, $userProxy );
		$out->addHTML( $this->getHeaderElement( $registration, $msgFormatter, $language, $viewingUser, $userStatus ) );
		$out->addHTML(
			$this->getDetailsDialogContent( $registration, $msgFormatter, $language, $viewingUser, $userStatus )
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
	 * @return Tag
	 */
	private function getHeaderElement(
		ExistingEventRegistration $registration,
		ITextFormatter $msgFormatter,
		Language $language,
		UserIdentity $viewingUser,
		int $userStatus
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

		$items[] = new TextWithIconWidget( [
			'icon' => 'clock',
			'content' => $msgFormatter->format(
				MessageValue::new( 'campaignevents-eventpage-header-dates' )->params(
					$language->userTimeAndDate( $registration->getStartTimestamp(), $viewingUser ),
					$language->userDate( $registration->getStartTimestamp(), $viewingUser ),
					$language->userTime( $registration->getStartTimestamp(), $viewingUser ),
					$language->userTimeAndDate( $registration->getEndTimestamp(), $viewingUser ),
					$language->userDate( $registration->getEndTimestamp(), $viewingUser ),
					$language->userTime( $registration->getEndTimestamp(), $viewingUser )
				)
			),
			'label' => $msgFormatter->format( MessageValue::new( 'campaignevents-eventpage-header-dates-label' ) ),
			'icon_classes' => [ 'ext-campaignevents-eventpage-icon' ],
		] );

		$items[] = new TextWithIconWidget( [
			'icon' => 'userGroup',
			'content' => $msgFormatter->format(
				MessageValue::new( 'campaignevents-eventpage-header-participants' )
					->numParams( $this->participantsStore->getParticipantCountForEvent( $eventID ) )
			),
			'label' => $msgFormatter->format(
				MessageValue::new( 'campaignevents-eventpage-header-participants-label' )
			),
			'icon_classes' => [ 'ext-campaignevents-eventpage-icon' ],
		] );

		$items[] = new ButtonWidget( [
			'framed' => false,
			'flags' => [ 'progressive' ],
			'label' => $msgFormatter->format( MessageValue::new( 'campaignevents-eventpage-header-details' ) ),
			'classes' => [ 'ext-campaignevents-event-details-btn' ],
			'href' => SpecialPage::getTitleFor( SpecialEventDetails::PAGE_NAME, (string)$eventID )->getLocalURL(),
		] );

		$actionElements = $this->getActionElements( $eventID, $msgFormatter, $userStatus );
		$items = array_merge( $items, $actionElements );

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
	 * - Other utilities are missing (e.g., Linker::userLink)
	 * - Secondarily, no need to make 3 API requests and worry about them failing.
	 *
	 * @param ExistingEventRegistration $registration
	 * @param ITextFormatter $msgFormatter
	 * @param Language $language
	 * @param UserIdentity $viewingUser
	 * @param int $userStatus One of the self::USER_STATUS_* constants
	 * @return string
	 */
	private function getDetailsDialogContent(
		ExistingEventRegistration $registration,
		ITextFormatter $msgFormatter,
		Language $language,
		UserIdentity $viewingUser,
		int $userStatus
	): string {
		$eventID = $registration->getID();

		$organizersCount = $this->organizersStore->getOrganizerCountForEvent( $eventID );
		$partialOrganizers = $this->organizersStore->getEventOrganizers( $eventID, self::ORGANIZERS_LIMIT );
		$organizerLinks = [];
		foreach ( $partialOrganizers as $organizer ) {
			$organizerUser = $organizer->getUser();
			$organizerLinks[] = Linker::userLink( $organizerUser->getLocalID(), $organizerUser->getName() );
		}
		$organizersStr = $msgFormatter->format(
			MessageValue::new( 'campaignevents-eventpage-dialog-organizers' )->commaListParams( $organizerLinks )
		);
		if ( count( $partialOrganizers ) < $organizersCount ) {
			$organizersStr .= Html::rawElement(
				'p',
				[],
				// TODO MVP: This page doesn't actually list the organizers. However, in V0 there can only be
				// a single organizer.
				$this->linkRenderer->makeKnownLink(
					SpecialPage::getTitleFor( SpecialEventDetails::PAGE_NAME, (string)$eventID ),
					$msgFormatter->format(
						MessageValue::new( 'campaignevents-eventpage-dialog-organizers-view-all' )
					)
				)
			);
		}
		$organizersAndDetails = Html::rawElement( 'div', [], $organizersStr );
		$datesWidget = new EventDetailsWidget( [
			'icon' => 'clock',
			'label' => $msgFormatter->format(
				MessageValue::new( 'campaignevents-eventpage-dialog-dates-label' )
			),
			'content' => $msgFormatter->format(
				MessageValue::new( 'campaignevents-eventpage-dialog-dates' )->params(
					$language->userTimeAndDate( $registration->getStartTimestamp(), $viewingUser ),
					$language->userDate( $registration->getStartTimestamp(), $viewingUser ),
					$language->userTime( $registration->getStartTimestamp(), $viewingUser ),
					$language->userTimeAndDate( $registration->getEndTimestamp(), $viewingUser ),
					$language->userDate( $registration->getEndTimestamp(), $viewingUser ),
					$language->userTime( $registration->getEndTimestamp(), $viewingUser )
				)
			)
		] );
		$organizersAndDetails .= Html::rawElement( 'div', [], $datesWidget );

		$locationElements = [];
		$onlineLocationElements = [];
		if ( $registration->getMeetingType() & EventRegistration::MEETING_TYPE_ONLINE ) {
			$onlineLocationElements[] = ( new Tag( 'h5' ) )->appendContent(
				$msgFormatter->format(
					MessageValue::new( 'campaignevents-eventpage-dialog-online-label' )
				)
			);
			$meetingURL = $registration->getMeetingURL();
			if ( $meetingURL === null ) {
				$linkContent = $msgFormatter->format(
					MessageValue::new( 'campaignevents-eventpage-dialog-link-not-available' )
						->numParams( $organizersCount )
				);
			} elseif (
				$userStatus === self::USER_STATUS_ORGANIZER ||
				$userStatus === self::USER_STATUS_PARTICIPANT_CAN_UNREGISTER ||
				$userStatus === self::USER_STATUS_PARTICIPANT_CANNOT_UNREGISTER
			) {
				$linkContent = new HtmlSnippet( Linker::makeExternalLink( $meetingURL, $meetingURL ) );
			} elseif ( $userStatus === self::USER_STATUS_CAN_REGISTER ) {
				$linkContent = $msgFormatter->format(
					MessageValue::new( 'campaignevents-eventpage-dialog-link-register' )
				);
			} elseif (
				$userStatus === self::USER_STATUS_BLOCKED ||
				$userStatus === self::USER_STATUS_CANNOT_REGISTER
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
				$inPersonLabel = ( new Tag( 'h5' ) )->appendContent( $msgFormatter->format(
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
		$organizersAndDetailsContainer = Html::rawElement(
			'div',
			[ 'class' => 'ext-campaignevents-detailsdialog-organizeranddetails-container' ],
			$organizersAndDetails
		);

		$participantsList = '';
		$participantsCount = $this->participantsStore->getParticipantCountForEvent( $eventID );
		$partialParticipants = $this->participantsStore->getEventParticipants( $eventID, self::PARTICIPANTS_LIMIT );
		if ( $partialParticipants ) {
			$participantsList .= Html::openElement( 'ul' );
			foreach ( $partialParticipants as $participant ) {
				$participantsList .= Html::rawElement(
					'li',
					[],
					Linker::userLink( $participant->getUser()->getLocalID(), $participant->getUser()->getName() )
				);
			}
			$participantsList .= Html::closeElement( 'ul' );
			if ( count( $partialParticipants ) < $participantsCount ) {
				$participantsList .= Html::rawElement(
					'p',
					[],
					$this->linkRenderer->makeKnownLink(
						SpecialPage::getTitleFor( SpecialEventDetails::PAGE_NAME, (string)$eventID ),
						$msgFormatter->format(
							MessageValue::new( 'campaignevents-eventpage-dialog-participants-view-list' )
						)
					)
				);
			}
		}
		$participantsWidget = new EventDetailsWidget( [
			'icon' => 'userGroup',
			'label' => $msgFormatter->format(
				MessageValue::new( 'campaignevents-eventpage-dialog-participants' )
					->numParams( $participantsCount )
			),
			'content' => new HtmlSnippet( $participantsList )
		] );
		$participantsContainer = Html::rawElement( 'div', [], $participantsWidget );

		$dialogContent = Html::element( 'h2', [], $registration->getName() );
		$dialogContent .= Html::rawElement(
			'div',
			[ 'class' => 'ext-campaignevents-detailsdialog-body-container' ],
			$organizersAndDetailsContainer . $participantsContainer
		);

		return Html::rawElement( 'div', [ 'id' => 'ext-campaignEvents-detailsDialog-content' ], $dialogContent );
	}

	/**
	 * Returns the "action" elements for the header (that are also cloned into the popup). This can be a button for
	 * managing the event, or one to register for it. Or it can be a widget informing the user that they are already
	 * registered, with a button to unregister. There can also be no elements if the user is not allowed to register.
	 * Note that when the user can (un)register we always return both the register button and the unregister layout, but
	 * one of them will be hidden. This way we can easily switch which one is shown when the button is clicked.
	 * Reloading the page may not work because we should only pull the data from the replicas on view requests.
	 *
	 * @param int $eventID
	 * @param ITextFormatter $msgFormatter
	 * @param int $userStatus
	 * @return Element[]
	 */
	private function getActionElements( int $eventID, ITextFormatter $msgFormatter, int $userStatus ): array {
		if ( $userStatus === self::USER_STATUS_BLOCKED || $userStatus === self::USER_STATUS_CANNOT_REGISTER ) {
			return [];
		}

		if ( $userStatus === self::USER_STATUS_ORGANIZER ) {
			$manageBtn = new ButtonWidget( [
				'flags' => [ 'progressive' ],
				'label' => $msgFormatter->format( MessageValue::new( 'campaignevents-eventpage-btn-manage' ) ),
				'classes' => [
					'ext-campaignevents-eventpage-action-element',
					'ext-campaignevents-eventpage-manage-btn'
				],
				'href' => SpecialPage::getTitleFor(
					SpecialEventDetails::PAGE_NAME,
					(string)$eventID
				)->getLocalURL(),
			] );
			return [ $manageBtn ];
		}

		$unregisterURL = SpecialPage::getTitleFor(
			SpecialCancelEventRegistration::PAGE_NAME,
			(string)$eventID
		)->getLocalURL();
		$alreadyRegisteredItems = [
			new MessageWidget( [
				'type' => 'success',
				'label' => $msgFormatter->format(
					MessageValue::new( 'campaignevents-eventpage-header-attending' )
				),
				'inline' => true,
			] )
		];
		if ( $userStatus !== self::USER_STATUS_PARTICIPANT_CANNOT_UNREGISTER ) {
			$alreadyRegisteredItems[] = new ButtonWidget( [
				'flags' => [ 'destructive' ],
				'icon' => 'trash',
				'framed' => false,
				'href' => $unregisterURL,
				'classes' => [ 'ext-campaignevents-event-unregister-btn' ],
			] );
		}
		$alreadyRegisteredAction = new HorizontalLayout( [
			'items' => $alreadyRegisteredItems,
			'classes' => [
				'ext-campaignevents-eventpage-action-element',
				'ext-campaignevents-eventpage-unregister-layout'
			]
		] );
		$registerBtn = new ButtonWidget( [
			'flags' => [ 'primary', 'progressive' ],
			'label' => $msgFormatter->format( MessageValue::new( 'campaignevents-eventpage-btn-register' ) ),
			'classes' => [ 'ext-campaignevents-eventpage-action-element', 'ext-campaignevents-eventpage-register-btn' ],
			'href' => SpecialPage::getTitleFor( SpecialRegisterForEvent::PAGE_NAME, (string)$eventID )->getLocalURL(),
		] );

		if (
			$userStatus === self::USER_STATUS_PARTICIPANT_CAN_UNREGISTER ||
			$userStatus === self::USER_STATUS_PARTICIPANT_CANNOT_UNREGISTER
		) {
			$registerBtn->addClasses( [ 'ext-campaignevents-eventpage-hidden-action' ] );
		} elseif ( $userStatus === self::USER_STATUS_CAN_REGISTER ) {
			$alreadyRegisteredAction->addClasses( [ 'ext-campaignevents-eventpage-hidden-action' ] );
		} else {
			throw new LogicException( "Unexpected user status $userStatus" );
		}

		return [ $registerBtn, $alreadyRegisteredAction ];
	}

	/**
	 * @param ExistingEventRegistration $event
	 * @param ICampaignsUser $user
	 * @return int One of the SELF::USER_STATUS_* constants
	 */
	private function getUserStatus( ExistingEventRegistration $event, ICampaignsUser $user ): int {
		if ( $this->userBlockChecker->isSitewideBlocked( $user ) ) {
			return self::USER_STATUS_BLOCKED;
		}

		if ( $this->organizersStore->isEventOrganizer( $event->getID(), $user ) ) {
			return self::USER_STATUS_ORGANIZER;
		}

		if ( $this->participantsStore->userParticipatesToEvent( $event->getID(), $user ) ) {
			return UnregisterParticipantCommand::isUnregistrationAllowedForEvent( $event )
				? self::USER_STATUS_PARTICIPANT_CAN_UNREGISTER
				: self::USER_STATUS_PARTICIPANT_CANNOT_UNREGISTER;
		}

		// User is logged-in and not already participating, or logged-out, in which case we'll know better
		// once they log in.
		return RegisterParticipantCommand::isRegistrationAllowedForEvent( $event )
			? self::USER_STATUS_CAN_REGISTER
			: self::USER_STATUS_CANNOT_REGISTER;
	}
}
