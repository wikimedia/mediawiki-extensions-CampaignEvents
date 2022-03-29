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
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFormatter;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWUserProxy;
use MediaWiki\Extension\CampaignEvents\Store\IEventLookup;
use MWTimestamp;
use Status;

abstract class AbstractEventRegistrationSpecialPage extends FormSpecialPage {

	/** @var array */
	private $formMessages;
	/** @var IEventLookup */
	protected $eventLookup;
	/** @var EventFactory */
	private $eventFactory;
	/** @var CampaignsPageFormatter */
	private $campaignsPageFormatter;
	/** @var EditEventCommand */
	protected $editEventCommand;
	/** @var int|null */
	protected $eventID;
	/** @var EventRegistration|null */
	protected $event;
	/** @var MWUserProxy */
	protected $user;

	/**
	 * @param string $name
	 * @param string $restriction
	 * @param IEventLookup $eventLookup
	 * @param EventFactory $eventFactory
	 * @param CampaignsPageFormatter $campaignsPageFormatter
	 * @param EditEventCommand $editEventCommand
	 */
	public function __construct(
		string $name,
		string $restriction,
		IEventLookup $eventLookup,
		EventFactory $eventFactory,
		CampaignsPageFormatter $campaignsPageFormatter,
		EditEventCommand $editEventCommand
	) {
		parent::__construct( $name, $restriction );
		$this->eventLookup = $eventLookup;
		$this->eventFactory = $eventFactory;
		$this->campaignsPageFormatter = $campaignsPageFormatter;
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
	 * @return void
	 */
	protected function outputErrorBox( string $errorMsg ): void {
		$this->setHeaders();
		$this->getOutput()->addHTML( Html::errorBox(
			$this->msg( $errorMsg )->parseAsBlock()
		) );
	}

	/**
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
			$eventPageDefault = $this->campaignsPageFormatter->getPrefixedText( $this->event->getPage() );
		}

		$formFields = [];
		$formFields['EventPage'] = [
			'type' => 'title',
			'label-message' => 'campaignevents-edit-field-page',
			'interwiki' => true,
			'exists' => true,
			'namespace' => NS_EVENT,
			'default' => $eventPageDefault,
			'help-message' => 'campaignevents-edit-field-page-help',
			'help-inline' => false,
			'required' => true,
		];
		$formFields['EventName'] = [
			'type' => 'text',
			'label-message' => 'campaignevents-edit-field-name',
			'default' => $this->event ? $this->event->getName() : '',
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
			'min' => MWTimestamp::now(),
			'default' => $this->event ? wfTimestamp( TS_ISO_8601, $this->event->getStartTimestamp() ) : '',
			'required' => true,
		];
		$formFields['EventEnd'] = [
			'type' => 'datetime',
			'label-message' => 'campaignevents-edit-field-end',
			'min' => MWTimestamp::now(),
			'default' => $this->event ? wfTimestamp( TS_ISO_8601, $this->event->getEndTimestamp() ) : '',
			'required' => true,
		];
		$formFields['EventMeetingType'] = [
			'type' => 'radio',
			'label-message' => 'campaignevents-edit-field-meeting-type',
			'flatlist' => true,
			'options-messages' => [
				'campaignevents-edit-field-type-online' => EventRegistration::MEETING_TYPE_ONLINE,
				'campaignevents-edit-field-type-physical' => EventRegistration::MEETING_TYPE_PHYSICAL,
				'campaignevents-edit-field-type-online-and-physical' =>
					EventRegistration::MEETING_TYPE_ONLINE_AND_PHYSICAL
			],
			'default' => $this->event ? $this->event->getMeetingType() : null,
			'required' => true,
		];

		$formFields['EventMeetingURL'] = [
			'type' => 'text',
			'label-message' => 'campaignevents-edit-field-meeting-url',
			'hide-if' => [ '===', 'wpEventMeetingType', (string)EventRegistration::MEETING_TYPE_PHYSICAL ],
			'default' => $this->event ? $this->event->getMeetingURL() : '',
		];
		$formFields['EventMeetingCountry'] = [
			'type' => 'text',
			'label-message' => 'campaignevents-edit-field-country',
			'hide-if' => [ '===', 'wpEventMeetingType', (string)EventRegistration::MEETING_TYPE_ONLINE ],
			'default' => $this->event ? $this->event->getMeetingCountry() : '',
			'required' => true,
		];
		$formFields['EventMeetingAddress'] = [
			'type' => 'textarea',
			'rows' => 5,
			'label-message' => 'campaignevents-edit-field-address',
			'hide-if' => [ '===', 'wpEventMeetingType', (string)EventRegistration::MEETING_TYPE_ONLINE ],
			'default' => $this->event ? $this->event->getMeetingAddress() : '',
			'required' => true,
		];
		$formFields['EventChatURL'] = [
			'type' => 'text',
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
	}

	/**
	 * @inheritDoc
	 */
	public function onSubmit( array $data ) {
		try {
			$event = $this->eventFactory->newEvent(
				$this->eventID,
				$data['EventName'],
				$data['EventPage'],
				$data['EventChatURL'] ?: null,
				// TODO MVP: Tracking tool
				null,
				null,
				$this->event ? $data['EventStatus'] : EventRegistration::STATUS_OPEN,
				$data['EventStart'],
				$data['EventEnd'],
				EventRegistration::TYPE_GENERIC,
				(int)$data['EventMeetingType'],
				$data['EventMeetingURL'],
				$data['EventMeetingCountry'],
				$data['EventMeetingAddress'],
				$this->event ? $this->event->getCreationTimestamp() : null,
				$this->event ? $this->event->getLastEditTimestamp() : null,
				$this->event ? $this->event->getDeletionTimestamp() : null
			);
		} catch ( InvalidEventDataException $e ) {
			return Status::wrap( $e->getStatus() );
		}

		$userProxy = new MWUserProxy( $this->getUser(), $this->getAuthority() );
		return Status::wrap( $this->editEventCommand->doEditIfAllowed( $event, $userProxy ) );
	}

	/**
	 * @inheritDoc
	 */
	public function onSuccess(): void {
		$this->getOutput()->addHTML( Html::successBox(
			$this->msg( $this->formMessages['success'] )->escaped()
		) );
	}

	/**
	 * @inheritDoc
	 */
	protected function getDisplayFormat(): string {
		return 'ooui';
	}
}
