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
use MediaWiki\Extension\CampaignEvents\MWEntity\HiddenCentralUserException;
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
use MediaWiki\Extension\CampaignEvents\Questions\EventQuestionsRegistry;
use MediaWiki\Extension\CampaignEvents\Special\SpecialCancelEventRegistration;
use MediaWiki\Extension\CampaignEvents\Special\SpecialEnableEventRegistration;
use MediaWiki\Extension\CampaignEvents\Special\SpecialEventDetails;
use MediaWiki\Extension\CampaignEvents\Special\SpecialRegisterForEvent;
use MediaWiki\Extension\CampaignEvents\Time\EventTimeFormatter;
use MediaWiki\Extension\CampaignEvents\Utils;
use MediaWiki\Extension\CampaignEvents\Widget\TextWithIconWidget;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Permissions\Authority;
use MediaWiki\User\UserIdentity;
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
 * This service is used to add some widgets to the event page, like the registration header.
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
	/** @var EventPageCacheUpdater */
	private EventPageCacheUpdater $eventPageCacheUpdater;
	/** @var EventQuestionsRegistry */
	private EventQuestionsRegistry $eventQuestionsRegistry;

	private Language $language;
	private ICampaignsAuthority $authority;
	private UserIdentity $viewingUser;
	private OutputPage $out;
	private ITextFormatter $msgFormatter;

	/**
	 * @var bool|null Whether the user is registered publicly or privately. This value is lazy-loaded iff the user
	 * status is USER_STATUS_PARTICIPANT_CAN_UNREGISTER.
	 */
	private ?bool $participantIsPublic = null;

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
	 * @param EventPageCacheUpdater $eventPageCacheUpdater
	 * @param EventQuestionsRegistry $eventQuestionsRegistry
	 * @param Language $language
	 * @param Authority $viewingAuthority
	 * @param OutputPage $out
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
		EventTimeFormatter $eventTimeFormatter,
		EventPageCacheUpdater $eventPageCacheUpdater,
		EventQuestionsRegistry $eventQuestionsRegistry,
		Language $language,
		Authority $viewingAuthority,
		OutputPage $out
	) {
		$this->eventLookup = $eventLookup;
		$this->participantsStore = $participantsStore;
		$this->organizersStore = $organizersStore;
		$this->permissionChecker = $permissionChecker;
		$this->linkRenderer = $linkRenderer;
		$this->titleFormatter = $titleFormatter;
		$this->centralUserLookup = $centralUserLookup;
		$this->userLinker = $userLinker;
		$this->eventTimeFormatter = $eventTimeFormatter;
		$this->eventPageCacheUpdater = $eventPageCacheUpdater;
		$this->eventQuestionsRegistry = $eventQuestionsRegistry;

		$this->language = $language;
		$this->authority = new MWAuthorityProxy( $viewingAuthority );
		$this->viewingUser = $viewingAuthority->getUser();
		$this->out = $out;
		$this->msgFormatter = $messageFormatterFactory->getTextFormatter( $language->getCode() );
	}

	/**
	 * This is the main entry point for this class. It adds all the necessary HTML (registration header, popup etc.)
	 * to the given OutputPage, as well as loading some JS/CSS resources.
	 *
	 * @param ProperPageIdentity $page
	 */
	public function decoratePage( ProperPageIdentity $page ): void {
		$campaignsPage = new MWPageProxy( $page, $this->titleFormatter->getPrefixedText( $page ) );
		try {
			$registration = $this->eventLookup->getEventByPage( $campaignsPage );
		} catch ( EventNotFoundException $_ ) {
			$registration = null;
		}

		if ( $registration && $registration->getDeletionTimestamp() !== null ) {
			return;
		}

		if ( $registration ) {
			$this->addRegistrationHeader( $registration );
			$this->eventPageCacheUpdater->adjustCacheForPageWithRegistration( $this->out, $registration );
		} else {
			$this->maybeAddEnableRegistrationHeader( $campaignsPage );
		}
	}

	/**
	 * @param ICampaignsPage $eventPage
	 */
	private function maybeAddEnableRegistrationHeader( ICampaignsPage $eventPage ): void {
		if ( !$this->permissionChecker->userCanEnableRegistration( $this->authority, $eventPage ) ) {
			return;
		}

		$this->out->enableOOUI();
		$this->out->addModuleStyles( [
			'ext.campaignEvents.eventpage.styles',
			'oojs-ui.styles.icons-editing-advanced',
		] );
		$this->out->addModules( [ 'ext.campaignEvents.eventpage' ] );
		// We pass this to the client to avoid hardcoding the name of the page field in JS. Apparently we can't use
		// a RL callback for this because it doesn't provide the current page.
		$enableRegistrationURL = SpecialPage::getTitleFor( SpecialEnableEventRegistration::PAGE_NAME )->getLocalURL( [
			SpecialEnableEventRegistration::PAGE_FIELD_NAME => $eventPage->getPrefixedText()
		] );
		$this->out->addJsConfigVars( [ 'wgCampaignEventsEnableRegistrationURL' => $enableRegistrationURL ] );
		$this->out->addHTML( $this->getEnableRegistrationHeader( $enableRegistrationURL ) );
	}

	/**
	 * @param string $enableRegistrationURL
	 * @return Tag
	 */
	private function getEnableRegistrationHeader( string $enableRegistrationURL ): Tag {
		$organizerText = ( new Tag( 'div' ) )->appendContent(
			$this->msgFormatter->format( MessageValue::new( 'campaignevents-eventpage-enableheader-organizer' ) )
		)->setAttributes( [ 'class' => 'ext-campaignevents-eventpage-organizer-label' ] );

		// Wrap it in a span for use inside a flex container, since the message contains HTML.
		// XXX Can't use ITextFormatter here because the message contains HTML, see T260689
		$infoMsg = ( new Tag( 'span' ) )->appendContent(
			new HtmlSnippet( $this->out->msg( 'campaignevents-eventpage-enableheader-eventpage-desc' )->parse() )
		);
		$infoText = ( new Tag( 'div' ) )->appendContent(
			new IconWidget( [ 'icon' => 'calendar', 'classes' => [ 'ext-campaignevents-eventpage-icon' ] ] ),
			$infoMsg
		)->setAttributes( [ 'class' => 'ext-campaignevents-eventpage-enableheader-message' ] );
		$infoElement = ( new Tag( 'div' ) )->appendContent( $organizerText, $infoText );

		$enableRegistrationBtn = new ButtonWidget( [
			'flags' => [ 'primary', 'progressive' ],
			'label' => $this->msgFormatter->format(
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
			'dir' => $this->language->getDir(),
			'lang' => $this->language->getHtmlCode()
		] );
		return $layout;
	}

	/**
	 * @param ExistingEventRegistration $registration
	 */
	private function addRegistrationHeader( ExistingEventRegistration $registration ): void {
		$this->out->setPreventClickjacking( true );
		$this->out->enableOOUI();
		$this->out->addModuleStyles( array_merge(
			[
				'ext.campaignEvents.eventpage.styles',
				'oojs-ui.styles.icons-location',
				'oojs-ui.styles.icons-interactions',
				'oojs-ui.styles.icons-moderation',
				'oojs-ui.styles.icons-user',
				'oojs-ui.styles.icons-alerts',
			],
			UserLinker::MODULE_STYLES
		) );

		$this->out->addModules( [ 'ext.campaignEvents.eventpage' ] );

		try {
			$centralUser = $this->centralUserLookup->newFromAuthority( $this->authority );
		} catch ( UserNotGlobalException $_ ) {
			$centralUser = null;
		}
		$curParticipant = $centralUser ? $this->participantsStore->getEventParticipant(
			$registration->getID(),
			$centralUser,
			true
		) : null;

		$userStatus = $this->getUserStatus( $registration, $centralUser, $curParticipant );

		$this->out->addHTML( $this->getHeaderElement( $registration, $userStatus ) );
		$this->out->addHTML(
			$this->getDetailsDialogContent(
				$registration,
				$userStatus,
				$curParticipant
			)
		);

		$aggregationTimestamp = $curParticipant
			? Utils::getAnswerAggregationTimestamp( $curParticipant, $registration )
			: null;
		$this->out->addJsConfigVars( [
			'wgCampaignEventsEventID' => $registration->getID(),
			'wgCampaignEventsParticipantIsPublic' => $this->participantIsPublic,
			'wgCampaignEventsEventQuestions' => $this->getEventQuestionsData( $registration, $curParticipant ),
			// temporarily feature flag to prevent participants from seeing the event questions
			'wgCampaignEventsEnableParticipantQuestions' =>
				MediaWikiServices::getInstance()->getMainConfig()->get( 'CampaignEventsEnableParticipantQuestions' ),
			'wgCampaignEventsAggregationTimestamp' => $aggregationTimestamp
		] );
	}

	/**
	 * @param ExistingEventRegistration $registration
	 * @param Participant|null $participant
	 * @return array[]
	 */
	private function getEventQuestionsData(
		ExistingEventRegistration $registration,
		?Participant $participant
	): array {
		$enabledQuestions = $registration->getParticipantQuestions();
		$curAnswers = $participant ? $participant->getAnswers() : [];

		$questionsData = [];
		$questionsAPI = $this->eventQuestionsRegistry->getQuestionsForAPI( $enabledQuestions );
		// Localise all messages to avoid having to do that in the client side.
		foreach ( $questionsAPI as $questionAPIData ) {
			$curQuestionData = [
				'type' => $questionAPIData['type'],
				'label' => $this->msgFormatter->format( MessageValue::new( $questionAPIData['label-message'] ) ),
			];
			if ( isset( $questionAPIData['options-messages'] ) ) {
				$curQuestionData['options'] = [];
				foreach ( $questionAPIData['options-messages'] as $messageKey => $value ) {
					$message = $this->msgFormatter->format( MessageValue::new( $messageKey ) );
					$curQuestionData['options'][$messageKey] = [
						'value' => $value,
						'message' => $message
					];
				}
			}
			if ( isset( $questionAPIData['other-options'] ) ) {
				$curQuestionData['other-options'] = [];
				foreach ( $questionAPIData['other-options'] as $showIfVal => $otherOptData ) {
					$curQuestionData['other-options'][$showIfVal] = [
						'type' => $otherOptData['type'],
						'placeholder' => $this->msgFormatter->format(
							MessageValue::new( $otherOptData['label-message'] )
						),
					];
				}
			}
			$questionsData[$questionAPIData['name']] = $curQuestionData;
		}

		return [
			'questions' => $questionsData,
			'answers' => $this->eventQuestionsRegistry->formatAnswersForAPI( $curAnswers )
		];
	}

	/**
	 * Returns the header element.
	 *
	 * @param ExistingEventRegistration $registration
	 * @param int $userStatus One of the self::USER_STATUS_* constants
	 * @return Tag
	 */
	private function getHeaderElement(
		ExistingEventRegistration $registration,
		int $userStatus
	): Tag {
		$content = [];

		$participantNoticeRow = $this->getParticipantNoticeRow( $userStatus );
		if ( $participantNoticeRow ) {
			$content[] = $participantNoticeRow;
		}

		$content[] = $this->getEventInfoHeaderRow( $registration, $userStatus );

		$layout = new PanelLayout( [
			'content' => $content,
			'padded' => true,
			'framed' => true,
			'expanded' => false,
			'classes' => [ 'ext-campaignevents-eventpage-header' ],
		] );

		$layout->setAttributes( [
			// Set the lang/dir explicitly, otherwise it will use that of the site/page language,
			// not that of the interface.
			'dir' => $this->language->getDir(),
			'lang' => $this->language->getHtmlCode()
		] );

		return $layout;
	}

	/**
	 * @param int $userStatus
	 * @return Tag|null
	 */
	private function getParticipantNoticeRow( int $userStatus ): ?Tag {
		if ( $userStatus !== self::USER_STATUS_PARTICIPANT_CAN_UNREGISTER ) {
			return null;
		}
		$msg = $this->participantIsPublic
			? 'campaignevents-eventpage-header-registered-publicly'
			: 'campaignevents-eventpage-header-registered-privately';
		return new MessageWidget( [
			'type' => 'success',
			'label' => $this->msgFormatter->format( MessageValue::new( $msg ) ),
			'inline' => true,
			'classes' => [ 'ext-campaignevents-eventpage-participant-notice' ]
		] );
	}

	/**
	 * @param ExistingEventRegistration $registration
	 * @param int $userStatus
	 * @return Tag
	 */
	private function getEventInfoHeaderRow(
		ExistingEventRegistration $registration,
		int $userStatus
	): Tag {
		$eventID = $registration->getID();
		$items = [];

		$meetingType = $registration->getMeetingType();
		if ( $meetingType === EventRegistration::MEETING_TYPE_ONLINE_AND_IN_PERSON ) {
			$locationContent = $this->msgFormatter->format(
				MessageValue::new( 'campaignevents-eventpage-header-type-online-and-in-person' )
			);
		} elseif ( $meetingType & EventRegistration::MEETING_TYPE_ONLINE ) {
			$locationContent = $this->msgFormatter->format(
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
				$locationContent->appendContent(
					$this->language->truncateForVisual( $address, self::ADDRESS_MAX_LENGTH )
				);
			} else {
				$locationContent = $this->msgFormatter->format(
					MessageValue::new( 'campaignevents-eventpage-header-type-in-person' )
				);
			}
		}
		$items[] = new TextWithIconWidget( [
			'icon' => 'mapPin',
			'content' => $locationContent,
			'label' => $this->msgFormatter->format(
				MessageValue::new( 'campaignevents-eventpage-header-location-label' )
			),
			'icon_classes' => [ 'ext-campaignevents-eventpage-icon' ],
		] );

		$formattedStart = $this->eventTimeFormatter->formatStart( $registration, $this->language, $this->viewingUser );
		$formattedEnd = $this->eventTimeFormatter->formatEnd( $registration, $this->language, $this->viewingUser );
		$datesMsg = $this->msgFormatter->format(
			MessageValue::new( 'campaignevents-eventpage-header-dates' )->params(
				$formattedStart->getTimeAndDate(),
				$formattedStart->getDate(),
				$formattedStart->getTime(),
				$formattedEnd->getTimeAndDate(),
				$formattedEnd->getDate(),
				$formattedEnd->getTime()
			)
		);
		$formattedTimezone = $this->eventTimeFormatter->formatTimezone( $registration, $this->viewingUser );
		// XXX Can't use ITextFormatter due to parse()
		$timezoneMsg = $this->out->msg( 'campaignevents-eventpage-header-timezone' )
			->params( $formattedTimezone )
			->parse();
		$items[] = new TextWithIconWidget( [
			'icon' => 'clock',
			'content' => [
				$datesMsg,
				( new Tag( 'div' ) )->appendContent( new HtmlSnippet( $timezoneMsg ) )
			],
			'label' => $this->msgFormatter->format(
				MessageValue::new( 'campaignevents-eventpage-header-dates-label' )
			),
			'icon_classes' => [ 'ext-campaignevents-eventpage-icon' ],
		] );

		$items[] = new TextWithIconWidget( [
			'icon' => 'userGroup',
			'content' => $this->msgFormatter->format(
				MessageValue::new( 'campaignevents-eventpage-header-participants' )
					->numParams( $this->participantsStore->getFullParticipantCountForEvent( $eventID ) )
			),
			'label' => $this->msgFormatter->format(
				MessageValue::new( 'campaignevents-eventpage-header-participants-label' )
			),
			'icon_classes' => [ 'ext-campaignevents-eventpage-icon' ],
		] );

		$btnContainer = ( new Tag( 'div' ) )
			->addClasses( [ 'ext-campaignevents-eventpage-header-buttons' ] );
		$btnContainer->appendContent( new ButtonWidget( [
			'framed' => false,
			'flags' => [ 'progressive' ],
			'label' => $this->msgFormatter->format( MessageValue::new( 'campaignevents-eventpage-header-details' ) ),
			'classes' => [ 'ext-campaignevents-event-details-btn' ],
			'href' => SpecialPage::getTitleFor( SpecialEventDetails::PAGE_NAME, (string)$eventID )->getLocalURL(),
		] ) );

		$actionElement = $this->getActionElement( $eventID, $userStatus );
		if ( $actionElement ) {
			$btnContainer->appendContent( $actionElement );
		}

		$items[] = $btnContainer;

		return ( new Tag( 'div' ) )
			->addClasses( [ 'ext-campaignevents-eventpage-header-eventinfo' ] )
			->appendContent( ...$items );
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
	 * @param int $userStatus One of the self::USER_STATUS_* constants
	 * @param Participant|null $participant
	 * @return string
	 */
	private function getDetailsDialogContent(
		ExistingEventRegistration $registration,
		int $userStatus,
		?Participant $participant
	): string {
		$eventID = $registration->getID();
		$organizersCount = $this->organizersStore->getOrganizerCountForEvent( $eventID );

		$eventInfoContainer = $this->getDetailsDialogEventInfo(
			$registration,
			$organizersCount,
			$userStatus
		);
		$participantsContainer = $this->getDetailsDialogParticipants(
			$eventID,
			$participant
		);

		$dialogContent = Html::element(
			'h2',
			[ 'class' => 'ext-campaignevents-detailsdialog-header' ],
			$registration->getName()
		);
		$dialogContent .= $this->getDetailsDialogOrganizers(
			$eventID,
			$organizersCount
		);
		$dialogContent .= Html::rawElement(
			'div',
			[ 'class' => 'ext-campaignevents-detailsdialog-body-container' ],
			$eventInfoContainer . $participantsContainer
		);

		return Html::rawElement( 'div', [ 'id' => 'ext-campaignEvents-detailsDialog-content' ], $dialogContent );
	}

	/**
	 * @param int $eventID
	 * @param int $organizersCount
	 * @return string
	 */
	private function getDetailsDialogOrganizers(
		int $eventID,
		int $organizersCount
	): string {
		$partialOrganizers = $this->organizersStore->getEventOrganizers( $eventID, self::ORGANIZERS_LIMIT );

		$organizerElements = [];
		foreach ( $partialOrganizers as $organizer ) {
			$organizerElements[] = $this->userLinker->generateUserLinkWithFallback(
				$organizer->getUser(),
				$this->language->getCode()
			);
		}
		// XXX We need to use OutputPage here because there's no supported way to change the format of
		// MessageFormatterFactory...
		$organizersStr = $this->out->msg( 'campaignevents-eventpage-dialog-organizers' )
			->rawParams( $this->language->commaList( $organizerElements ) )
			->escaped();
		if ( count( $partialOrganizers ) < $organizersCount ) {
			$organizersStr .= Html::rawElement(
				'p',
				[],
				$this->linkRenderer->makeKnownLink(
					SpecialPage::getTitleFor( SpecialEventDetails::PAGE_NAME, (string)$eventID ),
					$this->msgFormatter->format(
						MessageValue::new( 'campaignevents-eventpage-dialog-organizers-view-all' )
					)
				)
			);
		}

		return Html::rawElement(
			'div',
			[ 'class' => 'ext-campaignevents-detailsdialog-organizers' ],
			$organizersStr
		);
	}

	/**
	 * @param ExistingEventRegistration $registration
	 * @param int $organizersCount
	 * @param int $userStatus
	 * @return string
	 */
	private function getDetailsDialogEventInfo(
		ExistingEventRegistration $registration,
		int $organizersCount,
		int $userStatus
	): string {
		$eventInfo = $this->getDetailsDialogDates( $registration );
		$eventInfo .= $this->getDetailsDialogLocation(
			$registration,
			$organizersCount,
			$userStatus
		);
		$eventInfo .= $this->getDetailsDialogChat( $registration, $userStatus );

		return Html::rawElement(
			'div',
			[ 'class' => 'ext-campaignevents-detailsdialog-eventinfo-container' ],
			$eventInfo
		);
	}

	/**
	 * @param ExistingEventRegistration $registration
	 * @return string
	 */
	private function getDetailsDialogDates( ExistingEventRegistration $registration ): string {
		$formattedStart = $this->eventTimeFormatter->formatStart( $registration, $this->language, $this->viewingUser );
		$formattedEnd = $this->eventTimeFormatter->formatEnd( $registration, $this->language, $this->viewingUser );
		$datesMsg = $this->msgFormatter->format(
			MessageValue::new( 'campaignevents-eventpage-dialog-dates' )->params(
				$formattedStart->getTimeAndDate(),
				$formattedStart->getDate(),
				$formattedStart->getTime(),
				$formattedEnd->getTimeAndDate(),
				$formattedEnd->getDate(),
				$formattedEnd->getTime()
			)
		);
		$formattedTimezone = $this->eventTimeFormatter->formatTimezone( $registration, $this->viewingUser );
		// XXX Can't use $msgFormatter due to parse()
		$timezoneMsg = $this->out->msg( 'campaignevents-eventpage-dialog-timezone' )
			->params( $formattedTimezone )
			->parse();
		return $this->makeDetailsDialogSection(
			'clock',
			[
				$datesMsg,
				( new Tag( 'div' ) )->appendContent( new HtmlSnippet( $timezoneMsg ) )
			],
			$this->msgFormatter->format(
				MessageValue::new( 'campaignevents-eventpage-dialog-dates-label' )
			)
		);
	}

	/**
	 * @param ExistingEventRegistration $registration
	 * @param int $organizersCount
	 * @param int $userStatus
	 * @return string
	 */
	private function getDetailsDialogLocation(
		ExistingEventRegistration $registration,
		int $organizersCount,
		int $userStatus
	): string {
		$locationElements = [];
		$onlineLocationElements = [];
		if ( $registration->getMeetingType() & EventRegistration::MEETING_TYPE_ONLINE ) {
			$onlineLocationElements[] = ( new Tag( 'h4' ) )
				->addClasses( [ 'ext-campaignevents-eventpage-detailsdialog-location-header' ] )
				->appendContent(
					$this->msgFormatter->format(
						MessageValue::new( 'campaignevents-eventpage-dialog-online-label' )
					) );
			$meetingURL = $registration->getMeetingURL();
			if ( $meetingURL === null ) {
				$linkContent = $this->msgFormatter->format(
					MessageValue::new( 'campaignevents-eventpage-dialog-link-not-available' )
						->numParams( $organizersCount )
				);
			} elseif (
				$userStatus === self::USER_STATUS_ORGANIZER ||
				$userStatus === self::USER_STATUS_PARTICIPANT_CAN_UNREGISTER
			) {
				$linkContent = new HtmlSnippet( Linker::makeExternalLink( $meetingURL, $meetingURL ) );
			} elseif ( $userStatus === self::USER_STATUS_CAN_REGISTER ) {
				$linkContent = $this->msgFormatter->format(
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
				$addressElement->appendContent( $this->msgFormatter->format(
					MessageValue::new( 'campaignevents-eventpage-dialog-venue-not-available' )
						->numParams( $organizersCount )
				) );
			}
			if ( $onlineLocationElements ) {
				$inPersonLabel = ( new Tag( 'h4' ) )
					->addClasses( [ 'ext-campaignevents-eventpage-detailsdialog-location-header' ] )
					->appendContent( $this->msgFormatter->format(
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
		return $this->makeDetailsDialogSection(
			'mapPin',
			$locationElements,
			$this->msgFormatter->format(
				MessageValue::new( 'campaignevents-eventpage-dialog-location-label' )
			)
		);
	}

	/**
	 * @param ExistingEventRegistration $registration
	 * @param int $userStatus
	 * @return string
	 */
	private function getDetailsDialogChat(
		ExistingEventRegistration $registration,
		int $userStatus
	): string {
		$chatURL = $registration->getChatURL();
		if ( $chatURL === null ) {
			$chatURLContent = $this->msgFormatter->format(
				MessageValue::new( 'campaignevents-eventpage-dialog-chat-not-available' )
			);
		} elseif (
			$userStatus === self::USER_STATUS_ORGANIZER ||
			$userStatus === self::USER_STATUS_PARTICIPANT_CAN_UNREGISTER
		) {
			$chatURLContent = new HtmlSnippet( Linker::makeExternalLink( $chatURL, $chatURL ) );
		} elseif ( $userStatus === self::USER_STATUS_CAN_REGISTER ) {
			$chatURLContent = $this->msgFormatter->format(
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
		return $this->makeDetailsDialogSection(
			'speechBubbles',
			$chatURLContent,
			$this->msgFormatter->format(
				MessageValue::new( 'campaignevents-eventpage-dialog-chat-label' )
			)
		);
	}

	/**
	 * @param int $eventID
	 * @param Participant|null $participant
	 * @return string
	 */
	private function getDetailsDialogParticipants(
		int $eventID,
		?Participant $participant
	): string {
		$showPrivateParticipants = $this->permissionChecker->userCanViewPrivateParticipants(
			$this->authority,
			$eventID
		);
		$participantsCount = $this->participantsStore->getFullParticipantCountForEvent( $eventID );
		$privateCount = $this->participantsStore->getPrivateParticipantCountForEvent( $eventID );
		$participantsList = $this->getParticipantRows(
			$eventID,
			$participant,
			$showPrivateParticipants
		);
		$participantsFooter = '';
		if ( self::PARTICIPANTS_LIMIT < $participantsCount ) {
			$participantsFooter = $this->getParticipantFooter( $eventID );
		}

		$privateCountFooter = '';
		if ( $privateCount > 0 ) {
			$privateCountFooter = new Tag();
			$privateCountFooter->addClasses( [
				'ext-campaignevents-detailsdialog-private-participants-footer'
			] );
			$privateCountIcon = new IconWidget( [
				'icon' => 'lock'
			] );
			$privateCountText = ( new Tag( 'span' ) )
				->addClasses( [ 'ext-campaignevents-detailsdialog-private-participants-footer-text' ] );
			$privateCountText->appendContent(
				$this->msgFormatter->format(
					MessageValue::new( 'campaignevents-eventpage-dialog-participants-private' )
						->numParams( $privateCount )
				)
			);

			$privateCountFooter->appendContent( [ $privateCountIcon, $privateCountText ] );
		}

		return $this->makeDetailsDialogSection(
			'userGroup',
			[ $participantsList ?: '', $participantsFooter ],
			$this->msgFormatter->format(
				MessageValue::new( 'campaignevents-eventpage-dialog-participants' )
					->numParams( $participantsCount )
			),
			$privateCountFooter
		);
	}

	/**
	 * @param int $eventID
	 * @param Participant|null $curUserParticipant
	 * @param bool $showPrivateParticipants
	 *
	 * @return Tag|null
	 */
	private function getParticipantRows(
		int $eventID,
		?Participant $curUserParticipant,
		bool $showPrivateParticipants
	): ?Tag {
		$participantsList = ( new Tag( 'ul' ) )
			->addClasses( [ 'ext-campaignevents-detailsdialog-participants-list' ] );
		$partialParticipants = $this->participantsStore->getEventParticipants(
			$eventID,
			$curUserParticipant ?
				self::PARTICIPANTS_LIMIT - 1 :
				self::PARTICIPANTS_LIMIT,
			null,
			null,
			null,
			$showPrivateParticipants,
			$curUserParticipant ? [ $curUserParticipant->getUser()->getCentralID() ] : null
		);

		if ( !$curUserParticipant && !$partialParticipants ) {
			return null;
		}

		if ( $curUserParticipant ) {
			$participantsList->appendContent(
				$this->getParticipantRow( $curUserParticipant )
			);
		}
		foreach ( $partialParticipants as $participant ) {
			$participantsList->appendContent(
				$this->getParticipantRow( $participant )
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
	 * @param int $userStatus
	 * @return Element|null
	 */
	private function getActionElement( int $eventID, int $userStatus ): ?Element {
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
				'label' => $this->msgFormatter->format( MessageValue::new( $msgKey ) ),
				'classes' => [
					'ext-campaignevents-eventpage-action-element'
				],
			] );
		}

		if ( $userStatus === self::USER_STATUS_ORGANIZER ) {
			return new ButtonWidget( [
				'flags' => [ 'progressive' ],
				'label' => $this->msgFormatter->format( MessageValue::new( 'campaignevents-eventpage-btn-manage' ) ),
				'classes' => [
					'ext-campaignevents-eventpage-action-element',
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

			// Note that this will be replaced with a ButtonMenuSelectWidget in JS.
			return new HorizontalLayout( [
				'items' => [
					new ButtonWidget( [
						'flags' => [ 'progressive' ],
						'label' => $this->msgFormatter->format(
							MessageValue::new( 'campaignevents-eventpage-btn-edit' )
						),
						'href' => SpecialPage::getTitleFor( SpecialRegisterForEvent::PAGE_NAME, (string)$eventID )
							->getLocalURL(),
					] ),
					new ButtonWidget( [
						'flags' => [ 'destructive' ],
						'label' => $this->msgFormatter->format(
							MessageValue::new( 'campaignevents-eventpage-btn-cancel' )
						),
						'href' => $unregisterURL,
					] )
				],
				'classes' => [
					'ext-campaignevents-eventpage-manage-registration-layout',
					'ext-campaignevents-eventpage-action-element'
				]
			] );
		}

		if ( $userStatus === self::USER_STATUS_CAN_REGISTER ) {
			return new ButtonWidget( [
				'flags' => [ 'primary', 'progressive' ],
				'label' => $this->msgFormatter->format( MessageValue::new( 'campaignevents-eventpage-btn-register' ) ),
				'classes' => [
					'ext-campaignevents-eventpage-register-btn',
					'ext-campaignevents-eventpage-action-element'
				],
				'href' => SpecialPage::getTitleFor( SpecialRegisterForEvent::PAGE_NAME, (string)$eventID )
					->getLocalURL(),
			] );
		}
		throw new LogicException( "Unexpected user status $userStatus" );
	}

	/**
	 * @param ExistingEventRegistration $event
	 * @param CentralUser|null $centralUser Corresponding to $this->authority, if it exists
	 * @param Participant|null $participant For $centralUser, if they're a participant
	 * @return int One of the SELF::USER_STATUS_* constants
	 */
	private function getUserStatus(
		ExistingEventRegistration $event,
		?CentralUser $centralUser,
		?Participant $participant
	): int {
		// Do not check user blocks or other user-dependent conditions for logged-out users, so that we can serve the
		// same (cached) version of the page to everyone. Also, even if the IP is blocked, the user might have an
		// account that they can log into, so showing the button is fine.
		if ( $centralUser ) {
			if ( $this->authority->isSitewideBlocked() ) {
				return self::USER_STATUS_BLOCKED;
			}

			if ( $this->organizersStore->isEventOrganizer( $event->getID(), $centralUser ) ) {
				return self::USER_STATUS_ORGANIZER;
			}

			if ( $participant ) {
				$checkUnregistrationAllowedVal = UnregisterParticipantCommand::checkIsUnregistrationAllowed( $event );
				switch ( $checkUnregistrationAllowedVal ) {
					case UnregisterParticipantCommand::CANNOT_UNREGISTER_DELETED:
						throw new UnexpectedValueException( "Registration should not be deleted at this point." );
					case UnregisterParticipantCommand::CAN_UNREGISTER:
						$this->participantIsPublic = !$participant->isPrivateRegistration();
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
	 * @return Tag
	 */
	private function getParticipantFooter( int $eventID ): Tag {
		$viewParticipantsURL = SpecialPage::getTitleFor( SpecialEventDetails::PAGE_NAME, (string)$eventID )
			->getLocalURL( [ 'tab' => SpecialEventDetails::PARTICIPANTS_PANEL ] );
		return new ButtonWidget( [
			'framed' => false,
			'flags' => [ 'progressive' ],
			'label' => $this->msgFormatter->format(
				MessageValue::new( 'campaignevents-eventpage-dialog-participants-view-list' )
			),
			'href' => $viewParticipantsURL,
		] );
	}

	/**
	 * @param Participant $participant
	 * @return Tag
	 */
	private function getParticipantRow( Participant $participant ): Tag {
		$usernameElement = new HtmlSnippet(
			$this->userLinker->generateUserLinkWithFallback(
				$participant->getUser(),
				$this->language->getCode()
			)
		);

		$tag = ( new Tag( 'li' ) )
			->appendContent( $usernameElement );

		if ( $participant->isPrivateRegistration() ) {
			try {
				$userName = $this->centralUserLookup->getUserName( $participant->getUser() );
			} catch ( CentralUserNotFoundException | HiddenCentralUserException $_ ) {
				// Hack: use an invalid username to force unspecified gender
				$userName = '@';
			}
			$labelText = $this->msgFormatter->format(
				MessageValue::new( 'campaignevents-eventpage-dialog-private-registration-label' )
					->params( $userName )
			);
			$tag->appendContent( new IconWidget( [
					'icon' => 'lock',
					'title' => $labelText,
					'label' => $labelText,
					'classes' => [ 'ext-campaignevents-event-details-participants-private-icon' ]
				] )
			);
		}

		return $tag;
	}

	/**
	 * @param string $icon
	 * @param string|Tag|array $content
	 * @param string $label
	 * @param string|Tag|array $footer
	 * @return string
	 */
	private function makeDetailsDialogSection( string $icon, $content, string $label, $footer = '' ): string {
		$iconWidget = new IconWidget( [
			'icon' => $icon,
			'classes' => [ 'ext-campaignevents-eventpage-detailsdialog-section-icon' ]
		] );
		$header = ( new Tag( 'h3' ) )
			->appendContent( $iconWidget, ( new Tag( 'span' ) )->appendContent( $label ) )
			->addClasses( [ 'ext-campaignevents-eventpage-detailsdialog-section-header' ] );

		$contentTag = ( new Tag( 'div' ) )
			->appendContent( $content )
			->addClasses( [ 'ext-campaignevents-eventpage-detailsdialog-section-content' ] );

		return (string)( new Tag( 'div' ) )
			->appendContent( $header, $contentTag, $footer );
	}
}
