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
use Status;

class SpecialRegisterForEvent extends ChangeRegistrationSpecialPageBase {
	public const PAGE_NAME = 'RegisterForEvent';

	/** @var RegisterParticipantCommand */
	private $registerParticipantCommand;
	/** @var ParticipantsStore */
	private $participantsStore;
	/** @var PolicyMessagesLookup */
	private $policyMessagesLookup;

	/**
	 * @param IEventLookup $eventLookup
	 * @param CampaignsCentralUserLookup $centralUserLookup
	 * @param RegisterParticipantCommand $registerParticipantCommand
	 * @param ParticipantsStore $participantsStore
	 * @param PolicyMessagesLookup $policyMessagesLookup
	 */
	public function __construct(
		IEventLookup $eventLookup,
		CampaignsCentralUserLookup $centralUserLookup,
		RegisterParticipantCommand $registerParticipantCommand,
		ParticipantsStore $participantsStore,
		PolicyMessagesLookup $policyMessagesLookup
	) {
		parent::__construct( self::PAGE_NAME, $eventLookup, $centralUserLookup );
		$this->registerParticipantCommand = $registerParticipantCommand;
		$this->participantsStore = $participantsStore;
		$this->policyMessagesLookup = $policyMessagesLookup;
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
		$fields = [
			'Confirm' => [
				'type' => 'info',
				'default' => $this->msg( 'campaignevents-register-confirmation-text' )->text(),
			],
		];
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
	}

	/**
	 * @inheritDoc
	 */
	public function onSubmit( array $data ) {
		return Status::wrap( $this->registerParticipantCommand->registerIfAllowed(
			$this->event,
			new MWAuthorityProxy( $this->getAuthority() ),
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
