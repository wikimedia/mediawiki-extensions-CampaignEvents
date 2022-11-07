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
use MediaWiki\Extension\CampaignEvents\Participants\UnregisterParticipantCommand;
use Status;

class SpecialCancelEventRegistration extends ChangeRegistrationSpecialPageBase {
	public const PAGE_NAME = 'CancelEventRegistration';

	/** @var UnregisterParticipantCommand */
	private $unregisterParticipantCommand;
	/** @var ParticipantsStore */
	private $participantsStore;

	/**
	 * @param IEventLookup $eventLookup
	 * @param CampaignsCentralUserLookup $centralUserLookup
	 * @param UnregisterParticipantCommand $unregisterParticipantCommand
	 * @param ParticipantsStore $participantsStore
	 */
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
			$centralUser = $this->centralUserLookup->newFromAuthority( new MWAuthorityProxy( $this->getAuthority() ) );
			$isParticipating = $this->participantsStore->userParticipatesInEvent(
				$this->event->getID(),
				$centralUser,
				true
			);
		} catch ( UserNotGlobalException $_ ) {
			$isParticipating = false;
		}

		return $isParticipating ? true : 'campaignevents-unregister-not-participant';
	}

	/**
	 * @inheritDoc
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
	 */
	public function onSubmit( array $data ) {
		return Status::wrap( $this->unregisterParticipantCommand->unregisterIfAllowed(
			$this->event,
			new MWAuthorityProxy( $this->getAuthority() )
		) );
	}

	/**
	 * @inheritDoc
	 */
	public function onSuccess(): void {
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
}
