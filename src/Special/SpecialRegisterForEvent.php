<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Special;

use Html;
use HTMLForm;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWAuthorityProxy;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Participants\RegisterParticipantCommand;
use MediaWiki\Extension\CampaignEvents\PolicyMessagesLookup;
use MediaWiki\Extension\CampaignEvents\Questions\EventQuestionsRegistry;
use OOUI\IconWidget;
use Status;

class SpecialRegisterForEvent extends ChangeRegistrationSpecialPageBase {
	public const PAGE_NAME = 'RegisterForEvent';

	private const QUESTIONS_SECTION_NAME = 'campaignevents-register-questions-label-title';

	/** @var RegisterParticipantCommand */
	private $registerParticipantCommand;
	/** @var ParticipantsStore */
	private $participantsStore;
	/** @var PolicyMessagesLookup */
	private $policyMessagesLookup;
	/** @var EventQuestionsRegistry */
	private $eventQuestionsRegistry;

	/**
	 * @param IEventLookup $eventLookup
	 * @param CampaignsCentralUserLookup $centralUserLookup
	 * @param RegisterParticipantCommand $registerParticipantCommand
	 * @param ParticipantsStore $participantsStore
	 * @param PolicyMessagesLookup $policyMessagesLookup
	 * @param EventQuestionsRegistry $eventQuestionsRegistry
	 */
	public function __construct(
		IEventLookup $eventLookup,
		CampaignsCentralUserLookup $centralUserLookup,
		RegisterParticipantCommand $registerParticipantCommand,
		ParticipantsStore $participantsStore,
		PolicyMessagesLookup $policyMessagesLookup,
		EventQuestionsRegistry $eventQuestionsRegistry
	) {
		parent::__construct( self::PAGE_NAME, $eventLookup, $centralUserLookup );
		$this->registerParticipantCommand = $registerParticipantCommand;
		$this->participantsStore = $participantsStore;
		$this->policyMessagesLookup = $policyMessagesLookup;
		$this->eventQuestionsRegistry = $eventQuestionsRegistry;
		$this->getOutput()->enableOOUI();
		$this->getOutput()->addModuleStyles( [
			'ext.campaignEvents.specialregisterforevent.styles',
			'oojs-ui.styles.icons-location',
			'oojs-ui.styles.icons-moderation'

		] );
	}

	/**
	 * @inheritDoc
	 */
	protected function checkRegistrationPrecondition() {
		try {
			$centralUser = $this->centralUserLookup->newFromAuthority( new MWAuthorityProxy( $this->getAuthority() ) );
			$isParticipating = $this->participantsStore->userParticipatesInEvent(
				$this->event->getID(),
				$centralUser,
				true
			);
		} catch ( UserNotGlobalException $_ ) {
			$isParticipating = false;
		}

		return $isParticipating ? 'campaignevents-register-already-participant' : true;
	}

	/**
	 * @inheritDoc
	 */
	protected function getFormFields(): array {
		$publicIcon =
			new IconWidget( [
				'icon' => 'globe',
				'classes' => [ 'ext-campaignevents-registerforevent-icon' ]
			] );
		$privateIcon =
			new IconWidget( [
				'icon' => 'lock',
				'classes' => [ 'ext-campaignevents-registerforevent-icon' ]
			] );

		// Use a fake "top" section to force ordering
		$fields = [
			'Confirm' => [
				'type' => 'info',
				'default' => $this->msg( 'campaignevents-register-confirmation-text' )->text(),
				'section' => 'top',
			],
			'IsPrivate' => [
				'type' => 'radio',
				'options' => [
					$this->msg( 'campaignevents-register-confirmation-radio-public' ) . $publicIcon => false,
					$this->msg( 'campaignevents-register-confirmation-radio-private' ) . $privateIcon => true
				],
				'section' => 'top',
			]
		];

		if ( $this->getConfig()->get( 'CampaignEventsEnableParticipantQuestions' ) ) {
			$questionFields = $this->eventQuestionsRegistry->getQuestionsForHTMLForm();
			$questionFields = array_map(
				static fn ( $fieldDescriptor ) =>
					[ 'section' => self::QUESTIONS_SECTION_NAME ] + $fieldDescriptor,
				$questionFields
			);
			$fields += $questionFields;
		}

		$policyMsg = $this->policyMessagesLookup->getPolicyMessageForRegistration();
		if ( $policyMsg !== null ) {
			$fields['Policy'] = [
				'type' => 'info',
				'raw' => true,
				'default' => $this->msg( $policyMsg )->parse(),
			];
		}

		return $fields;
	}

	/**
	 * @inheritDoc
	 */
	protected function alterForm( HTMLForm $form ): void {
		$form->setWrapperLegendMsg( 'campaignevents-register-confirmation-top' );
		$form->setSubmitTextMsg( 'campaignevents-register-confirmation-btn' );
		if ( $this->getConfig()->get( 'CampaignEventsEnableParticipantQuestions' ) ) {
			$questionsHeader = Html::rawElement(
				'div',
				[ 'class' => 'ext-campaignevents-participant-questions-info-subtitle' ],
				$this->msg( 'campaignevents-register-questions-label-subtitle' )->parseAsBlock()
			);
			$form->addHeaderHtml( $questionsHeader, self::QUESTIONS_SECTION_NAME );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function onSubmit( array $data ) {
		return Status::wrap( $this->registerParticipantCommand->registerIfAllowed(
			$this->event,
			new MWAuthorityProxy( $this->getAuthority() ),
			$data['wpIsPrivate'] ?
				RegisterParticipantCommand::REGISTRATION_PRIVATE :
				RegisterParticipantCommand::REGISTRATION_PUBLIC
		) );
	}

	/**
	 * @inheritDoc
	 */
	public function onSuccess(): void {
		$this->getOutput()->addHTML( Html::successBox(
			$this->msg( 'campaignevents-register-success' )->escaped()
		) );
	}
}
