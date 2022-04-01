<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Special;

use Html;
use HTMLForm;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWUserProxy;

class SpecialRegisterForEvent extends ChangeRegistrationSpecialPageBase {
	/**
	 * @inheritDoc
	 */
	protected function getNameInternal(): string {
		return 'RegisterForEvent';
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
		$user = new MWUserProxy( $this->getUser(), $this->getAuthority() );
		$this->participantsStore->addParticipantToEvent( $this->eventID, $user );
		return true;
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
