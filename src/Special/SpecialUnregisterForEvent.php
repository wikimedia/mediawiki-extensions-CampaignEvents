<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Special;

use Html;
use HTMLForm;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWUserProxy;

class SpecialUnregisterForEvent extends ChangeRegistrationSpecialPageBase {
	/**
	 * @inheritDoc
	 */
	protected function getNameInternal(): string {
		return 'UnregisterForEvent';
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
	}

	/**
	 * @inheritDoc
	 */
	public function onSubmit( array $data ) {
		$user = new MWUserProxy( $this->getUser(), $this->getAuthority() );
		$this->participantsStore->removeParticipantFromEvent( $this->eventID, $user );
		return true;
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
		// TODO MVP: Are we comfortable with this?
		return false;
	}
}
