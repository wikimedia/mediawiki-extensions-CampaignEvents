<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Special;

use Html;
use HTMLForm;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Participants\RegisterParticipantCommand;
use MediaWiki\Extension\CampaignEvents\PolicyMessageLookup;
use Status;

class SpecialRegisterForEvent extends ChangeRegistrationSpecialPageBase {
	public const PAGE_NAME = 'RegisterForEvent';

	/** @var RegisterParticipantCommand */
	private $registerParticipantCommand;
	/** @var ParticipantsStore */
	private $participantsStore;
	/** @var PolicyMessageLookup */
	private $policyMessageLookup;

	/**
	 * @param IEventLookup $eventLookup
	 * @param RegisterParticipantCommand $registerParticipantCommand
	 * @param ParticipantsStore $participantsStore
	 * @param PolicyMessageLookup $policyMessageLookup
	 */
	public function __construct(
		IEventLookup $eventLookup,
		RegisterParticipantCommand $registerParticipantCommand,
		ParticipantsStore $participantsStore,
		PolicyMessageLookup $policyMessageLookup
	) {
		parent::__construct( self::PAGE_NAME, $eventLookup );
		$this->registerParticipantCommand = $registerParticipantCommand;
		$this->participantsStore = $participantsStore;
		$this->policyMessageLookup = $policyMessageLookup;
	}

	/**
	 * @inheritDoc
	 */
	protected function checkRegistrationPrecondition() {
		$isParticipating = $this->participantsStore->userParticipatesToEvent( $this->event->getID(), $this->mwUser );
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
		$policyMsg = $this->policyMessageLookup->getPolicyMessage();
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
		return Status::wrap( $this->registerParticipantCommand->registerIfAllowed( $this->event, $this->mwUser ) );
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
