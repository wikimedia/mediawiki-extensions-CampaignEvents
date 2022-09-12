<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Special;

use FormSpecialPage;
use Html;
use HTMLForm;
use MediaWiki\Extension\CampaignEvents\Event\EditEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\EventFactory;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\InvalidEventDataException;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWAuthorityProxy;
use MWTimestamp;
use OOUI\FieldLayout;
use OOUI\HtmlSnippet;
use OOUI\MessageWidget;
use OOUI\Tag;
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
	protected $editEventCommand;
	/** @var int|null */
	protected $eventID;
	/** @var EventRegistration|null */
	protected $event;

	/**
	 * @var string|null Prefixedtext of the event page, set upon form submission and guaranteed to be
	 * a string on success.
	 */
	private $eventPagePrefixedText;

	/**
	 * @param string $name
	 * @param string $restriction
	 * @param IEventLookup $eventLookup
	 * @param EventFactory $eventFactory
	 * @param EditEventCommand $editEventCommand
	 */
	public function __construct(
		string $name,
		string $restriction,
		IEventLookup $eventLookup,
		EventFactory $eventFactory,
		EditEventCommand $editEventCommand
	) {
		parent::__construct( $name, $restriction );
		$this->eventLookup = $eventLookup;
		$this->eventFactory = $eventFactory;
		$this->editEventCommand = $editEventCommand;
		$this->formMessages = $this->getFormMessages();
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $par ): void {
		$this->requireLogin();
		$this->addHelpLink( 'Extension:CampaignEvents' );

		parent::execute( $par );
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

		$formFields['EventStart'] = [
			'type' => 'datetime',
			'label-message' => 'campaignevents-edit-field-start',
			'min' => $this->event ? '' : MWTimestamp::now(),
			'default' => $this->event ? wfTimestamp( TS_ISO_8601, $this->event->getStartLocalTimestamp() ) : '',
			'required' => true,
		];
		$formFields['EventEnd'] = [
			'type' => 'datetime',
			'label-message' => 'campaignevents-edit-field-end',
			'min' => $this->event ? '' : MWTimestamp::now(),
			'default' => $this->event ? wfTimestamp( TS_ISO_8601, $this->event->getEndLocalTimestamp() ) : '',
			'required' => true,
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
			'hide-if' => [ '===', 'wpEventMeetingType', (string)EventRegistration::MEETING_TYPE_IN_PERSON ],
			'default' => $this->event ? $this->event->getMeetingURL() : '',
		];
		$formFields['EventMeetingCountry'] = [
			'type' => 'text',
			'label-message' => 'campaignevents-edit-field-country',
			'hide-if' => [ '===', 'wpEventMeetingType', (string)EventRegistration::MEETING_TYPE_ONLINE ],
			'default' => $this->event ? $this->event->getMeetingCountry() : '',
		];
		$formFields['EventMeetingAddress'] = [
			'type' => 'textarea',
			'rows' => 5,
			'label-message' => 'campaignevents-edit-field-address',
			'hide-if' => [ '===', 'wpEventMeetingType', (string)EventRegistration::MEETING_TYPE_ONLINE ],
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
	 * @inheritDoc
	 */
	protected function alterForm( HTMLForm $form ): void {
		$form->setWrapperLegendMsg( $this->formMessages['form-legend'] );
		$form->setSubmitTextMsg( $this->formMessages['submit'] );
		// XXX HACK: Override the font weight with inline style to avoid creating a new RL module just for this. T316820
		$footerNotice = ( new Tag( 'span' ) )
			->appendContent( new HtmlSnippet( $this->msg( 'campaignevents-edit-form-notice' )->parse() ) )
			->setAttributes( [ 'style' => 'font-weight: normal' ] );
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

		try {
			$event = $this->eventFactory->newEvent(
				$this->eventID,
				$data[self::PAGE_FIELD_NAME_HTMLFORM],
				$data['EventChatURL'],
				// TODO MVP: Tracking tool
				null,
				null,
				$this->event ? $data['EventStatus'] : EventRegistration::STATUS_OPEN,
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
		return Status::wrap( $this->editEventCommand->doEditIfAllowed( $event, $performer ) );
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
	 * @return int
	 */
	abstract protected function getValidationFlags(): int;
}
