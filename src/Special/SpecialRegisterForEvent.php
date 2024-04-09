<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Special;

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
use MediaWiki\Extension\CampaignEvents\Questions\InvalidAnswerDataException;
use MediaWiki\Extension\CampaignEvents\Utils;
use MediaWiki\Html\Html;
use MediaWiki\Status\Status;
use MediaWiki\Utils\MWTimestamp;
use OOUI\IconWidget;

class SpecialRegisterForEvent extends ChangeRegistrationSpecialPageBase {
	public const PAGE_NAME = 'RegisterForEvent';

	private const QUESTIONS_SECTION_NAME = 'campaignevents-register-questions-label-title';

	private RegisterParticipantCommand $registerParticipantCommand;
	private ParticipantsStore $participantsStore;
	private PolicyMessagesLookup $policyMessagesLookup;
	private EventQuestionsRegistry $eventQuestionsRegistry;

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
	/** @var array|null IDs of participant questions to show in the form */
	private ?array $participantQuestionsToShow;

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
		$enabledQuestions = $this->event->getParticipantQuestions();
		$curAnswers = $this->curParticipantData ? $this->curParticipantData->getAnswers() : [];
		$this->participantQuestionsToShow = EventQuestionsRegistry::getParticipantQuestionsToShow(
			$enabledQuestions,
			$curAnswers
		);
		$this->getOutput()->setPageTitleMsg(
			$this->msg( 'campaignevents-event-register-for-event-title', $this->event->getName() )
		);
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

		$this->addParticipantQuestionFields( $fields );

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

	private function addParticipantQuestionFields( array &$fields ): void {
		$alreadyAggregated = $this->curParticipantData
			? $this->curParticipantData->getAggregationTimestamp() !== null
			: false;

		if ( $alreadyAggregated ) {
			$fields['AnswersAggregated'] = [
				'type' => 'info',
				'default' => Html::element(
					'strong',
					[],
					$this->msg( 'campaignevents-register-answers-aggregated' )->text()
				),
				'raw' => true,
				'section' => self::QUESTIONS_SECTION_NAME,
			];
		} elseif ( !$this->participantQuestionsToShow ) {
			return;
		} else {
			$curAnswers = $this->curParticipantData ? $this->curParticipantData->getAnswers() : [];
			$questionFields = $this->eventQuestionsRegistry->getQuestionsForHTMLForm(
				$this->participantQuestionsToShow,
				$curAnswers
			);
			// XXX: This is affected by the following bug. Say we have an answer for question 1, and the organizer has
			// removed that question. We would show it here initially, which is correct. But then, if the user blanks
			// out the field and submit, it will still be shown after submission, even though it will no longer be
			// possible to submit a different value. This seems non-trivial to fix because this code runs before
			// onSubmit(), i.e. before we know what the updated user answers will be after form submission.
			$questionFields = array_map(
				static fn ( $fieldDescriptor ) =>
					[ 'section' => self::QUESTIONS_SECTION_NAME ] + $fieldDescriptor,
				$questionFields
			);
			$fields += $questionFields;
		}

		$retentionMsg = $this->msg( 'campaignevents-register-retention-base' )->escaped();
		if ( $this->curParticipantData ) {
			$plannedAggregationTS = Utils::getAnswerAggregationTimestamp( $this->curParticipantData, $this->event );
			if ( $plannedAggregationTS !== null ) {
				$timeRemaining = (int)$plannedAggregationTS - (int)MWTimestamp::now( TS_UNIX );
				if ( $timeRemaining < 60 * 60 * 24 ) {
					$additionalRetentionMsg = $this->msg( 'campaignevents-register-retention-hours' )->parse();
				} else {
					$remainingDays  = (int)round( $timeRemaining / ( 60 * 60 * 24 ) );
					$additionalRetentionMsg = $this->msg( 'campaignevents-register-retention-days' )
						->numParams( $remainingDays )
						->parse();
				}
				$retentionMsg .= $this->msg( 'word-separator' )->escaped() . $additionalRetentionMsg;
			}
		}
		$fields['DataRetentionInfo'] = [
			'type' => 'info',
			'raw' => true,
			'default' => $retentionMsg,
			'section' => 'campaignevents-register-retention-title',
		];
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

		if ( $this->participantQuestionsToShow ) {
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
		$privateFlag = $data['IsPrivate'] ?
			RegisterParticipantCommand::REGISTRATION_PRIVATE :
			RegisterParticipantCommand::REGISTRATION_PUBLIC;

		try {
			$answers = $this->eventQuestionsRegistry->extractUserAnswersHTMLForm(
				$data,
				$this->participantQuestionsToShow
			);
		} catch ( InvalidAnswerDataException $e ) {
			// Should never happen unless the user messes up with the form, so don't bother making this too pretty.
			return Status::newFatal( 'campaignevents-register-invalid-answer', $e->getQuestionName() );
		}

		$status = $this->registerParticipantCommand->registerIfAllowed(
			$this->event,
			new MWAuthorityProxy( $this->getAuthority() ),
			$privateFlag,
			$answers
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
