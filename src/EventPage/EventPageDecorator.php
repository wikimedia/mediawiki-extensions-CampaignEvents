<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\EventPage;

use LogicException;
use MediaWiki\Config\Config;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\PageEventLookup;
use MediaWiki\Extension\CampaignEvents\Formatters\EventFormatter;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFactory;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUserNotFoundException;
use MediaWiki\Extension\CampaignEvents\MWEntity\HiddenCentralUserException;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsAuthority;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsPage;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWAuthorityProxy;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserLinker;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\MWEntity\WikiLookup;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Participants\Participant;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Participants\RegisterParticipantCommand;
use MediaWiki\Extension\CampaignEvents\Participants\UnregisterParticipantCommand;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\Questions\EventQuestionsRegistry;
use MediaWiki\Extension\CampaignEvents\Special\AbstractEventRegistrationSpecialPage;
use MediaWiki\Extension\CampaignEvents\Special\SpecialCancelEventRegistration;
use MediaWiki\Extension\CampaignEvents\Special\SpecialEnableEventRegistration;
use MediaWiki\Extension\CampaignEvents\Special\SpecialEventDetails;
use MediaWiki\Extension\CampaignEvents\Special\SpecialRegisterForEvent;
use MediaWiki\Extension\CampaignEvents\Time\EventTimeFormatter;
use MediaWiki\Extension\CampaignEvents\Topics\ITopicRegistry;
use MediaWiki\Extension\CampaignEvents\Utils;
use MediaWiki\Extension\CampaignEvents\Widget\TextWithIconWidget;
use MediaWiki\Html\Html;
use MediaWiki\Language\Language;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Output\OutputPage;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Permissions\Authority;
use MediaWiki\Permissions\GroupPermissionsLookup;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\UserGroupMembership;
use MediaWiki\User\UserIdentity;
use OOUI\ButtonWidget;
use OOUI\Element;
use OOUI\HorizontalLayout;
use OOUI\HtmlSnippet;
use OOUI\IconWidget;
use OOUI\MessageWidget;
use OOUI\PanelLayout;
use OOUI\Tag;
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

	private PageEventLookup $pageEventLookup;
	private ParticipantsStore $participantsStore;
	private OrganizersStore $organizersStore;
	private PermissionChecker $permissionChecker;
	private LinkRenderer $linkRenderer;
	private CampaignsPageFactory $campaignsPageFactory;
	private CampaignsCentralUserLookup $centralUserLookup;
	private UserLinker $userLinker;
	private EventTimeFormatter $eventTimeFormatter;
	private EventPageCacheUpdater $eventPageCacheUpdater;
	private EventQuestionsRegistry $eventQuestionsRegistry;
	private WikiLookup $wikiLookup;
	private ITopicRegistry $topicRegistry;
	private GroupPermissionsLookup $groupPermissionsLookup;
	private Config $config;

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

	public function __construct(
		PageEventLookup $pageEventLookup,
		ParticipantsStore $participantsStore,
		OrganizersStore $organizersStore,
		PermissionChecker $permissionChecker,
		IMessageFormatterFactory $messageFormatterFactory,
		LinkRenderer $linkRenderer,
		CampaignsPageFactory $campaignsPageFactory,
		CampaignsCentralUserLookup $centralUserLookup,
		UserLinker $userLinker,
		EventTimeFormatter $eventTimeFormatter,
		EventPageCacheUpdater $eventPageCacheUpdater,
		EventQuestionsRegistry $eventQuestionsRegistry,
		WikiLookup $wikiLookup,
		ITopicRegistry $topicRegistry,
		GroupPermissionsLookup $groupPermissionsLookup,
		Config $config,
		Language $language,
		Authority $viewingAuthority,
		OutputPage $out
	) {
		$this->pageEventLookup = $pageEventLookup;
		$this->participantsStore = $participantsStore;
		$this->organizersStore = $organizersStore;
		$this->permissionChecker = $permissionChecker;
		$this->linkRenderer = $linkRenderer;
		$this->campaignsPageFactory = $campaignsPageFactory;
		$this->centralUserLookup = $centralUserLookup;
		$this->userLinker = $userLinker;
		$this->eventTimeFormatter = $eventTimeFormatter;
		$this->eventPageCacheUpdater = $eventPageCacheUpdater;
		$this->eventQuestionsRegistry = $eventQuestionsRegistry;
		$this->wikiLookup = $wikiLookup;
		$this->topicRegistry = $topicRegistry;
		$this->groupPermissionsLookup = $groupPermissionsLookup;
		$this->config = $config;

		$this->language = $language;
		$this->authority = new MWAuthorityProxy( $viewingAuthority );
		$this->viewingUser = $viewingAuthority->getUser();
		$this->out = $out;
		$this->msgFormatter = $messageFormatterFactory->getTextFormatter( $language->getCode() );
	}

	/**
	 * This is the main entry point for this class. It adds all the necessary HTML (registration header, popup etc.)
	 * to the given OutputPage, as well as loading some JS/CSS resources.
	 */
	public function decoratePage( ProperPageIdentity $page ): void {
		$registration = $this->pageEventLookup->getRegistrationForLocalPage( $page );

		if ( $registration && $registration->getDeletionTimestamp() !== null ) {
			return;
		}

		if ( $registration ) {
			$this->addRegistrationHeader( $page, $registration );
			$this->eventPageCacheUpdater->adjustCacheForPageWithRegistration( $this->out, $registration );
		} else {
			$campaignsPage = $this->campaignsPageFactory->newFromLocalMediaWikiPage( $page );
			$this->maybeAddEnableRegistrationHeader( $campaignsPage );
		}
	}

	private function maybeAddEnableRegistrationHeader( ICampaignsPage $eventPage ): void {
		if (
			$eventPage->getNamespace() !== NS_EVENT ||
			!in_array( NS_EVENT, $this->config->get( 'CampaignEventsEventNamespaces' ), true ) ||
			!$this->permissionChecker->userCanEnableRegistration( $this->authority, $eventPage )
		) {
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

	private function addRegistrationHeader( ProperPageIdentity $page, ExistingEventRegistration $registration ): void {
		$this->out->getMetadata()->setPreventClickjacking( true );
		$this->out->enableOOUI();
		$this->out->addModuleStyles( array_merge(
			[
				'ext.campaignEvents.eventpage.styles',
				'oojs-ui.styles.icons-location',
				'oojs-ui.styles.icons-interactions',
				'oojs-ui.styles.icons-moderation',
				'oojs-ui.styles.icons-user',
				'oojs-ui.styles.icons-alerts',
				'oojs-ui.styles.icons-wikimedia',
				'oojs-ui.styles.icons-content'
			],
			UserLinker::MODULE_STYLES
		) );

		$this->out->addModules( [ 'ext.campaignEvents.eventpage' ] );

		try {
			$centralUser = $this->centralUserLookup->newFromAuthority( $this->authority );
			$curParticipant = $this->participantsStore->getEventParticipant(
				$registration->getID(),
				$centralUser,
				true
			);
			$hasAggregatedAnswers = $this->participantsStore->userHasAggregatedAnswers(
				$registration->getID(),
				$centralUser
			);
		} catch ( UserNotGlobalException $_ ) {
			$centralUser = null;
			$curParticipant = null;
			$hasAggregatedAnswers = false;
		}

		$userStatus = $this->getUserStatus( $registration, $centralUser, $curParticipant );

		$this->out->addHTML( $this->getHeaderElement( $registration, $userStatus ) );
		$this->out->addHTML(
			$this->getDetailsDialogContent(
				$page,
				$registration,
				$userStatus,
				$curParticipant
			)
		);

		$aggregationTimestamp = $curParticipant
			? Utils::getAnswerAggregationTimestamp( $curParticipant, $registration )
			: null;

		$session = $this->out->getRequest()->getSession();
		$registrationUpdatedVal = $session
			->get( AbstractEventRegistrationSpecialPage::REGISTRATION_UPDATED_SESSION_KEY );
		$registrationUpdatedWarnings = [];
		$isNewRegistration = false;
		if ( $registrationUpdatedVal ) {
			// User just updated registration, show a success notification, plus any warnings.
			$registrationUpdatedWarnings = $session
				->get( AbstractEventRegistrationSpecialPage::REGISTRATION_UPDATED_WARNINGS_SESSION_KEY, [] );
			$isNewRegistration = $registrationUpdatedVal ===
				AbstractEventRegistrationSpecialPage::REGISTRATION_UPDATED_SESSION_ENABLED;
			$session->remove( AbstractEventRegistrationSpecialPage::REGISTRATION_UPDATED_SESSION_KEY );
			$session->remove( AbstractEventRegistrationSpecialPage::REGISTRATION_UPDATED_WARNINGS_SESSION_KEY );
		}
		$privateAccessGroups = [];
		foreach (
			$this->groupPermissionsLookup->getGroupsWithPermission(
				PermissionChecker::VIEW_PRIVATE_PARTICIPANTS_RIGHT
			) as $group
		) {
			$privateAccessGroups[] = UserGroupMembership::getLinkWiki( $group, $this->out->getContext() );
		}
		$privateAccessGroupsText = $this->language->listToText( $privateAccessGroups );
		if ( $privateAccessGroups ) {
			$privateAccessMessage = $this->out
				->msg( 'campaignevents-registration-confirmation-helptext-private-with-groups' )
				->params( $privateAccessGroupsText )
				->numParams( count( $privateAccessGroups ) );
		} else {
			$privateAccessMessage = $this->out
				->msg( 'campaignevents-registration-confirmation-helptext-private-no-groups' );
		}

		$this->out->addJsConfigVars( [
			'wgCampaignEventsEventID' => $registration->getID(),
			'wgCampaignEventsParticipantIsPublic' => $this->participantIsPublic,
			'wgCampaignEventsEventQuestions' => $this->getEventQuestionsData( $registration, $curParticipant ),
			'wgCampaignEventsAnswersAlreadyAggregated' => $hasAggregatedAnswers,
			'wgCampaignEventsAggregationTimestamp' => $aggregationTimestamp,
			'wgCampaignEventsRegistrationUpdated' => (bool)$registrationUpdatedVal,
			'wgCampaignEventsIsNewRegistration' => $isNewRegistration,
			'wgCampaignEventsRegistrationUpdatedWarnings' => $registrationUpdatedWarnings,
			'wgCampaignEventsIsTestRegistration' => $registration->getIsTestEvent(),
			'wgCampaignEventsPrivateAccessMessage' => $privateAccessMessage->parse(),
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
		$questionsToShow = EventQuestionsRegistry::getParticipantQuestionsToShow( $enabledQuestions, $curAnswers );

		$questionsData = [];
		$questionsAPI = $this->eventQuestionsRegistry->getQuestionsForAPI( $questionsToShow );
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
			'answers' => $this->eventQuestionsRegistry->formatAnswersForAPI( $curAnswers, $enabledQuestions )
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

	private function getEventInfoHeaderRow(
		ExistingEventRegistration $registration,
		int $userStatus
	): Tag {
		$eventID = $registration->getID();
		$items = [];

		$meetingType = $registration->getMeetingType();
		if ( $meetingType === EventRegistration::MEETING_TYPE_ONLINE_AND_IN_PERSON ) {
			$locationContent = $this->out->msg(
				MessageValue::new( 'campaignevents-eventpage-header-type-online-and-in-person' )
			)->escaped();
		} elseif ( $meetingType & EventRegistration::MEETING_TYPE_ONLINE ) {
			$locationContent = $this->out->msg(
				MessageValue::new( 'campaignevents-eventpage-header-type-online' )
			)->escaped();
		} else {
			// In-person event
			$address = $registration->getMeetingAddress();
			if ( $address !== null ) {
				$locationContent = Html::element(
					'div',
					[
						'dir' => Utils::guessStringDirection( $address ),
						'class' => [ 'ext-campaignevents-eventpage-header-address' ]
					],
					$this->language->truncateForVisual( $address, self::ADDRESS_MAX_LENGTH )
				);
			} else {
				$locationContent = $this->out->msg(
					MessageValue::new( 'campaignevents-eventpage-header-type-in-person' )
				)->escaped();
			}
		}
		$items[] = new HtmlSnippet( TextWithIconWidget::build(
			'map-pin',
			$this->msgFormatter->format(
				MessageValue::new( 'campaignevents-eventpage-header-location-label' )
			),
			$locationContent
		) );

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
		$formattedTimezone = EventTimeFormatter::wrapTimeZoneForConversion(
			$this->eventTimeFormatter->formatTimezone( $registration, $this->viewingUser )
		);
		// XXX Can't use ITextFormatter due to parse()
		$timezoneMsg = $this->out->msg( 'campaignevents-eventpage-header-timezone' )
			->params( $formattedTimezone )
			->parse();
		$items[] = new HtmlSnippet( TextWithIconWidget::build(
			'clock',
			$this->msgFormatter->format(
				MessageValue::new( 'campaignevents-eventpage-header-dates-label' )
			),
			EventTimeFormatter::wrapRangeForConversion( $registration, $datesMsg ) .
				Html::rawElement( 'div', [], $timezoneMsg ),
			[ 'ext-campaignevents-eventpage-header-time' ]
		) );

		$items[] = new HtmlSnippet( TextWithIconWidget::build(
			'user-group',
			$this->msgFormatter->format(
				MessageValue::new( 'campaignevents-eventpage-header-participants-label' )
			),
			$this->out->msg(
				MessageValue::new( 'campaignevents-eventpage-header-participants' )
					->numParams( $this->participantsStore->getFullParticipantCountForEvent( $eventID ) )
			)->escaped()
		) );

		$btnContainer = ( new Tag( 'div' ) )
			->addClasses( [ 'ext-campaignevents-eventpage-header-buttons' ] );
		$btnContainer->appendContent( new ButtonWidget( [
			'framed' => false,
			'flags' => [ 'progressive' ],
			'label' => $this->msgFormatter->format( MessageValue::new( 'campaignevents-eventpage-header-details' ) ),
			'classes' => [ 'ext-campaignevents-eventpage-details-btn' ],
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
	 * @param ProperPageIdentity $page
	 * @param ExistingEventRegistration $registration
	 * @param int $userStatus One of the self::USER_STATUS_* constants
	 * @param Participant|null $participant
	 * @return string
	 */
	private function getDetailsDialogContent(
		ProperPageIdentity $page,
		ExistingEventRegistration $registration,
		int $userStatus,
		?Participant $participant
	): string {
		$eventID = $registration->getID();
		$organizersCount = $this->organizersStore->getOrganizerCountForEvent( $eventID );

		$eventInfoContainer = $this->getDetailsDialogEventInfo(
			$page,
			$registration,
			$organizersCount,
			$userStatus
		);
		$participantsContainer = $this->getDetailsDialogParticipants(
			$eventID,
			$participant,
			$registration
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

		return Html::rawElement(
			'div',
			[ 'id' => 'ext-campaignevents-eventpage-details-dialog-content' ],
			$dialogContent
		);
	}

	private function getDetailsDialogOrganizers(
		int $eventID,
		int $organizersCount
	): string {
		$partialOrganizers = $this->organizersStore->getEventOrganizers( $eventID, self::ORGANIZERS_LIMIT );

		$organizerElements = [];
		foreach ( $partialOrganizers as $organizer ) {
			$organizerElements[] = $this->userLinker->generateUserLinkWithFallback(
				$this->out,
				$organizer->getUser(),
				$this->language->getCode()
			);
		}
		// XXX We need to use OutputPage here because there's no supported way to change the format of
		// MessageFormatterFactory...
		$organizersStr = $this->out->msg( 'campaignevents-eventpage-dialog-organizers' )
			->rawParams( $this->language->commaList( $organizerElements ) )
			->numParams( count( $organizerElements ) )
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

	private function getDetailsDialogEventInfo(
		ProperPageIdentity $page,
		ExistingEventRegistration $registration,
		int $organizersCount,
		int $userStatus
	): string {
		$eventInfo = $this->getDetailsDialogDates( $registration );
		$eventInfo .= $this->getDetailsDialogLocation(
			$page,
			$registration,
			$organizersCount,
			$userStatus
		);
		if ( $registration->getWikis() ) {
			$eventInfo .= $this->getDetailsDialogWikis( $registration );
		}

		$eventTopics = $registration->getTopics();
		if ( $eventTopics ) {
			$eventInfo .= $this->getDetailsDialogTopics( $eventTopics );
		}

		$eventInfo .= $this->getDetailsDialogChat( $page, $registration, $userStatus );

		return Html::rawElement(
			'div',
			[ 'class' => 'ext-campaignevents-detailsdialog-eventinfo-container' ],
			$eventInfo
		);
	}

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
		$formattedTimezone = EventTimeFormatter::wrapTimeZoneForConversion(
			$this->eventTimeFormatter->formatTimezone( $registration, $this->viewingUser )
		);
		// XXX Can't use $msgFormatter due to parse()
		$timezoneMsg = $this->out->msg( 'campaignevents-eventpage-dialog-timezone' )
			->params( $formattedTimezone )
			->parse();
		return $this->makeDetailsDialogSection(
			'clock',
			[
				new HtmlSnippet( EventTimeFormatter::wrapRangeForConversion( $registration, $datesMsg ) ),
				( new Tag( 'div' ) )->appendContent( new HtmlSnippet( $timezoneMsg ) )
			],
			$this->msgFormatter->format(
				MessageValue::new( 'campaignevents-eventpage-dialog-dates-label' )
			),
			'',
			[ 'ext-campaignevents-eventpage-detailsdialog-time' ]
		);
	}

	private function getDetailsDialogLocation(
		ProperPageIdentity $page,
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
				$linkContent = new HtmlSnippet(
					$this->linkRenderer->makeExternalLink( $meetingURL, $meetingURL, $page )
				);
			} elseif ( $userStatus === self::USER_STATUS_CAN_REGISTER ) {
				$linkContent = $this->msgFormatter->format(
					MessageValue::new( 'campaignevents-eventpage-dialog-link-register' )
				);
			} elseif ( $userStatus === self::USER_STATUS_BLOCKED ) {
				$linkContent = $this->msgFormatter->format(
					MessageValue::new( 'campaignevents-event-details-sensitive-data-message-blocked-user' )
				);
			} elseif (
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

	private function getDetailsDialogChat(
		ProperPageIdentity $page,
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
			$chatURLContent = new HtmlSnippet(
				$this->linkRenderer->makeExternalLink( $chatURL, $chatURL, $page )
			);
		} elseif ( $userStatus === self::USER_STATUS_CAN_REGISTER ) {
			$chatURLContent = $this->msgFormatter->format(
				MessageValue::new( 'campaignevents-eventpage-dialog-chat-register' )
			);
		} elseif (
			$userStatus === self::USER_STATUS_BLOCKED
		) {
			$chatURLContent = $this->msgFormatter->format(
				MessageValue::new( 'campaignevents-event-details-sensitive-data-message-blocked-user' )
			);
		} elseif (
			$userStatus === self::USER_STATUS_CANNOT_REGISTER_CLOSED ||
			$userStatus === self::USER_STATUS_CANNOT_REGISTER_ENDED
		) {
			$chatURLContent = '';
		} else {
			throw new LogicException( "Unexpected user status $userStatus" );
		}

		if ( $chatURLContent ) {
			return $this->makeDetailsDialogSection(
				'speechBubbles',
				$chatURLContent,
				$this->msgFormatter->format(
					MessageValue::new( 'campaignevents-eventpage-dialog-chat-label' )
				)
			);
		}
		return '';
	}

	private function getDetailsDialogParticipants(
		int $eventID,
		?Participant $participant,
		ExistingEventRegistration $registration
	): string {
		$showPrivateParticipants = $this->permissionChecker->userCanViewPrivateParticipants(
			$this->authority,
			$registration
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

		$privateCountFooter = new Tag();
		if ( $privateCount > 0 ) {
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

	private function getParticipantRows(
		int $eventID,
		?Participant $curUserParticipant,
		bool $showPrivateParticipants
	): ?Tag {
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

		$orderedParticipants = $curUserParticipant ? [ $curUserParticipant ] : [];
		$orderedParticipants = array_merge( $orderedParticipants, $partialParticipants );
		if ( !$orderedParticipants ) {
			return null;
		}

		$participantsList = ( new Tag( 'ul' ) )
			->addClasses( [ 'ext-campaignevents-detailsdialog-participants-list' ] );
		foreach ( $orderedParticipants as $participant ) {
			$participantsList->appendContent( $this->getParticipantRow( $participant ) );
		}
		return $participantsList;
	}

	/**
	 * Returns the "action" element for the header (that are also cloned into the popup). This can be a button for
	 * managing the event, or one to register for it. Or it can be a widget informing the user that they are already
	 * registered, with a button to unregister. There can also be no element if the user is not allowed to register.
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
				switch ( $checkUnregistrationAllowedVal->getValue() ) {
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
		$checkRegistrationAllowedVal = RegisterParticipantCommand::checkIsRegistrationAllowed(
			$event,
			RegisterParticipantCommand::REGISTRATION_NEW
		);
		switch ( $checkRegistrationAllowedVal->value ) {
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

	private function getParticipantRow( Participant $participant ): Tag {
		$usernameElement = new HtmlSnippet(
			$this->userLinker->generateUserLinkWithFallback(
				$this->out,
				$participant->getUser(),
				$this->language->getCode()
			)
		);

		$tag = ( new Tag( 'li' ) )
			->appendContent( $usernameElement );

		if ( $participant->isPrivateRegistration() ) {
			$this->addPrivateParticipantNotice( $participant, $tag );
		}

		return $tag;
	}

	/**
	 * @param string $icon
	 * @param string|Tag|array|HtmlSnippet $content
	 * @param string $label
	 * @param string|Tag|array $footer
	 * @param string[] $classes
	 * @return string
	 */
	private function makeDetailsDialogSection(
		string $icon,
		$content,
		string $label,
		$footer = '',
		array $classes = []
	): string {
		$iconWidget = new IconWidget( [
			'icon' => $icon,
			'classes' => [ 'ext-campaignevents-eventpage-detailsdialog-section-icon' ]
		] );
		$header = ( new Tag( 'h3' ) )
			->appendContent( $iconWidget, ( new Tag( 'span' ) )->appendContent( $label ) )
			->addClasses( [ 'ext-campaignevents-eventpage-detailsdialog-section-header' ] );

		$contentTag = ( new Tag( 'div' ) )
			->appendContent( $content )
			->addClasses( [ 'ext-campaignevents-eventpage-detailsdialog-section-content', ...$classes ] );

		return (string)( new Tag( 'div' ) )
			->appendContent( $header, $contentTag, $footer );
	}

	private function getDetailsDialogWikis( ExistingEventRegistration $registration ): string {
		$content = EventFormatter::formatWikis(
			$registration,
			$this->msgFormatter,
			$this->wikiLookup,
			$this->language,
			$this->linkRenderer,
			'campaignevents-eventpage-all-wikis',
			'campaignevents-eventpage-wikis-more',
		);
		return $this->makeDetailsDialogSection(
			$this->wikiLookup->getWikiIcon( $registration->getWikis() ),
			$content,
			$this->msgFormatter->format(
				MessageValue::new( 'campaignevents-eventpage-dialog-wikis-label' )
			)
		);
	}

	/**
	 * @param array $eventTopics
	 * @return string
	 */
	private function getDetailsDialogTopics( array $eventTopics ): string {
		$localizedTopicNames = array_map(
			fn ( string $msgKey ) => $this->msgFormatter->format(
				MessageValue::new( $msgKey )
			),
			$this->topicRegistry->getTopicMessages( $eventTopics )
		);
		sort( $localizedTopicNames );
		$content = $this->language->commaList( $localizedTopicNames );

		return $this->makeDetailsDialogSection(
			'tag',
			$content,
			$this->msgFormatter->format(
				MessageValue::new( 'campaignevents-eventpage-dialog-topics-label' )
			)
		);
	}

	private function addPrivateParticipantNotice( Participant $participant, Tag $tag ): void {
		try {
			$userName = $this->centralUserLookup->getUserName( $participant->getUser() );
		} catch ( CentralUserNotFoundException | HiddenCentralUserException $_ ) {
			// Hack: use an invalid username to force unspecified gender
			$userName = '@';
		}
		$labelText = $this->msgFormatter->format(
			MessageValue::new( 'campaignevents-eventpage-dialog-private-registration-label' )->params( $userName )
		);
		$tag->appendContent( new IconWidget( [
			'icon' => 'lock',
			'title' => $labelText,
			'label' => $labelText,
			'classes' => [ 'ext-campaignevents-event-details-participants-private-icon' ]
		] ) );
	}
}
