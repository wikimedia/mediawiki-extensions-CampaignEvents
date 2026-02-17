<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Special;

use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\Participants\Participant;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Participants\RegisterParticipantCommand;
use MediaWiki\Extension\CampaignEvents\PolicyMessagesLookup;
use MediaWiki\Extension\CampaignEvents\Questions\EventQuestionsRegistry;
use MediaWiki\Extension\CampaignEvents\Questions\InvalidAnswerDataException;
use MediaWiki\Extension\CampaignEvents\Utils;
use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Status\Status;
use MediaWiki\Utils\MWTimestamp;
use OOUI\IconWidget;
use StatusValue;

class SpecialRegisterForEvent extends ChangeRegistrationSpecialPageBase {
	public const PAGE_NAME = 'RegisterForEvent';

	private const QUESTIONS_SECTION_NAME = 'campaignevents-register-questions-label-title';

	/**
	 * @var Participant|null If the user is already registered, this is their Participant record, containing
	 * info about their current state.
	 */
	private ?Participant $curParticipantData;
	/**
	 * @var bool Whether the user has any aggregated answers for this event. This can be true even if the user is not
	 * a participant (if they cancelled their registration after their answers had been aggregated).
	 */
	private bool $hasAggregatedAnswers;
	/**
	 * @var bool|null Whether the operation resulted in any data about the participant being modified.
	 */
	private ?bool $modifiedData;
	/** @var bool|null Whether the user is editing their registration, as opposed to registering for the first time */
	private ?bool $isEdit;
	/** @var list<int>|null IDs of participant questions to show in the form */
	private ?array $participantQuestionsToShow;
	private ?CentralUser $centralUser;

	public function __construct(
		IEventLookup $eventLookup,
		CampaignsCentralUserLookup $centralUserLookup,
		private readonly RegisterParticipantCommand $registerParticipantCommand,
		private readonly ParticipantsStore $participantsStore,
		private readonly PolicyMessagesLookup $policyMessagesLookup,
		private readonly EventQuestionsRegistry $eventQuestionsRegistry,
	) {
		parent::__construct( self::PAGE_NAME, $eventLookup, $centralUserLookup );
		$this->getOutput()->enableOOUI();
		$this->getOutput()->addModuleStyles( [
			'ext.campaignEvents.specialPages.styles',
			'oojs-ui.styles.icons-location',
			'oojs-ui.styles.icons-moderation',
		] );
	}

	/**
	 * @inheritDoc
	 */
	protected function getForm(): HTMLForm {
		if ( $this->centralUser !== null ) {
			$this->hasAggregatedAnswers = $this->participantsStore->userHasAggregatedAnswers(
				$this->event->getID(),
				$this->centralUser
			);
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
	 * @return array<string,array<string,mixed>>
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

		// Turn this into a hidden field when it must not be shown, instead of omitting it altogether, to simplify
		// submit handler (as it can always expect a value), and avoid ambiguity between explicit false and value not
		// provided.
		if ( $this->isEdit && $this->event->hasContributionTracking() && !$this->event->isPast() ) {
			$contribOptOutFieldType = 'check';
		} else {
			$contribOptOutFieldType = 'hidden';
		}
		$eventDetailsTitle = SpecialPage::getTitleFor(
			SpecialEventDetails::PAGE_NAME,
			(string)$this->event->getID()
		);
		$fields['ShowContributionAssociationPrompt'] = [
			'type' => $contribOptOutFieldType,
			'label-message' => 'campaignevents-register-contribstats-label',
			'default' => $this->curParticipantData?->shouldShowContributionAssociationPrompt() ?? true,
			'section' => 'campaignevents-register-contribstats-title',
			'help' => $this->msg( 'campaignevents-register-contribstats-help' )
				->params( $eventDetailsTitle->getFullURL( [ 'tab' => SpecialEventDetails::CONTRIBUTIONS_PANEL ] ) )
				->parse(),
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

	/** @param array<string,array<string,mixed>> &$fields */
	private function addParticipantQuestionFields( array &$fields ): void {
		if ( $this->hasAggregatedAnswers ) {
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
				/**
				 * @param array<string,mixed> $fieldDescriptor
				 * @return array<string,mixed>
				 */
				static fn ( array $fieldDescriptor ): array =>
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
					$remainingDays = intdiv( $timeRemaining, 60 * 60 * 24 );
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
				[ 'class' => 'ext-campaignevents-registerforevent-participant-questions-info-subtitle' ],
				$this->msg( 'campaignevents-register-questions-label-subtitle' )->parseAsBlock()
			);
			$form->addHeaderHtml( $questionsHeader, self::QUESTIONS_SECTION_NAME );
		}
		$form->setId( 'ext-campaignevents-registerforevent-form' );
	}

	/**
	 * @inheritDoc
	 * @param array{IsPrivate:bool,ShowContributionAssociationPrompt:bool} $data
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

		$contributionAssociationMode = $data['ShowContributionAssociationPrompt']
			? RegisterParticipantCommand::SHOW_CONTRIBUTION_ASSOCIATION_PROMPT
			: RegisterParticipantCommand::HIDE_CONTRIBUTION_ASSOCIATION_PROMPT;

		$status = $this->registerParticipantCommand->registerIfAllowed(
			$this->event,
			$this->getAuthority(),
			$privateFlag,
			$answers,
			$contributionAssociationMode,
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
		$this->getOutput()->addModuleStyles( [
			'mediawiki.codex.messagebox.styles',
		] );
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

	/**
	 * @inheritDoc
	 */
	public function checkEventIsValid(): StatusValue {
		try {
			$this->centralUser = $this->centralUserLookup->newFromAuthority(
				$this->getAuthority()
			);
			$this->curParticipantData = $this->participantsStore->getEventParticipant(
				$this->event->getID(),
				$this->centralUser,
				true
			);
		} catch ( UserNotGlobalException ) {
			$this->centralUser = null;
			$this->curParticipantData = null;
			$this->hasAggregatedAnswers = false;
		}
		return RegisterParticipantCommand::checkIsRegistrationAllowed(
			$this->event,
			$this->curParticipantData ?
				RegisterParticipantCommand::REGISTRATION_EDIT :
				RegisterParticipantCommand::REGISTRATION_NEW
		);
	}
}
