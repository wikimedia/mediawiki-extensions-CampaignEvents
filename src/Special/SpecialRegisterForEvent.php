<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Special;

use Html;
use HTMLForm;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWAuthorityProxy;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\Participants\Participant;
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
	 * @var Participant|null If the user is already registered, this is their Participant record, containing
	 * info about their current state.
	 */
	private ?Participant $curParticipantData;
	/**
	 * @var bool|null Whether the operation resulted in any data about the participant being modified.
	 */
	private ?bool $modifiedData;
	/** @var bool|null Whether the user is editing their registration, as opposed to registering for the first time */
	private ?bool $isEdit;

	/**
	 * @var bool Temporary flag to control whether participant questions are shown. This will be removed together
	 * with the feature flag.
	 */
	private bool $showParticipantQuestions;

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
			'oojs-ui.styles.icons-moderation',
		] );
		$this->showParticipantQuestions = $this->getConfig()->get( 'CampaignEventsEnableParticipantQuestions' );
	}

	/**
	 * @inheritDoc
	 */
	protected function getForm(): HTMLForm {
		try {
			$centralUser = $this->centralUserLookup->newFromAuthority( new MWAuthorityProxy( $this->getAuthority() ) );
			$this->curParticipantData = $this->participantsStore->getEventParticipant(
				$this->event->getID(),
				$centralUser,
				true
			);
		} catch ( UserNotGlobalException $_ ) {
			$this->curParticipantData = null;
		}

		$this->isEdit = $this->curParticipantData || $this->getRequest()->wasPosted();
		if ( $this->isEdit ) {
			$this->showParticipantQuestions = false;
		}
		return parent::getForm();
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
			'IsPrivate' => [
				'type' => 'radio',
				'options' => [
					$this->msg( 'campaignevents-register-confirmation-radio-public' ) . $publicIcon => false,
					$this->msg( 'campaignevents-register-confirmation-radio-private' ) . $privateIcon => true
				],
				'default' => $this->curParticipantData ? $this->curParticipantData->isPrivateRegistration() : false,
				'section' => 'top',
			]
		];

		if ( $this->showParticipantQuestions ) {
			$enabledQuestions = $this->event->getParticipantQuestions();
			$questionFields = $this->eventQuestionsRegistry->getQuestionsForHTMLForm( $enabledQuestions );
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
		if ( $this->isEdit ) {
			$form->setWrapperLegendMsg( 'campaignevents-register-edit-legend' );
			$form->setSubmitTextMsg( 'campaignevents-register-edit-btn' );
		} else {
			$form->setWrapperLegendMsg( 'campaignevents-register-confirmation-top' );
			$form->setSubmitTextMsg( 'campaignevents-register-confirmation-btn' );
		}

		if ( $this->showParticipantQuestions && $this->event->getParticipantQuestions() ) {
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
		$status = $this->registerParticipantCommand->registerIfAllowed(
			$this->event,
			new MWAuthorityProxy( $this->getAuthority() ),
			$data['IsPrivate'] ?
				RegisterParticipantCommand::REGISTRATION_PRIVATE :
				RegisterParticipantCommand::REGISTRATION_PUBLIC
		);
		$this->modifiedData = $status->getValue();
		return Status::wrap( $status );
	}

	/**
	 * @inheritDoc
	 */
	public function onSuccess(): void {
		if ( $this->modifiedData === false ) {
			// No change to the previous data, don't show a success message.
			// TODO We might want to explicitly inform the user that nothing changed.
			return;
		}
		// Note: we can't use isEdit here because that's computed before this method is called,
		// and it'll always be true at this point.
		$successMsg = $this->curParticipantData
			? 'campaignevents-register-success-edit'
			: 'campaignevents-register-success';
		$this->getOutput()->prependHTML( Html::successBox(
			$this->msg( $successMsg )->escaped()
		) );
	}

	/**
	 * @inheritDoc
	 */
	protected function getShowAlways(): bool {
		return true;
	}
}
