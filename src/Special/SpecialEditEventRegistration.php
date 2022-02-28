<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Special;

use FormSpecialPage;
use Html;
use HTMLForm;
use MediaWiki\Extension\CampaignEvents\Event\EventFactory;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\InvalidEventDataException;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFormatter;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWUserProxy;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\Store\EventNotFoundException;
use MediaWiki\Extension\CampaignEvents\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\Store\IEventStore;
use MWTimestamp;
use Status;
use User;

class SpecialEditEventRegistration extends FormSpecialPage {
	/** @var IEventLookup */
	private $eventLookup;
	/** @var EventFactory */
	private $eventFactory;
	/** @var IEventStore */
	private $eventStore;
	/** @var CampaignsPageFormatter */
	private $campaignsPageFormatter;
	/** @var PermissionChecker */
	private $permissionChecker;

	/** @var int|null */
	private $eventID;
	/** @var EventRegistration|null */
	private $event;

	/**
	 * @param IEventLookup $eventLookup
	 * @param EventFactory $eventFactory
	 * @param IEventStore $eventStore
	 * @param CampaignsPageFormatter $campaignsPageFormatter
	 * @param PermissionChecker $permissionChecker
	 */
	public function __construct(
		IEventLookup $eventLookup,
		EventFactory $eventFactory,
		IEventStore $eventStore,
		CampaignsPageFormatter $campaignsPageFormatter,
		PermissionChecker $permissionChecker
	) {
		parent::__construct( 'EditEventRegistration' );
		$this->eventLookup = $eventLookup;
		$this->eventFactory = $eventFactory;
		$this->eventStore = $eventStore;
		$this->campaignsPageFormatter = $campaignsPageFormatter;
		$this->permissionChecker = $permissionChecker;
	}

	/**
	 * @inheritDoc
	 */
	public function userCanExecute( User $user ): bool {
		return $this->permissionChecker->userCanCreateRegistrations( new MWUserProxy( $user, $user ) );
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $par ): void {
		$this->addHelpLink( 'Extension:CampaignEvents' );
		if ( $par !== null ) {
			// Editing an existing registration
			$eventID = (int)$par;
			if ( (string)$eventID !== $par ) {
				$this->setHeaders();
				$this->getOutput()->addHTML( Html::errorBox(
					$this->msg( 'campaignevents-edit-invalid-id' )->parseAsBlock()
				) );
				return;
			}
			try {
				$this->event = $this->eventLookup->getEvent( $eventID );
			} catch ( EventNotFoundException $_ ) {
				$this->setHeaders();
				$this->getOutput()->addHTML( Html::errorBox(
					$this->msg( 'campaignevents-edit-event-notfound' )->parseAsBlock()
				) );
				return;
			}
			$this->eventID = $eventID;
		}
		parent::execute( $par );
	}

	/**
	 * @inheritDoc
	 */
	protected function getFormFields(): array {
		$eventPageDefault = null;
		if ( $this->event ) {
			$eventPageDefault = $this->campaignsPageFormatter->getPrefixedText( $this->event->getPage() );
		}
		return [
			'EventPage' => [
				'type' => 'title',
				'label-message' => 'campaignevents-edit-field-page',
				'interwiki' => true,
				'exists' => true,
				// TODO XXX namespace
				'default' => $eventPageDefault,
				'help-message' => 'campaignevents-edit-field-page-help',
				'help-inline' => false,
				'required' => true,
			],
			'EventName' => [
				'type' => 'text',
				'label-message' => 'campaignevents-edit-field-name',
				'default' => $this->event ? $this->event->getName() : '',
				'required' => true,
			],
			'EventStart' => [
				'type' => 'datetime',
				'label-message' => 'campaignevents-edit-field-start',
				'min' => MWTimestamp::now(),
				'default' => $this->event ? $this->event->getStartTimestamp() : '',
				'required' => true,
			],
			'EventEnd' => [
				'type' => 'datetime',
				'label-message' => 'campaignevents-edit-field-end',
				'min' => MWTimestamp::now(),
				'default' => $this->event ? $this->event->getEndTimestamp() : '',
				'required' => true,
			],
			'EventMeetingType' => [
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
			],
			'EventMeetingURL' => [
				'type' => 'text',
				'label-message' => 'campaignevents-edit-field-meeting-url',
				'hide-if' => [ '===', 'wpEventMeetingType', (string)EventRegistration::MEETING_TYPE_PHYSICAL ],
				'default' => $this->event ? $this->event->getMeetingURL() : '',
				'required' => true,
			],
			'EventMeetingCountry' => [
				'type' => 'text',
				'label-message' => 'campaignevents-edit-field-country',
				'hide-if' => [ '===', 'wpEventMeetingType', (string)EventRegistration::MEETING_TYPE_ONLINE ],
				'default' => $this->event ? $this->event->getMeetingCountry() : '',
				'required' => true,
			],
			'EventMeetingAddress' => [
				'type' => 'textarea',
				'rows' => 5,
				'label-message' => 'campaignevents-edit-field-address',
				'hide-if' => [ '===', 'wpEventMeetingType', (string)EventRegistration::MEETING_TYPE_ONLINE ],
				'default' => $this->event ? $this->event->getMeetingAddress() : '',
				'required' => true,
			],
			'EventChatURL' => [
				'type' => 'text',
				'label-message' => 'campaignevents-edit-field-chat-url',
				'default' => $this->event ? $this->event->getChatURL() : '',
				'help-message' => 'campaignevents-edit-field-chat-url-help',
				'help-inline' => false
			],
		];
	}

	/**
	 * @inheritDoc
	 */
	protected function alterForm( HTMLForm $form ): void {
		$form->setWrapperLegendMsg( 'campaignevents-edit-form-legend' );
		$form->setSubmitTextMsg( 'campaignevents-edit-form-submit' );
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
				// For now, the event status cannot be changed
				EventRegistration::STATUS_OPEN,
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
		if ( !$this->permissionChecker->userCanCreateRegistration( $userProxy, $event->getPage() ) ) {
			return Status::newFatal( 'campaignevents-edit-not-allowed-page' );
		}

		$this->eventStore->saveRegistration( $event );
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function onSuccess(): void {
		$this->getOutput()->addHTML( Html::successBox(
			$this->msg( 'campaignevents-edit-success-msg' )->escaped()
		) );
	}

	/**
	 * @inheritDoc
	 */
	protected function getDisplayFormat(): string {
		return 'ooui';
	}
}
