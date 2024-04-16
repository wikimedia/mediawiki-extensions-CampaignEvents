<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Special;

use DateTime;
use DateTimeZone;
use Exception;
use HTMLForm;
use LogicException;
use MediaWiki\Extension\CampaignEvents\Event\EditEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\EventFactory;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\InvalidEventDataException;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\Hooks\CampaignEventsHookRunner;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUserNotFoundException;
use MediaWiki\Extension\CampaignEvents\MWEntity\HiddenCentralUserException;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWAuthorityProxy;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\PolicyMessagesLookup;
use MediaWiki\Extension\CampaignEvents\Questions\EventQuestionsRegistry;
use MediaWiki\Extension\CampaignEvents\Questions\UnknownQuestionException;
use MediaWiki\Extension\CampaignEvents\TrackingTool\InvalidToolURLException;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolRegistry;
use MediaWiki\Extension\CampaignEvents\Utils;
use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\FormSpecialPage;
use MediaWiki\Status\Status;
use MediaWiki\User\UserTimeCorrection;
use OOUI\FieldLayout;
use OOUI\HtmlSnippet;
use OOUI\MessageWidget;
use OOUI\Tag;
use RuntimeException;
use StatusValue;
use Wikimedia\RequestTimeout\TimeoutException;

abstract class AbstractEventRegistrationSpecialPage extends FormSpecialPage {
	private const PAGE_FIELD_NAME_HTMLFORM = 'EventPage';
	public const PAGE_FIELD_NAME = 'wp' . self::PAGE_FIELD_NAME_HTMLFORM;
	public const DETAILS_SECTION = 'campaignevents-edit-form-details-label';
	private const PARTICIPANT_QUESTIONS_SECTION = 'campaignevents-edit-form-questions-label';

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

	protected ?int $eventID = null;
	protected ?EventRegistration $event = null;
	protected MWAuthorityProxy $performer;

	/**
	 * @var string|null Prefixedtext of the event page, set upon form submission and guaranteed to be
	 * a string on success.
	 */
	private ?string $eventPagePrefixedText = null;
	/**
	 * @var string[] Usernames of invalid organizers, used for live validation in JavaScript.
	 */
	private array $invalidOrganizerNames = [];
	private ?StatusValue $saveWarningsStatus;

	/**
	 * @param string $name
	 * @param string $restriction
	 * @param IEventLookup $eventLookup
	 * @param EventFactory $eventFactory
	 * @param EditEventCommand $editEventCommand
	 * @param PolicyMessagesLookup $policyMessagesLookup
	 * @param OrganizersStore $organizersStore
	 * @param PermissionChecker $permissionChecker
	 * @param CampaignsCentralUserLookup $centralUserLookup
	 * @param TrackingToolRegistry $trackingToolRegistry
	 * @param EventQuestionsRegistry $eventQuestionsRegistry
	 * @param CampaignEventsHookRunner $hookRunner
	 */
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
		CampaignEventsHookRunner $hookRunner
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

		$this->performer = new MWAuthorityProxy( $this->getAuthority() );
		$this->formMessages = $this->getFormMessages();
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $par ): void {
		$this->requireNamedUser();
		$this->addHelpLink( 'Help:Extension:CampaignEvents/Registration' );
		$this->getOutput()->addModules( [
			'ext.campaignEvents.editeventregistration',
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
			$performerUserName = $this->performer->getName();
			$isEventCreator = $performerUserName === $eventCreatorUsername;
		} else {
			$isEventCreator = true;
			$eventCreatorUsername = $this->performer->getName();
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
	 * @return void
	 */
	protected function outputErrorBox( string $errorMsg, ...$msgParams ): void {
		$this->setHeaders();
		$this->getOutput()->addHTML( Html::errorBox(
			$this->msg( $errorMsg )->params( ...$msgParams )->parseAsBlock()
		) );
	}

	/**
	 * Returns messages to be used on the page. 'form-legend' and 'submit' must not use markup or take any parameter.
	 * 'success' can contain markup, and will be passed the prefixedtext of the event page as the $1 parameter.
	 * @phan-return array{success:string,details-section-subtitle:string,submit:string}
	 * @return array
	 */
	abstract protected function getFormMessages(): array;

	/**
	 * @inheritDoc
	 */
	protected function getFormFields(): array {
		$eventPageDefault = null;
		if ( $this->event ) {
			$eventPageDefault = $this->event->getPage()->getPrefixedText();
		}

		$formFields = [];
		$formFields[self::PAGE_FIELD_NAME_HTMLFORM] = [
			'type' => 'title',
			'label-message' => 'campaignevents-edit-field-page',
			// TODO Interwiki support (T307108)
			'exists' => true,
			'namespace' => NS_EVENT,
			'default' => $eventPageDefault,
			'help-message' => 'campaignevents-edit-field-page-help',
			'help-inline' => false,
			'required' => true,
			'section' => self::DETAILS_SECTION,
		];

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
			'validation-callback' => function ( $value, $alldata ) {
				$organizers = $alldata['EventOrganizerUsernames'] !== ''
					? explode( "\n", $alldata['EventOrganizerUsernames'] )
					: [];
				$validationStatus = $this->editEventCommand->validateOrganizers( $organizers );

				if ( !$validationStatus->isGood() ) {
					if ( $validationStatus->getValue() ) {
						$this->invalidOrganizerNames = $validationStatus->getValue();
					}
					$msg = $validationStatus->getMessages()[0];
					return $this->msg( $msg )->text();
				}

				return true;
			},
			'section' => self::DETAILS_SECTION,
		];

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
				'validation-callback' => function ( $value, $allData ) {
					if ( $value === '' ) {
						return true;
					}
					try {
						$this->trackingToolRegistry->getToolEventIDFromURL( $allData['EventTrackingToolID'], $value );
						return true;
					} catch ( InvalidToolURLException $e ) {
						$baseURL = rtrim( $e->getExpectedBaseURL(), '/' ) . '/courses';
						return $this->msg( 'campaignevents-error-invalid-dashboard-url' )
							->params( $baseURL )
							->text();
					}
				},
				'section' => self::DETAILS_SECTION,
			];
		}

		$formFields['EventMeetingType'] = [
			'type' => 'radio',
			'label-message' => 'campaignevents-edit-field-meeting-type',
			'options-messages' => [
				'campaignevents-edit-field-type-online' => EventRegistration::MEETING_TYPE_ONLINE,
				'campaignevents-edit-field-type-in-person' => EventRegistration::MEETING_TYPE_IN_PERSON,
				'campaignevents-edit-field-type-online-and-in-person' =>
					EventRegistration::MEETING_TYPE_ONLINE_AND_IN_PERSON
			],
			'default' => $this->event ? $this->event->getMeetingType() : null,
			'required' => true,
			'section' => self::DETAILS_SECTION,
		];

		$formFields['EventMeetingURL'] = [
			'type' => 'url',
			'label-message' => 'campaignevents-edit-field-meeting-url',
			'hide-if' => [ '===', 'EventMeetingType', (string)EventRegistration::MEETING_TYPE_IN_PERSON ],
			'default' => $this->event ? $this->event->getMeetingURL() : '',
			'section' => self::DETAILS_SECTION,
		];
		$formFields['EventMeetingCountry'] = [
			'type' => 'text',
			'label-message' => 'campaignevents-edit-field-country',
			'hide-if' => [ '===', 'EventMeetingType', (string)EventRegistration::MEETING_TYPE_ONLINE ],
			'default' => $this->event ? $this->event->getMeetingCountry() : '',
			'section' => self::DETAILS_SECTION,
		];
		$formFields['EventMeetingAddress'] = [
			'type' => 'textarea',
			'rows' => 5,
			'label-message' => 'campaignevents-edit-field-address',
			'hide-if' => [ '===', 'EventMeetingType', (string)EventRegistration::MEETING_TYPE_ONLINE ],
			'default' => $this->event ? $this->event->getMeetingAddress() : '',
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
		$formFields = array_merge( $formFields, $this->getParticipantQuestionsFields() );

		return $formFields;
	}

	/**
	 * Return the form fields for the participant questions section.
	 *
	 * @return array
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
			'label' => ( new Tag( 'span' ) )
				->appendContent( new HtmlSnippet(
					$this->msg( 'campaignevents-edit-form-questions-pii-notice' )->parse()
				) )
				// XXX HACK: Override the font weight with inline style to avoid creating a new RL module. T351818
				->setAttributes( [ 'style' => 'font-weight: normal' ] )
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
	 * @param DateTimeZone $tz
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
		// XXX HACK: Override the font weight with inline style to avoid creating a new RL module just for this. T316820
		$footerNotice = ( new Tag( 'span' ) )
			->setAttributes( [ 'style' => 'font-weight: normal' ] );

		$footerHasContent = false;
		if ( $this->event && !$this->event->getParticipantQuestions() ) {
			$footerHasContent = true;
			$footerNotice->appendContent( new HtmlSnippet( $this->msg( 'campaignevents-edit-form-notice' )->parse() ) );
		}

		$policyMsg = $this->policyMessagesLookup->getPolicyMessageForRegistrationForm();
		if ( $policyMsg !== null ) {
			$footerHasContent = true;
			$footerNotice->appendContent(
				new HtmlSnippet( $this->msg( $policyMsg )->parseAsBlock() )
			);
		}

		if ( $footerHasContent ) {
			$form->addFooterHtml( new FieldLayout( new MessageWidget( [
				'type' => 'notice',
				'inline' => true,
				'label' => $footerNotice
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
	 */
	public function onSubmit( array $data ) {
		$meetingType = (int)$data['EventMeetingType'];
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

		try {
			$event = $this->eventFactory->newEvent(
				$this->eventID,
				$data[self::PAGE_FIELD_NAME_HTMLFORM],
				$data['EventChatURL'],
				$trackingToolUserID,
				$trackingToolEventID,
				$this->event ? $data['EventStatus'] : EventRegistration::STATUS_OPEN,
				$this->parseSubmittedTimezone( $data['TimeZone'] ),
				// Converting timestamps to TS_MW also gets rid of the UTC timezone indicator in them
				wfTimestamp( TS_MW, $data['EventStart'] ),
				wfTimestamp( TS_MW, $data['EventEnd'] ),
				EventRegistration::TYPE_GENERIC,
				$meetingType,
				( $meetingType & EventRegistration::MEETING_TYPE_ONLINE ) ? $data['EventMeetingURL'] : null,
				( $meetingType & EventRegistration::MEETING_TYPE_IN_PERSON ) ? $data['EventMeetingCountry'] : null,
				( $meetingType & EventRegistration::MEETING_TYPE_IN_PERSON ) ? $data['EventMeetingAddress'] : null,
				$participantQuestionNames,
				$this->event ? $this->event->getCreationTimestamp() : null,
				$this->event ? $this->event->getLastEditTimestamp() : null,
				$this->event ? $this->event->getDeletionTimestamp() : null,
				$this->getValidationFlags()
			);
		} catch ( InvalidEventDataException $e ) {
			return Status::wrap( $e->getStatus() );
		}

		$this->eventPagePrefixedText = $event->getPage()->getPrefixedText();
		$organizerUsernames = $data[ 'EventOrganizerUsernames' ]
			? explode( "\n", $data[ 'EventOrganizerUsernames' ] )
			: [];

		$res = $this->editEventCommand->doEditIfAllowed(
			$event,
			$this->performer,
			$organizerUsernames
		);
		if ( $res->isOK() ) {
			if ( !empty( $data[ 'ClickWrapCheckbox' ] ) ) {
				$this->organizersStore->updateClickwrapAcceptance(
					$res->getValue(),
					$this->centralUserLookup->newFromAuthority( $this->performer )
				);
			}
			$this->hookRunner->onCampaignEventsRegistrationFormSubmit( $data, $res->getValue() );
		}
		[ $errorsStatus, $this->saveWarningsStatus ] = $res->splitByErrorType();
		return Status::wrap( $errorsStatus );
	}

	/**
	 * @return DateTimeZone
	 */
	private function getTimezone() {
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
	 * @return array of usernames
	 */
	private function getOrganizerUsernames(): array {
		if ( !$this->eventID ) {
			return [ $this->performer->getName() ];
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
		$this->getOutput()->prependHTML( Html::successBox(
			$this->msg( $this->formMessages['success'] )->params( $this->eventPagePrefixedText )->parse()
		) );
		if ( $this->saveWarningsStatus ) {
			foreach ( $this->saveWarningsStatus->getMessages() as $msg ) {
				$this->getOutput()->prependHTML( Html::warningBox( $this->msg( $msg )->escaped() ) );
			}
		}
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

	/**
	 * @return int
	 */
	abstract protected function getValidationFlags(): int;
}
