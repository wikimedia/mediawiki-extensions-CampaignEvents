<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\EventPage;

use LogicException;
use MediaWiki\Config\Config;
use MediaWiki\Extension\CampaignEvents\Address\CountryProvider;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\PageEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFactory;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
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
use MediaWiki\Extension\CampaignEvents\Special\AbstractEventRegistrationSpecialPage;
use MediaWiki\Extension\CampaignEvents\Special\SpecialCancelEventRegistration;
use MediaWiki\Extension\CampaignEvents\Special\SpecialEditEventRegistration;
use MediaWiki\Extension\CampaignEvents\Special\SpecialEnableEventRegistration;
use MediaWiki\Extension\CampaignEvents\Special\SpecialEventDetails;
use MediaWiki\Extension\CampaignEvents\Special\SpecialRegisterForEvent;
use MediaWiki\Extension\CampaignEvents\Time\EventTimeFormatter;
use MediaWiki\Extension\CampaignEvents\Utils;
use MediaWiki\Extension\CampaignEvents\Widget\TextWithIconWidget;
use MediaWiki\Html\Html;
use MediaWiki\Language\Language;
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

	// Constants for the different statuses of a user wrt a given event registration
	private const USER_STATUS_BLOCKED = 1;
	private const USER_STATUS_ORGANIZER = 2;
	private const USER_STATUS_PARTICIPANT_CAN_UNREGISTER = 3;
	private const USER_STATUS_CAN_REGISTER = 4;
	private const USER_STATUS_CANNOT_REGISTER_ENDED = 5;
	private const USER_STATUS_CANNOT_REGISTER_CLOSED = 6;

	private UserIdentity $viewingUser;
	private ITextFormatter $msgFormatter;

	/**
	 * @var bool|null Whether the user is registered publicly or privately. This value is lazy-loaded iff the user
	 * status is USER_STATUS_PARTICIPANT_CAN_UNREGISTER.
	 */
	private ?bool $participantIsPublic = null;

	public function __construct(
		private readonly PageEventLookup $pageEventLookup,
		private readonly ParticipantsStore $participantsStore,
		private readonly OrganizersStore $organizersStore,
		private readonly PermissionChecker $permissionChecker,
		IMessageFormatterFactory $messageFormatterFactory,
		private readonly CampaignsPageFactory $campaignsPageFactory,
		private readonly CampaignsCentralUserLookup $centralUserLookup,
		private readonly EventTimeFormatter $eventTimeFormatter,
		private readonly EventPageCacheUpdater $eventPageCacheUpdater,
		private readonly EventQuestionsRegistry $eventQuestionsRegistry,
		private readonly GroupPermissionsLookup $groupPermissionsLookup,
		private readonly Config $config,
		private readonly CountryProvider $countryProvider,
		private readonly Language $language,
		private readonly Authority $authority,
		private readonly OutputPage $out,
	) {
		$this->viewingUser = $authority->getUser();
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
			$this->addRegistrationHeader( $registration );
			$this->eventPageCacheUpdater->adjustCacheForPageWithRegistration( $this->out, $registration );
		} else {
			$campaignsPage = $this->campaignsPageFactory->newFromLocalMediaWikiPage( $page );
			$this->maybeAddEnableRegistrationHeader( $campaignsPage );
		}
	}

	private function maybeAddEnableRegistrationHeader( MWPageProxy $eventPage ): void {
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

	private function getEnableRegistrationHeader( string $enableRegistrationURL ): string {
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
		return $layout->toString();
	}

	private function addRegistrationHeader( ExistingEventRegistration $registration ): void {
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
		} catch ( UserNotGlobalException ) {
			$centralUser = null;
			$curParticipant = null;
			$hasAggregatedAnswers = false;
		}

		$userStatus = $this->getUserStatus( $registration, $centralUser, $curParticipant );

		$this->out->addHTML( $this->getHeaderElement( $registration, $userStatus )->toString() );

		if ( $curParticipant ) {
			$aggregationTimestamp = Utils::getAnswerAggregationTimestamp( $curParticipant, $registration );
			$showContributionPrompt = $curParticipant->shouldShowContributionAssociationPrompt();
		} else {
			$aggregationTimestamp = null;
			$showContributionPrompt = true;
		}

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

		$showContributionPromptSection = $curParticipant &&
			$registration->hasContributionTracking() &&
			!$registration->isPast();

		$contributionsTabURL = SpecialPage::getTitleFor(
			SpecialEventDetails::PAGE_NAME,
			(string)$registration->getID()
		)->getFullURL( [ 'tab' => SpecialEventDetails::CONTRIBUTIONS_PANEL ] );

		$this->out->addJsConfigVars( [
			'wgCampaignEventsEventID' => $registration->getID(),
			'wgCampaignEventsParticipantIsPublic' => $this->participantIsPublic,
			'wgCampaignEventsEventQuestions' => $this->getEventQuestionsData( $registration, $curParticipant ),
			'wgCampaignEventsAnswersAlreadyAggregated' => $hasAggregatedAnswers,
			'wgCampaignEventsAggregationTimestamp' => $aggregationTimestamp,
			'wgCampaignEventsParticipantShowContributionPrompt' => $showContributionPrompt,
			'wgCampaignEventsRegistrationUpdated' => (bool)$registrationUpdatedVal,
			'wgCampaignEventsIsNewRegistration' => $isNewRegistration,
			'wgCampaignEventsRegistrationUpdatedWarnings' => $registrationUpdatedWarnings,
			'wgCampaignEventsIsTestRegistration' => $registration->getIsTestEvent(),
			'wgCampaignEventsPrivateAccessMessage' => $privateAccessMessage->parse(),
			'wgCampaignEventsContributionsEnabled' => $showContributionPromptSection,
			'wgCampaignEventsContributionsTabURL' => $contributionsTabURL,
		] );
	}

	/**
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

		$participationOptions = $registration->getParticipationOptions();
		if ( $participationOptions & EventRegistration::PARTICIPATION_OPTION_IN_PERSON ) {
			// In-person event
			$address = $registration->getAddressOrThrow();
			// Unlike other places, here we want the address without country to be on a single line, and truncate it
			// if necessary; and then a separate line for the country.
			$participationOptionsContent = '';
			$addressWithoutCountry = $address->getAddressWithoutCountry();
			if ( $addressWithoutCountry ) {
				$oneLinedAddress = strtr( $addressWithoutCountry, "\n", ' ' );
				$participationOptionsContent .= Html::element(
					'div',
					[
						'dir' => Utils::guessStringDirection( $addressWithoutCountry ),
						'class' => [ 'ext-campaignevents-eventpage-header-address' ]
					],
					$this->language->truncateForVisual( $oneLinedAddress, self::ADDRESS_MAX_LENGTH )
				);
			}

			$formattedCountry = $this->countryProvider
				->getCountryName( $address->getCountryCode(), $this->language->getCode() );
			$participationOptionsContent .= Html::element( 'div', [], $formattedCountry );

			// Add a line about online participation for hybrid events.
			if ( $participationOptions & EventRegistration::PARTICIPATION_OPTION_ONLINE ) {
				$participationOptionsContent .= $this->out->msg(
					MessageValue::new( 'campaignevents-eventpage-header-participation-options-online-and-in-person' )
				)->escaped();
			}
		} elseif ( $participationOptions & EventRegistration::PARTICIPATION_OPTION_ONLINE ) {
			$participationOptionsContent = $this->out->msg(
				MessageValue::new( 'campaignevents-eventpage-header-participation-options-online' )
			)->escaped();
		} else {
			throw new LogicException( 'There must be at least one participation option' );
		}

		$items[] = new HtmlSnippet( TextWithIconWidget::build(
			'map-pin',
			$this->msgFormatter->format(
				MessageValue::new( 'campaignevents-eventpage-header-participation-options-label' )
			),
			$participationOptionsContent
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
					SpecialEditEventRegistration::PAGE_NAME,
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
			if ( $this->authority->getBlock()?->isSitewide() ) {
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
}
