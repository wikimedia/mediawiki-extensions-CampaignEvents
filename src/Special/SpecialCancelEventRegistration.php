<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Special;

use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Participants\UnregisterParticipantCommand;
use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Status\Status;
use StatusValue;

class SpecialCancelEventRegistration extends ChangeRegistrationSpecialPageBase {
	public const PAGE_NAME = 'CancelEventRegistration';

	private UnregisterParticipantCommand $unregisterParticipantCommand;
	private ParticipantsStore $participantsStore;

	public function __construct(
		IEventLookup $eventLookup,
		CampaignsCentralUserLookup $centralUserLookup,
		UnregisterParticipantCommand $unregisterParticipantCommand,
		ParticipantsStore $participantsStore
	) {
		parent::__construct( self::PAGE_NAME, $eventLookup, $centralUserLookup );
		$this->unregisterParticipantCommand = $unregisterParticipantCommand;
		$this->participantsStore = $participantsStore;
	}

	/**
	 * @inheritDoc
	 */
	protected function checkRegistrationPrecondition() {
		try {
			$centralUser = $this->centralUserLookup->newFromAuthority( $this->getAuthority() );
			$isParticipating = $this->participantsStore->userParticipatesInEvent(
				$this->event->getID(),
				$centralUser,
				true
			);
		} catch ( UserNotGlobalException ) {
			$isParticipating = false;
		}

		return $isParticipating ? true : 'campaignevents-unregister-not-participant';
	}

	/**
	 * @inheritDoc
	 * @return array<string,array<string,mixed>>
	 */
	protected function getFormFields(): array {
		return [
			'Info' => [
				'type' => 'info',
				'default' => $this->msg( 'campaignevents-unregister-confirmation-text' )->text(),
			],
		];
	}

	/**
	 * @inheritDoc
	 */
	protected function alterForm( HTMLForm $form ): void {
		$form->setWrapperLegendMsg( 'campaignevents-unregister-confirmation-top' );
		$form->setSubmitTextMsg( 'campaignevents-unregister-confirmation-btn' );
	}

	/**
	 * @inheritDoc
	 * @param array<string,mixed> $data
	 */
	public function onSubmit( array $data ) {
		return Status::wrap( $this->unregisterParticipantCommand->unregisterIfAllowed(
			$this->event,
			$this->getAuthority()
		) );
	}

	/**
	 * @inheritDoc
	 */
	public function onSuccess(): void {
		$this->getOutput()->addModuleStyles( [
			'mediawiki.codex.messagebox.styles',
		] );
		$this->getOutput()->addHTML( Html::successBox(
			$this->msg( 'campaignevents-unregister-success' )->escaped()
		) );
	}

	/**
	 * @inheritDoc
	 */
	public function requiresUnblock(): bool {
		return false;
	}

	/**
	 * @inheritDoc
	 */
	public function checkEventIsValid(): StatusValue {
		return UnregisterParticipantCommand::checkIsUnregistrationAllowed( $this->event );
	}
}
