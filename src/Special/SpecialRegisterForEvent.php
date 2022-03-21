<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Special;

use Html;
use HTMLForm;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Participants\RegisterParticipantCommand;
use Status;

class SpecialRegisterForEvent extends ChangeRegistrationSpecialPageBase {
	public const PAGE_NAME = 'RegisterForEvent';

	/** @var RegisterParticipantCommand */
	private $registerParticipantCommand;
	/** @var ParticipantsStore */
	private $participantsStore;

	/**
	 * @param IEventLookup $eventLookup
	 * @param RegisterParticipantCommand $registerParticipantCommand
	 * @param ParticipantsStore $participantsStore
	 */
	public function __construct(
		IEventLookup $eventLookup,
		RegisterParticipantCommand $registerParticipantCommand,
		ParticipantsStore $participantsStore
	) {
		parent::__construct( self::PAGE_NAME, $eventLookup );
		$this->registerParticipantCommand = $registerParticipantCommand;
		$this->participantsStore = $participantsStore;
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
		return [
			'Info' => [
				'type' => 'info',
				'default' => $this->msg( 'campaignevents-register-confirmation-text' )->text(),
			],
		];
	}

	/**
	 * @inheritDoc
	 */
	protected function alterForm( HTMLForm $form ): void {
		$form->setWrapperLegendMsg( 'campaignevents-register-confirmation-top' );
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
