<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Special;

use DateTime;
use DateTimeZone;
use Exception;
use LogicException;
use MediaWiki\Config\Config;
use MediaWiki\Extension\CampaignEvents\Address\CountryProvider;
use MediaWiki\Extension\CampaignEvents\Event\EditEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\EventFactory;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\EventTypesRegistry;
use MediaWiki\Extension\CampaignEvents\Event\InvalidEventDataException;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\Hooks\CampaignEventsHookRunner;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUserNotFoundException;
use MediaWiki\Extension\CampaignEvents\MWEntity\HiddenCentralUserException;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWPageProxy;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageURLResolver;
use MediaWiki\Extension\CampaignEvents\MWEntity\WikiLookup;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\PolicyMessagesLookup;
use MediaWiki\Extension\CampaignEvents\Questions\EventQuestionsRegistry;
use MediaWiki\Extension\CampaignEvents\Questions\UnknownQuestionException;
use MediaWiki\Extension\CampaignEvents\Topics\ITopicRegistry;
use MediaWiki\Extension\CampaignEvents\TrackingTool\InvalidToolURLException;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolRegistry;
use MediaWiki\Extension\CampaignEvents\Utils;
use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Message\Message;
use MediaWiki\SpecialPage\FormSpecialPage;
use MediaWiki\Status\Status;
use MediaWiki\User\UserTimeCorrection;
use OOUI\FieldLayout;
use OOUI\HtmlSnippet;
use OOUI\MessageWidget;
use RuntimeException;
use StatusValue;
use Wikimedia\Message\MessageSpecifier;
use Wikimedia\RequestTimeout\TimeoutException;

abstract class AbstractEventRegistrationSpecialPage extends FormSpecialPage {
	private const PAGE_FIELD_NAME_HTMLFORM = 'EventPage';
	public const PAGE_FIELD_NAME = 'wp' . self::PAGE_FIELD_NAME_HTMLFORM;
	public const DETAILS_SECTION = 'campaignevents-edit-form-details-label';
	private const PARTICIPANT_QUESTIONS_SECTION = 'campaignevents-edit-form-questions-label';

	public const REGISTRATION_UPDATED_SESSION_KEY = 'campaignevents-registration-updated';
	public const REGISTRATION_UPDATED_SESSION_ENABLED = 1;
	public const REGISTRATION_UPDATED_SESSION_UPDATED = 2;
	public const REGISTRATION_UPDATED_WARNINGS_SESSION_KEY = 'campaignevents-registration-updated-warnings';

	private const WIKI_TYPE_NONE = 1;
	private const WIKI_TYPE_ALL = 2;
	private const WIKI_TYPE_SPECIFIC = 3;

	/** @var array<string,string> */
	private array $formMessages;
	protected IEventLookup $eventLookup;
	private EventFactory $eventFactory;
	private EditEventCommand $editEventCommand;
	private PolicyMessagesLookup $policyMessagesLookup;
	private OrganizersStore $organizersStore;
	protected PermissionChecker $permissionChecker;
	private CampaignsCentralUserLookup $centralUserLookup;
	private TrackingToolRegistry $trackingToolRegistry;
	private EventQuestionsRegistry $eventQuestionsRegistry;
	private CampaignEventsHookRunner $hookRunner;
	private PageURLResolver $pageUrlResolver;
	private WikiLookup $wikiLookup;
	private ITopicRegistry $topicRegistry;
	private Config $wikiConfig;
	private EventTypesRegistry $eventTypesRegistry;
	private CountryProvider $countryProvider;

	protected ?int $eventID = null;
	protected ?EventRegistration $event = null;

	/**
	 * @var MWPageProxy|null The event page, set upon form submission and guaranteed to be set on success.
	 */
	protected ?MWPageProxy $eventPage = null;
	/**
	 * @var string[] Usernames of invalid organizers, used for live validation in JavaScript.
	 */
	private array $invalidOrganizerNames = [];
	/**
	 * @var StatusValue|null Status with warnings from the update. Guaranteed to be set upon successful form submission.
	 */
	protected ?StatusValue $saveWarningsStatus = null;

	public function __construct(
		string $name,
		string $restriction,
		IEventLookup $eventLookup,
		EventFactory $eventFactory,
		EditEventCommand $editEventCommand,
		PolicyMessagesLookup $policyMessagesLookup,
		OrganizersStore $organizersStore,
		PermissionChecker $permissionChecker,
		CampaignsCentralUserLookup $centralUserLookup,
		TrackingToolRegistry $trackingToolRegistry,
		EventQuestionsRegistry $eventQuestionsRegistry,
		CampaignEventsHookRunner $hookRunner,
		PageURLResolver $pageURLResolver,
		WikiLookup $wikiLookup,
		ITopicRegistry $topicRegistry,
		Config $wikiConfig,
		EventTypesRegistry $eventTypesRegistry,
		CountryProvider $countryProvider
	) {
		parent::__construct( $name, $restriction );
		$this->eventLookup = $eventLookup;
		$this->eventFactory = $eventFactory;
		$this->editEventCommand = $editEventCommand;
		$this->policyMessagesLookup = $policyMessagesLookup;
		$this->organizersStore = $organizersStore;
		$this->permissionChecker = $permissionChecker;
		$this->centralUserLookup = $centralUserLookup;
		$this->trackingToolRegistry = $trackingToolRegistry;
		$this->eventQuestionsRegistry = $eventQuestionsRegistry;
		$this->hookRunner = $hookRunner;
		$this->pageUrlResolver = $pageURLResolver;
		$this->wikiLookup = $wikiLookup;
		$this->topicRegistry = $topicRegistry;
		$this->wikiConfig = $wikiConfig;
		$this->eventTypesRegistry = $eventTypesRegistry;
		$this->countryProvider = $countryProvider;

		$this->formMessages = $this->getFormMessages();
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $par ): void {
		$this->requireNamedUser();
		$this->addHelpLink( 'Help:Extension:CampaignEvents/Registration' );
		$this->getOutput()->addModules( [
			'ext.campaignEvents.specialPages',
		] );
		$this->getOutput()->addModuleStyles( [
			'ext.campaignEvents.specialPages.styles',
		] );

		if ( $this->eventID ) {
			$eventCreator = $this->organizersStore->getEventCreator(
				$this->eventID,
				OrganizersStore::GET_CREATOR_INCLUDE_DELETED
			);
			if ( !$eventCreator ) {
				throw new RuntimeException( "Did not find event creator." );
			}
			try {
				$eventCreatorUsername = $this->centralUserLookup->getUserName( $eventCreator->getUser() );
			} catch ( CentralUserNotFoundException | HiddenCentralUserException $_ ) {
				$eventCreatorUsername = null;
			}
			$performerUserName = $this->getAuthority()->getUser()->getName();
			$isEventCreator = $performerUserName === $eventCreatorUsername;
		} else {
			$isEventCreator = true;
			$eventCreatorUsername = $this->getAuthority()->getUser()->getName();
		}

		$this->getOutput()->addJsConfigVars( [
			'wgCampaignEventsIsEventCreator' => $isEventCreator,
			'wgCampaignEventsEventCreatorUsername' => $eventCreatorUsername,
			'wgCampaignEventsEventID' => $this->eventID,
			'wgCampaignEventsIsPastEvent' => $this->event && $this->event->isPast(),
			'wgCampaignEventsEventHasAnswers' => $this->event &&
				$this->editEventCommand->eventHasAnswersOrAggregates( $this->eventID ),
		] );
		// By default, OOUI is only enabled upon showing the form. But since we're using MessageWidget directly in a
		// couple places, we need to manually enable OOUI now (T354384).
		$this->getOutput()->enableOOUI();

		parent::execute( $par );
		// Note: this has to be added after parent::execute, which is where the validation runs.
		$this->getOutput()->addJsConfigVars( [
			'wgCampaignEventsInvalidOrganizers' => $this->invalidOrganizerNames
		] );
	}

	/**
	 * @param string $errorMsg
	 * @param mixed ...$msgParams
	 * @suppress PhanPluginUnknownArrayMethodParamType,UnusedSuppression https://github.com/phan/phan/issues/4927
	 */
	protected function outputErrorBox( string $errorMsg, mixed ...$msgParams ): void {
		$this->setHeaders();
		$this->getOutput()->addModuleStyles( [
			'mediawiki.codex.messagebox.styles',
		] );
		$this->getOutput()->addHTML( Html::errorBox(
			$this->msg( $errorMsg )->params( ...$msgParams )->parseAsBlock()
		) );
	}

	/**
	 * Returns messages to be used on the page. These must not use markup or take any parameter.
	 * @phan-return array{details-section-subtitle:string,submit:string}
	 * @return array
	 */
	abstract protected function getFormMessages(): array;

	/**
	 * @inheritDoc
	 * @return array<string,array<string,mixed>>
	 */
	protected function getFormFields(): array {
		$eventPageDefault = null;
		if ( $this->event ) {
			$eventPageDefault = $this->event->getPage()->getPrefixedText();
		}

		$formFields = [];

		$pageFieldSpecs = [
			'type' => 'title',
			'label-message' => 'campaignevents-edit-field-page',
			'exists' => true,
			'default' => $eventPageDefault,
			'help-message' => 'campaignevents-edit-field-page-help',
			'help-inline' => false,
			'required' => true,
			'section' => self::DETAILS_SECTION,
		];
		$allowedEventNamespaces = $this->wikiConfig->get( 'CampaignEventsEventNamespaces' );
		if ( $this->event ) {
			// Consider the namespace of the current event page as valid, see T387967#10704403. This way we can allow
			// edits that don't change the event page.
			$allowedEventNamespaces = array_unique( [
				...$allowedEventNamespaces,
				$this->event->getPage()->getNamespace()
			] );
		}
		if ( count( $allowedEventNamespaces ) === 1 ) {
			// T389078: can't filter by multiple namespaces.
			$pageFieldSpecs['namespace'] = $allowedEventNamespaces[0];
		}
		$formFields[self::PAGE_FIELD_NAME_HTMLFORM] = $pageFieldSpecs;

		if ( $this->event ) {
			$formFields['EventStatus'] = [
				'type' => 'select',
				'label-message' => 'campaignevents-edit-field-event-status',
				'default' => $this->event->getStatus(),
				'options-messages' => [
					'campaignevents-edit-field-status-open' => EventRegistration::STATUS_OPEN,
					'campaignevents-edit-field-status-closed' => EventRegistration::STATUS_CLOSED,
				],
				'required' => true,
				'section' => self::DETAILS_SECTION,
			];
		}

		if ( $this->event ) {
			$defaultTimezone = self::convertTimezoneForForm( $this->event->getTimezone() );
		} else {
			$defaultTimezone = '+00:00';
		}

		$formFields['TimeZone'] = [
			'type' => 'timezone',
			'label-message' => 'campaignevents-edit-field-timezone',
			'default' => $defaultTimezone,
			'required' => true,
			'cssclass' => 'ext-campaignevents-timezone-input',
			'section' => self::DETAILS_SECTION,
		];

		$timezone = $this->getTimezone();
		$curLocalTime = ( new DateTime( 'now', $timezone ) )->format( 'Y-m-d H:i:s' );
		$minTime = $this->event ? '' : wfTimestamp( TS_MW, $curLocalTime );
		$maxTime = $this->eventID && $this->event->isPast() &&
			$this->editEventCommand->eventHasAnswersOrAggregates( $this->eventID ) ?
				wfTimestamp( TS_MW, $curLocalTime ) :
				'';

		// Disable auto-infusion because we want to change the configuration.
		$timeFieldClasses = 'ext-campaignevents-time-input mw-htmlform-autoinfuse-lazy';
		$formFields['EventStart'] = [
			'type' => 'datetime',
			'label-message' => 'campaignevents-edit-field-start',
			'min' => $minTime,
			'max' => $maxTime,
			'default' => $this->event ? wfTimestamp( TS_ISO_8601, $this->event->getStartLocalTimestamp() ) : '',
			'required' => true,
			'section' => self::DETAILS_SECTION,
			'cssclass' => 'ext-campaignevents-time-input-event-start ' . $timeFieldClasses,
		];
		$formFields['EventEnd'] = [
			'type' => 'datetime',
			'label-message' => 'campaignevents-edit-field-end',
			'min' => $minTime,
			'max' => $maxTime,
			'default' => $this->event ? wfTimestamp( TS_ISO_8601, $this->event->getEndLocalTimestamp() ) : '',
			'required' => true,
			'section' => self::DETAILS_SECTION,
			'cssclass' => 'ext-campaignevents-time-input-event-end ' . $timeFieldClasses,
		];

		$formFields['EventOrganizerUsernames'] = [
			'type' => 'usersmultiselect',
			'label-message' => 'campaignevents-edit-field-organizers',
			'default' => implode( "\n", $this->getOrganizerUsernames() ),
			'exists' => true,
			'help-message' => 'campaignevents-edit-field-organizers-help',
			'max' => EditEventCommand::MAX_ORGANIZERS_PER_EVENT,
			'min' => 1,
			'cssclass' => 'ext-campaignevents-organizers-multiselect-input',
			'placeholder-message' => 'campaignevents-edit-field-organizers-placeholder',
			/**
			 * @param mixed $value
			 * @param array<string,mixed> $alldata
			 * @return Message|true
			 */
			'validation-callback' => function ( mixed $value, array $alldata ): Message|bool {
				$organizers = $alldata['EventOrganizerUsernames'] !== ''
					? explode( "\n", $alldata['EventOrganizerUsernames'] )
					: [];
				$validationStatus = $this->editEventCommand->validateOrganizers( $organizers );

				if ( !$validationStatus->isGood() ) {
					if ( $validationStatus->getValue() ) {
						$this->invalidOrganizerNames = $validationStatus->getValue();
					}
					$msg = $validationStatus->getMessages()[0];
					return $this->msg( $msg );
				}

				return true;
			},
			'section' => self::DETAILS_SECTION,
		];

		$formFields['EventTypes'] = [
			'type' => 'multiselect',
			'dropdown' => true,
			'label-message' => 'campaignevents-edit-field-eventtypes-label',
			'default' => $this->event ? $this->event->getTypes() : [],
			'options-messages' => $this->eventTypesRegistry->getAllOptionMessages(),
			'placeholder-message' => 'campaignevents-edit-field-eventtypes-placeholder',
			'help' => $this->msg( 'campaignevents-edit-field-eventtypes-other-help' )->escaped(),
			'cssclass' => 'ext-campaignevents-edit-eventtypes-input',
			'section' => self::DETAILS_SECTION,
			'required' => true,
			'max' => EventFactory::MAX_TYPES,
		];

		$eventWikis = $this->event ? $this->event->getWikis() : [];
		if ( $eventWikis === [] ) {
			$defaultWikiType = self::WIKI_TYPE_NONE;
		} elseif ( $eventWikis === EventRegistration::ALL_WIKIS ) {
			$defaultWikiType = self::WIKI_TYPE_ALL;
		} else {
			$defaultWikiType = self::WIKI_TYPE_SPECIFIC;
		}
		$formFields['WikiType'] = [
			'type' => 'radio',
			'label-message' => 'campaignevents-edit-field-wiki-type',
			'options-messages' => [
				'campaignevents-edit-field-wiki-type-none' => self::WIKI_TYPE_NONE,
				'campaignevents-edit-field-wiki-type-all' => self::WIKI_TYPE_ALL,
				'campaignevents-edit-field-wiki-type-specific' => self::WIKI_TYPE_SPECIFIC,
			],
			'default' => $defaultWikiType,
			'required' => true,
			'section' => self::DETAILS_SECTION,
		];
		$formFields['Wikis'] = [
			'type' => 'multiselect',
			'dropdown' => true,
			'label-message' => 'campaignevents-edit-field-wikis-label',
			'default' => is_array( $eventWikis ) ? $eventWikis : [],
			'options' => $this->wikiLookup->getListForSelect(),
			'max' => EventFactory::MAX_WIKIS,
			'placeholder-message' => 'campaignevents-edit-field-wikis-placeholder',
			'help-message' => 'campaignevents-edit-field-wikis-help',
			'hide-if' => [ '!==', 'WikiType', (string)self::WIKI_TYPE_SPECIFIC ],
			/**
			 * @param mixed $value
			 * @param array<string,mixed> $alldata
			 * @return Message|true
			 */
			'validation-callback' => function ( mixed $value, array $alldata ): Message|bool {
				if ( $value === [] && $alldata['WikiType'] === (string)self::WIKI_TYPE_SPECIFIC ) {
					return $this->msg( 'campaignevents-edit-field-wikis-empty-specific' );
				}
				return true;
			},
			'cssclass' => 'ext-campaignevents-edit-wikis-input',
			'section' => self::DETAILS_SECTION,
		];

		$availableTopics = $this->topicRegistry->getTopicsForSelect();
		if ( $availableTopics ) {
			$formFields['Topics'] = [
				'type' => 'multiselect',
				'dropdown' => true,
				'label-message' => 'campaignevents-edit-field-topics-label',
				'default' => $this->event ? $this->event->getTopics() : [],
				'options-messages' => $availableTopics,
				'placeholder-message' => 'campaignevents-edit-field-topics-placeholder',
				'help' => $this->msg( 'campaignevents-edit-field-topics-help' )
					->numParams( EventFactory::MAX_TOPICS )->escaped(),
				'cssclass' => 'ext-campaignevents-edit-topics-input',
				'section' => self::DETAILS_SECTION,
				'max' => EventFactory::MAX_TOPICS
			];
		}

		$availableTrackingTools = $this->trackingToolRegistry->getDataForForm();
		if ( $availableTrackingTools ) {
			if (
				count( $availableTrackingTools ) > 1 ||
				$availableTrackingTools[0]['user-id'] !== 'wikimedia-pe-dashboard'
			) {
				throw new LogicException( "Only the P&E Dashboard should be available as a tool for now" );
			}
			$formFields['EventTrackingToolID'] = [
				'type' => 'hidden',
				'default' => 'wikimedia-pe-dashboard',
				'section' => self::DETAILS_SECTION,
			];
			if ( $this->event ) {
				$curTrackingTools = $this->event->getTrackingTools();
				if ( $curTrackingTools ) {
					if (
						count( $curTrackingTools ) > 1 ||
						$curTrackingTools[0]->getToolID() !== 1
					) {
						throw new LogicException( "Only the P&E Dashboard should be available as a tool for now" );
					}
					$userInfo = $this->trackingToolRegistry->getUserInfo(
						$curTrackingTools[0]->getToolID(),
						$curTrackingTools[0]->getToolEventID()
					);
					$defaultDashboardURL = $userInfo['tool-event-url'];
				} else {
					$defaultDashboardURL = '';
				}
			} else {
				$defaultDashboardURL = '';
			}
			$formFields['EventDashboardURL'] = [
				'type' => 'url',
				'label-message' => 'campaignevents-edit-field-tracking-tools',
				'default' => $defaultDashboardURL,
				'help-message' => 'campaignevents-edit-field-tracking-tools-help',
				'placeholder-message' => 'campaignevents-edit-field-tracking-tools-placeholder',
				/**
				 * @param mixed $value
				 * @param array<string,mixed> $allData
				 * @return Message|true
				 */
				'validation-callback' => function ( mixed $value, array $allData ): Message|bool {
					if ( $value === '' ) {
						return true;
					}
					try {
						$this->trackingToolRegistry->getToolEventIDFromURL( $allData['EventTrackingToolID'], $value );
						return true;
					} catch ( InvalidToolURLException $e ) {
						$baseURL = rtrim( $e->getExpectedBaseURL(), '/' ) . '/courses';
						return $this->msg( 'campaignevents-error-invalid-dashboard-url' )->params( $baseURL );
					}
				},
				'section' => self::DETAILS_SECTION,
			];
		}

		// TODO: Maybe consider dropping the default when switching to Codex, if that allows indeterminate radios.
		$defaultParticipationOptions = EventRegistration::PARTICIPATION_OPTION_ONLINE;
		$formFields['ParticipationOptions'] = [
			'type' => 'radio',
			'label-message' => 'campaignevents-edit-field-participation-options',
			'options-messages' => [
				'campaignevents-edit-field-type-online' => EventRegistration::PARTICIPATION_OPTION_ONLINE,
				'campaignevents-edit-field-type-in-person' => EventRegistration::PARTICIPATION_OPTION_IN_PERSON,
				'campaignevents-edit-field-type-online-and-in-person' =>
					EventRegistration::PARTICIPATION_OPTION_ONLINE_AND_IN_PERSON
			],
			'default' => $this->event ? $this->event->getParticipationOptions() : $defaultParticipationOptions,
			'required' => true,
			'section' => self::DETAILS_SECTION,
		];

		$formFields['EventMeetingURL'] = [
			'type' => 'url',
			'label-message' => 'campaignevents-edit-field-meeting-url',
			'hide-if' => [ '===', 'ParticipationOptions', (string)EventRegistration::PARTICIPATION_OPTION_IN_PERSON ],
			'default' => $this->event ? $this->event->getMeetingURL() : '',
			'section' => self::DETAILS_SECTION,
		];

		$address = $this->event ? $this->event->getAddress() : null;
		if (
			$this->getOutput()->getConfig()->get( 'CampaignEventsCountrySchemaMigrationStage' ) &
			SCHEMA_COMPAT_WRITE_NEW
		) {
			$languageCode = $this->getLanguage()->getCode();
			$countryNames = $this->countryProvider->getAvailableCountries( $languageCode );
			asort( $countryNames );
			$countryOptions = [
				$this->msg( 'campaignevents-edit-field-country-placeholder' )->text() => ''
			];
			$countryOptions += array_flip( $countryNames );
			$formFields['EventMeetingCountryCode'] = [
				'type' => 'select',
				'label-message' => 'campaignevents-edit-field-country',
				'hide-if' => [ '===', 'ParticipationOptions', (string)EventRegistration::PARTICIPATION_OPTION_ONLINE ],
				// NOTE: If we have no country code (because the row is still in the old format, we will lose data here
				// by forcing the default. However, the field is required, so the organizer will still need to choose a
				// country code before saving, and they can match the existing free-text value. This is preferable to
				// just preventing the edit until the row has been migrated, or to try and match the country in
				// real-time as done in the migration script (which is potentially expensive).
				'default' => $address ? $address->getCountryCode() : '',
				'options' => $countryOptions,
				'required' => true,
				'section' => self::DETAILS_SECTION,
			];
		} else {
			if ( $address && $address->getCountryCode() && !$address->getCountry() ) {
				// Make sure we aren't losing information. Should never happen unless we revert the migration.
				throw new RuntimeException( 'Got country code without country in WRITE_OLD' );
			}
			$formFields['EventMeetingCountry'] = [
				'type' => 'text',
				'label-message' => 'campaignevents-edit-field-country',
				'hide-if' => [ '===', 'ParticipationOptions', (string)EventRegistration::PARTICIPATION_OPTION_ONLINE ],
				'default' => $address ? $address->getCountry() : '',
				'maxlength' => EventFactory::COUNTRY_MAXLENGTH_BYTES,
				'section' => self::DETAILS_SECTION,
			];
		}

		// Note that we're using length limit in bytes for `maxlength`, which uses UTF-16 codepoints. Could be fixed up
		// via jquery.lengthLimit, but it isn't worthwhile given how high these limits are.
		$formFields['EventMeetingAddress'] = [
			'type' => 'textarea',
			'rows' => 5,
			'label-message' => 'campaignevents-edit-field-address',
			'hide-if' => [ '===', 'ParticipationOptions', (string)EventRegistration::PARTICIPATION_OPTION_ONLINE ],
			'default' => $address ? $address->getAddressWithoutCountry() : '',
			'maxlength' => EventFactory::ADDRESS_MAXLENGTH_BYTES,
			'section' => self::DETAILS_SECTION,
		];
		$formFields['EventChatURL'] = [
			'type' => 'url',
			'label-message' => 'campaignevents-edit-field-chat-url',
			'default' => $this->event ? $this->event->getChatURL() : '',
			'help-message' => 'campaignevents-edit-field-chat-url-help',
			'help-inline' => false,
			'section' => self::DETAILS_SECTION,
		];

		$this->hookRunner->onCampaignEventsRegistrationFormLoad( $formFields, $this->eventID );
		$isTestEvent = $this->event && $this->event->getIsTestEvent();
		$formFields['TestEvent'] = [
			'type' => 'select',
			'label-message' => 'campaignevents-edit-field-event-is-test',
			'default' => $isTestEvent,
			'options-messages' => [
				'campaignevents-edit-field-status-live' => 0,
				'campaignevents-edit-field-status-test' => 1,
			],
			'section' => self::DETAILS_SECTION,
		];
		$formFields = array_merge( $formFields, $this->getParticipantQuestionsFields() );

		return $formFields;
	}

	/**
	 * Return the form fields for the participant questions section.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function getParticipantQuestionsFields(): array {
		$fields = [];

		$introText = Html::element( 'p', [], $this->msg( 'campaignevents-edit-form-questions-intro' )->text() ) .
			Html::element( 'p', [], $this->msg( 'campaignevents-edit-form-questions-explanation' )->text() );
		$fields['ParticipantQuestionsInfo'] = [
			'type' => 'info',
			'default' => $introText,
			'raw' => true,
			'section' => self::PARTICIPANT_QUESTIONS_SECTION,
		];

		$questionLabels = $this->eventQuestionsRegistry->getQuestionLabelsForOrganizerForm();
		$questionOptions = [];
		if ( $questionLabels['non-pii'] ) {
			$questionOptions['campaignevents-edit-form-questions-non-pii-label'] = $questionLabels['non-pii'];
		}
		if ( $questionLabels['pii'] ) {
			$questionOptions['campaignevents-edit-form-questions-pii-label'] = $questionLabels['pii'];
		}

		// XXX: The section headers of this field look identical to the form section headers and might be confusing.
		// See T358490.
		$fields['ParticipantQuestions'] = [
			'type' => 'multiselect',
			'options-messages' => $questionOptions,
			'default' => $this->event ? $this->event->getParticipantQuestions() : [],
			// Edits are not allowed once an event has ended, see T354880
			'disabled' => $this->event && $this->event->isPast(),
			'section' => self::PARTICIPANT_QUESTIONS_SECTION,
		];

		$piiNotice = new MessageWidget( [
			'type' => 'notice',
			'inline' => true,
			'label' => new HtmlSnippet( $this->msg( 'campaignevents-edit-form-questions-pii-notice' )->parse() ),
			'classes' => [ 'ext-campaignevents-eventregistration-notice-plain' ]
		] );
		$fields['ParticipantQuestionsPIINotice'] = [
			'type' => 'info',
			'default' => $piiNotice->toString(),
			'raw' => true,
			'section' => self::PARTICIPANT_QUESTIONS_SECTION,
			// XXX: Ideally we would use a `hide-if` here, but that doesn't work with `multiselect` (T358060).
			// Or we could implement it manually in JS, except it still won't work because the multiselect cannot
			// be infused (T358682).
		];

		return $fields;
	}

	/**
	 * @internal
	 * Converts a DateTimeZone object to a string that can be used as (default) value of the timezone input.
	 *
	 * @return string
	 */
	public static function convertTimezoneForForm( DateTimeZone $tz ): string {
		$userTimeCorrectionObj = Utils::timezoneToUserTimeCorrection( $tz );
		if ( $userTimeCorrectionObj->getCorrectionType() === UserTimeCorrection::OFFSET ) {
			return UserTimeCorrection::formatTimezoneOffset( $userTimeCorrectionObj->getTimeOffset() );
		}
		return $userTimeCorrectionObj->toString();
	}

	/**
	 * @inheritDoc
	 */
	protected function alterForm( HTMLForm $form ): void {
		$form->addHeaderHtml(
			$this->msg( $this->formMessages['details-section-subtitle'] )->parseAsBlock(),
			self::DETAILS_SECTION
		);
		$form->setSubmitTextMsg( $this->formMessages['submit'] );

		$footerNotice = '';

		if ( $this->event && !$this->event->getParticipantQuestions() ) {
			$footerNotice .= $this->msg( 'campaignevents-edit-form-notice' )->parse();
		}

		$policyMsg = $this->policyMessagesLookup->getPolicyMessageForRegistrationForm();
		if ( $policyMsg !== null ) {
			$footerNotice .= $this->msg( $policyMsg )->parseAsBlock();
		}

		if ( $footerNotice ) {
			$form->addFooterHtml( new FieldLayout( new MessageWidget( [
				'type' => 'notice',
				'inline' => true,
				'label' => new HtmlSnippet( $footerNotice ),
				'classes' => [ 'ext-campaignevents-eventregistration-notice-plain' ],
			] ) ) );
		}
	}

	private function parseSubmittedTimezone( string $rawVal ): string {
		$timeCorrection = new UserTimeCorrection( $rawVal );
		$timezoneObj = $timeCorrection->getTimeZone();
		if ( $timezoneObj ) {
			$timezone = $timezoneObj->getName();
		} elseif ( $timeCorrection->getCorrectionType() === UserTimeCorrection::SYSTEM ) {
			$timezone = UserTimeCorrection::formatTimezoneOffset( $timeCorrection->getTimeOffset() );
		} else {
			// User entered an offset directly, pass the value through without letting UserTimeCorrection
			// parse and accept raw offsets in minutes or things like "+0:555" that DateTimeZone doesn't support.
			// However, add a plus sign to valid positive offsets for consistency with the timezone selector core widget
			$timezone = $rawVal;
			if ( preg_match( '/^\d{2}:\d{2}$/', $timezone ) ) {
				$timezone = "+$timezone";
			}
		}
		return $timezone;
	}

	/**
	 * @inheritDoc
	 * @param array<string,mixed> $data
	 */
	public function onSubmit( array $data ) {
		$participationOptions = (int)$data['ParticipationOptions'];
		// The value for these fields is the empty string if the field was not filled, but EventFactory distinguishes
		// empty string (= the value was explicitly specified as an empty string) vs null (=value not specified).
		// That's mostly intended for API consumers, and here for the UI we can just assume that
		// empty string === not specified.
		$nullableFields = [ 'EventMeetingURL', 'EventMeetingCountry', 'EventMeetingAddress', 'EventChatURL' ];
		foreach ( $nullableFields as $fieldName ) {
			$data[$fieldName] = $data[$fieldName] !== '' ? $data[$fieldName] : null;
		}

		if ( isset( $data['EventDashboardURL'] ) && $data['EventDashboardURL'] !== '' ) {
			$trackingToolUserID = $data['EventTrackingToolID'];
			try {
				$trackingToolEventID = $this->trackingToolRegistry->getToolEventIDFromURL(
					$trackingToolUserID,
					$data['EventDashboardURL']
				);
			} catch ( InvalidToolURLException $_ ) {
				throw new LogicException( 'This should have been caught by validation-callback' );
			}
		} else {
			$trackingToolUserID = null;
			$trackingToolEventID = null;
		}

		if ( $this->event && $this->event->isPast() ) {
			// Edits are not allowed once an event has ended, see T354880
			$participantQuestionIDs = $this->event->getParticipantQuestions();
		} else {
			$participantQuestionIDs = array_map( 'intval', $data['ParticipantQuestions'] );
		}
		$participantQuestionNames = [];
		foreach ( $participantQuestionIDs as $questionID ) {
			try {
				$participantQuestionNames[] = $this->eventQuestionsRegistry->dbIDToName( $questionID );
			} catch ( UnknownQuestionException $e ) {
				// TODO This could presumably happen if a question is removed. Maybe we should just ignore it in
				// that case.
				throw new LogicException( 'Unknown question in the database', 0, $e );
			}
		}

		$wikiType = (int)$data['WikiType'];
		if ( $wikiType === self::WIKI_TYPE_ALL ) {
			$wikis = EventRegistration::ALL_WIKIS;
		} elseif ( $wikiType === self::WIKI_TYPE_NONE ) {
			$wikis = [];
		} else {
			$wikis = $data['Wikis'];
		}

		if ( $participationOptions & EventRegistration::PARTICIPATION_OPTION_ONLINE ) {
			$meetingURL = $data['EventMeetingURL'];
		} else {
			$meetingURL = null;
		}
		if ( $participationOptions & EventRegistration::PARTICIPATION_OPTION_IN_PERSON ) {
			$meetingCountry = $data['EventMeetingCountry'] ?? null;
			$meetingCountryCode = $data['EventMeetingCountryCode'] ?? null;
			$meetingAddress = $data['EventMeetingAddress'];
		} else {
			$meetingCountry = $meetingCountryCode = $meetingAddress = null;
		}

		$testEvent = $data['TestEvent'] === "1";

		try {
			$event = $this->eventFactory->newEvent(
				$this->eventID,
				$data[self::PAGE_FIELD_NAME_HTMLFORM],
				$this->event ? $data['EventStatus'] : EventRegistration::STATUS_OPEN,
				$this->parseSubmittedTimezone( $data['TimeZone'] ),
				// Converting timestamps to TS_MW also gets rid of the UTC timezone indicator in them
				wfTimestamp( TS_MW, $data['EventStart'] ),
				wfTimestamp( TS_MW, $data['EventEnd'] ),
				$data['EventTypes'],
				$wikis,
				$data['Topics'] ?? [],
				$trackingToolUserID,
				$trackingToolEventID,
				$participationOptions,
				$meetingURL,
				$meetingCountry,
				$meetingCountryCode,
				$meetingAddress,
				$data['EventChatURL'],
				$testEvent,
				$participantQuestionNames,
				$this->event ? $this->event->getCreationTimestamp() : null,
				$this->event ? $this->event->getLastEditTimestamp() : null,
				$this->event ? $this->event->getDeletionTimestamp() : null,
				$this->getValidationFlags(),
				$this->event ? $this->event->getPage() : null
			);
		} catch ( InvalidEventDataException $e ) {
			return Status::wrap( $e->getStatus() );
		}

		$this->eventPage = $event->getPage();
		$organizerUsernames = $data[ 'EventOrganizerUsernames' ]
			? explode( "\n", $data[ 'EventOrganizerUsernames' ] )
			: [];

		$res = $this->editEventCommand->doEditIfAllowed(
			$event,
			$this->getAuthority(),
			$organizerUsernames
		);
		if ( $res->isOK() ) {
			if ( !empty( $data[ 'ClickWrapCheckbox' ] ) ) {
				$this->organizersStore->updateClickwrapAcceptance(
					$res->getValue(),
					$this->centralUserLookup->newFromAuthority( $this->getAuthority() )
				);
			}
			$this->hookRunner->onCampaignEventsRegistrationFormSubmit( $data, $res->getValue() );
		}
		[ $errorsStatus, $this->saveWarningsStatus ] = $res->splitByErrorType();
		return Status::wrap( $errorsStatus );
	}

	private function getTimezone(): DateTimeZone {
		// HACK: If the form has been submitted, adjust the minimum allowed dates according to the selected
		// time zone, or the validation will be off (T348579). The proper solution would be for time fields to
		// accept a timezone parameter (T315874).
		if ( $this->getRequest()->wasPosted() ) {
			$rawTZ = $this->getRequest()->getVal( 'wpTimeZone' );
			if ( $rawTZ === 'other' ) {
				// See HTMLSelectOrOtherField::loadDataFromRequest
				$rawTZ = $this->getRequest()->getVal( 'wpTimeZone-other' );
			}
			$tzString = $this->parseSubmittedTimezone( $rawTZ );
			try {
				return new DateTimeZone( $tzString );
			} catch ( TimeoutException $e ) {
				throw $e;
			} catch ( Exception $_ ) {
				return new DateTimeZone( 'UTC' );
			}
		}

		return $this->event ? $this->event->getTimezone() : new DateTimeZone( 'UTC' );
	}

	/**
	 * @return list<string>
	 */
	private function getOrganizerUsernames(): array {
		if ( !$this->eventID ) {
			return [ $this->getAuthority()->getUser()->getName() ];
		}
		$organizerUserNames = [];
		$organizers = $this->organizersStore->getEventOrganizers(
			$this->eventID,
			EditEventCommand::MAX_ORGANIZERS_PER_EVENT
		);
		foreach ( $organizers as $organizer ) {
			$user = $organizer->getUser();
			try {
				$organizerUserNames[] = $this->centralUserLookup->getUserName( $user );
			} catch ( CentralUserNotFoundException | HiddenCentralUserException $_ ) {
				// If this happens we just don't display the user name
			}
		}

		return $organizerUserNames;
	}

	/**
	 * @inheritDoc
	 */
	public function onSuccess(): void {
		$out = $this->getOutput();
		$session = $out->getRequest()->getSession();
		$isUpdate = $this instanceof SpecialEditEventRegistration;
		// Use session variables, as opposed to query parameters, so that the notification will only be seen once, and
		// not on every page refresh (and possibly end up in shared links etc.)
		$session->set(
			self::REGISTRATION_UPDATED_SESSION_KEY,
			$isUpdate ? self::REGISTRATION_UPDATED_SESSION_UPDATED : self::REGISTRATION_UPDATED_SESSION_ENABLED
		);
		$warningMessages = $this->saveWarningsStatus->getMessages();
		if ( $warningMessages ) {
			$warningMessagesText = array_map(
				fn ( MessageSpecifier $msg ): string => $this->msg( $msg )->text(),
				$warningMessages
			);
			$session->set( self::REGISTRATION_UPDATED_WARNINGS_SESSION_KEY, $warningMessagesText );
		}
		$out->redirect( $this->pageUrlResolver->getUrl( $this->eventPage ) );
	}

	/**
	 * @inheritDoc
	 */
	protected function getDisplayFormat(): string {
		return 'ooui';
	}

	/**
	 * @inheritDoc
	 */
	protected function getGroupName(): string {
		return 'campaignevents';
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
	protected function getMessagePrefix(): string {
		return '';
	}

	abstract protected function getValidationFlags(): int;
}
