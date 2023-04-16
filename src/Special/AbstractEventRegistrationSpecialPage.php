<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Special;

use ApiMessage;
use DateTimeZone;
use FormSpecialPage;
use Html;
use HTMLForm;
use MediaWiki\Extension\CampaignEvents\Event\EditEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\EventFactory;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\InvalidEventDataException;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUserNotFoundException;
use MediaWiki\Extension\CampaignEvents\MWEntity\HiddenCentralUserException;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWAuthorityProxy;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\PolicyMessagesLookup;
use MediaWiki\Extension\CampaignEvents\Utils;
use MediaWiki\User\UserTimeCorrection;
use MWTimestamp;
use OOUI\FieldLayout;
use OOUI\HtmlSnippet;
use OOUI\MessageWidget;
use OOUI\Tag;
use RuntimeException;
use Status;

abstract class AbstractEventRegistrationSpecialPage extends FormSpecialPage {
	private const PAGE_FIELD_NAME_HTMLFORM = 'EventPage';
	public const PAGE_FIELD_NAME = 'wp' . self::PAGE_FIELD_NAME_HTMLFORM;

	/** @var array */
	private $formMessages;
	/** @var IEventLookup */
	protected $eventLookup;
	/** @var EventFactory */
	private $eventFactory;
	/** @var EditEventCommand */
	private $editEventCommand;
	/** @var PolicyMessagesLookup */
	private PolicyMessagesLookup $policyMessagesLookup;
	/** @var OrganizersStore */
	private OrganizersStore $organizersStore;
	/** @var PermissionChecker */
	protected PermissionChecker $permissionChecker;
	/** @var CampaignsCentralUserLookup */
	private CampaignsCentralUserLookup $centralUserLookup;

	/** @var int|null */
	protected $eventID;
	/** @var EventRegistration|null */
	protected $event;
	/** @var MWAuthorityProxy */
	protected $performer;

	/**
	 * @var string|null Prefixedtext of the event page, set upon form submission and guaranteed to be
	 * a string on success.
	 */
	private $eventPagePrefixedText;
	/**
	 * @var string[] Usernames of invalid organizers, used for live validation in JavaScript.
	 */
	private array $invalidOrganizerNames = [];

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
		CampaignsCentralUserLookup $centralUserLookup
	) {
		parent::__construct( $name, $restriction );
		$this->eventLookup = $eventLookup;
		$this->eventFactory = $eventFactory;
		$this->editEventCommand = $editEventCommand;
		$this->policyMessagesLookup = $policyMessagesLookup;
		$this->formMessages = $this->getFormMessages();
		$this->organizersStore = $organizersStore;
		$this->permissionChecker = $permissionChecker;
		$this->centralUserLookup = $centralUserLookup;
		$this->performer = new MWAuthorityProxy( $this->getAuthority() );
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $par ): void {
		$this->requireLogin();
		$this->addHelpLink( 'Extension:CampaignEvents' );
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
		] );

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
	 * @phan-return array{success:string,form-legend:string,submit:string}
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
			'cssclass' => 'ext-campaignevents-timezone-input'
		];
		// Disable auto-infusion because we want to change the configuration.
		$timeFieldClasses = 'ext-campaignevents-time-input mw-htmlform-autoinfuse-lazy';
		$formFields['EventStart'] = [
			'type' => 'datetime',
			'label-message' => 'campaignevents-edit-field-start',
			'min' => $this->event ? '' : MWTimestamp::now(),
			'default' => $this->event ? wfTimestamp( TS_ISO_8601, $this->event->getStartLocalTimestamp() ) : '',
			'required' => true,
			'cssclass' => $timeFieldClasses,
		];
		$formFields['EventEnd'] = [
			'type' => 'datetime',
			'label-message' => 'campaignevents-edit-field-end',
			'min' => $this->event ? '' : MWTimestamp::now(),
			'default' => $this->event ? wfTimestamp( TS_ISO_8601, $this->event->getEndLocalTimestamp() ) : '',
			'required' => true,
			'cssclass' => $timeFieldClasses,
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
			'placeholder' => $this->msg( 'campaignevents-edit-field-organizers-placeholder' )->text(),
			'validation-callback' => function ( $value, $alldata ) {
				$organizers = $alldata['EventOrganizerUsernames'] !== ''
					? explode( "\n", $alldata['EventOrganizerUsernames'] )
					: [];
				$validationStatus = $this->editEventCommand->validateOrganizers( $organizers );

				if ( !$validationStatus->isGood() ) {
					if ( $validationStatus->getValue() ) {
						$this->invalidOrganizerNames = $validationStatus->getValue();
					}
					$error = $validationStatus->getErrors()[0];
					$errorApiMsg = ApiMessage::create( $error );
					return $this->msg( $errorApiMsg->getKey(), ...$errorApiMsg->getParams() )->text();
				}

				return true;
			},
		];

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
		];

		$formFields['EventMeetingURL'] = [
			'type' => 'url',
			'label-message' => 'campaignevents-edit-field-meeting-url',
			'hide-if' => [ '===', 'EventMeetingType', (string)EventRegistration::MEETING_TYPE_IN_PERSON ],
			'default' => $this->event ? $this->event->getMeetingURL() : '',
		];
		$formFields['EventMeetingCountry'] = [
			'type' => 'text',
			'label-message' => 'campaignevents-edit-field-country',
			'hide-if' => [ '===', 'EventMeetingType', (string)EventRegistration::MEETING_TYPE_ONLINE ],
			'default' => $this->event ? $this->event->getMeetingCountry() : '',
		];
		$formFields['EventMeetingAddress'] = [
			'type' => 'textarea',
			'rows' => 5,
			'label-message' => 'campaignevents-edit-field-address',
			'hide-if' => [ '===', 'EventMeetingType', (string)EventRegistration::MEETING_TYPE_ONLINE ],
			'default' => $this->event ? $this->event->getMeetingAddress() : '',
		];
		$formFields['EventChatURL'] = [
			'type' => 'url',
			'label-message' => 'campaignevents-edit-field-chat-url',
			'default' => $this->event ? $this->event->getChatURL() : '',
			'help-message' => 'campaignevents-edit-field-chat-url-help',
			'help-inline' => false,
		];

		return $formFields;
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
		$form->setWrapperLegendMsg( $this->formMessages['form-legend'] );
		$form->setSubmitTextMsg( $this->formMessages['submit'] );
		// XXX HACK: Override the font weight with inline style to avoid creating a new RL module just for this. T316820
		$footerNotice = ( new Tag( 'span' ) )
			->appendContent( new HtmlSnippet( $this->msg( 'campaignevents-edit-form-notice' )->parse() ) )
			->setAttributes( [ 'style' => 'font-weight: normal' ] );

		$policyMsg = $this->policyMessagesLookup->getPolicyMessageForRegistrationForm();
		if ( $policyMsg !== null ) {
			$footerNotice->appendContent(
				new HtmlSnippet( $this->msg( $policyMsg )->parseAsBlock() )
			);
		}
		$form->addFooterHtml( new FieldLayout( new MessageWidget( [
			'type' => 'notice',
			'inline' => true,
			'label' => $footerNotice
		] ) ) );
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

		$timeCorrection = new UserTimeCorrection( $data['TimeZone'] );
		$timezoneObj = $timeCorrection->getTimeZone();
		if ( $timezoneObj ) {
			$timezone = $timezoneObj->getName();
		} elseif ( $timeCorrection->getCorrectionType() === UserTimeCorrection::SYSTEM ) {
			$timezone = UserTimeCorrection::formatTimezoneOffset( $timeCorrection->getTimeOffset() );
		} else {
			// User entered an offset directly, pass the value through without letting UserTimeCorrection
			// parse and accept raw offsets in minutes or things like "+0:555" that DateTimeZone doesn't support.
			// However, add a plus sign to valid positive offsets for consistency with the timezone selector core widget
			$timezone = $data['TimeZone'];
			if ( preg_match( '/^\d{2}:\d{2}$/', $timezone ) ) {
				$timezone = "+$timezone";
			}
		}

		try {
			$event = $this->eventFactory->newEvent(
				$this->eventID,
				$data[self::PAGE_FIELD_NAME_HTMLFORM],
				$data['EventChatURL'],
				// TODO MVP: Tracking tool
				null,
				null,
				$this->event ? $data['EventStatus'] : EventRegistration::STATUS_OPEN,
				$timezone,
				// Converting timestamps to TS_MW also gets rid of the UTC timezone indicator in them
				wfTimestamp( TS_MW, $data['EventStart'] ),
				wfTimestamp( TS_MW, $data['EventEnd'] ),
				EventRegistration::TYPE_GENERIC,
				$meetingType,
				( $meetingType & EventRegistration::MEETING_TYPE_ONLINE ) ? $data['EventMeetingURL'] : null,
				( $meetingType & EventRegistration::MEETING_TYPE_IN_PERSON ) ? $data['EventMeetingCountry'] : null,
				( $meetingType & EventRegistration::MEETING_TYPE_IN_PERSON ) ? $data['EventMeetingAddress'] : null,
				$this->event ? $this->event->getCreationTimestamp() : null,
				$this->event ? $this->event->getLastEditTimestamp() : null,
				$this->event ? $this->event->getDeletionTimestamp() : null,
				$this->getValidationFlags()
			);
		} catch ( InvalidEventDataException $e ) {
			return Status::wrap( $e->getStatus() );
		}

		$this->eventPagePrefixedText = $event->getPage()->getPrefixedText();
		$performer = new MWAuthorityProxy( $this->getAuthority() );
		$organizerUsernames = $data[ 'EventOrganizerUsernames' ]
			? explode( "\n", $data[ 'EventOrganizerUsernames' ] )
			: [];

		return Status::wrap( $this->editEventCommand->doEditIfAllowed(
				$event,
				$this->performer,
				$organizerUsernames
			)
		);
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
		$this->getOutput()->addHTML( Html::successBox(
			$this->msg( $this->formMessages['success'] )->params( $this->eventPagePrefixedText )->parse()
		) );
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
	 * @return int
	 */
	abstract protected function getValidationFlags(): int;
}
