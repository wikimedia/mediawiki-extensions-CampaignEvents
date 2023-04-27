<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Special;

use FormSpecialPage;
use Html;
use HTMLForm;
use MediaWiki\Extension\CampaignEvents\Event\DeleteEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventNotFoundException;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWAuthorityProxy;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use Status;
use User;

class SpecialDeleteEventRegistration extends FormSpecialPage {
	/** @var IEventLookup */
	private $eventLookup;
	/** @var DeleteEventCommand */
	private $deleteEventCommand;
	/** @var PermissionChecker */
	private $permissionChecker;

	/** @var ExistingEventRegistration|null */
	private $event;

	/**
	 * @param IEventLookup $eventLookup
	 * @param DeleteEventCommand $deleteEventCommand
	 * @param PermissionChecker $permissionChecker
	 */
	public function __construct(
		IEventLookup $eventLookup,
		DeleteEventCommand $deleteEventCommand,
		PermissionChecker $permissionChecker
	) {
		parent::__construct( 'DeleteEventRegistration' );
		$this->eventLookup = $eventLookup;
		$this->deleteEventCommand = $deleteEventCommand;
		$this->permissionChecker = $permissionChecker;
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $par ): void {
		$this->addHelpLink( 'Extension:CampaignEvents' );
		if ( $par === null ) {
			$this->setHeaders();
			$this->getOutput()->addHTML( Html::errorBox(
				$this->msg( 'campaignevents-delete-error-no-event' )->escaped()
			) );
			return;
		}
		$eventID = (int)$par;
		try {
			$this->event = $this->eventLookup->getEventByID( $eventID );
		} catch ( EventNotFoundException $_ ) {
			$this->setHeaders();
			$this->getOutput()->addHTML( Html::errorBox(
				$this->msg( 'campaignevents-delete-error-event-not-found' )->escaped()
			) );
			return;
		}

		if ( $this->event->getDeletionTimestamp() !== null ) {
			$this->setHeaders();
			$this->getOutput()->addHTML( Html::errorBox(
				$this->msg( 'campaignevents-delete-error-already-deleted' )->escaped()
			) );
			return;
		}

		parent::execute( $par );
	}

	/**
	 * @inheritDoc
	 */
	public function userCanExecute( User $user ): bool {
		$mwAuthority = new MWAuthorityProxy( $this->getAuthority() );
		return $this->permissionChecker->userCanDeleteRegistration( $mwAuthority, $this->event->getID() );
	}

	/**
	 * @inheritDoc
	 */
	protected function getFormFields(): array {
		return [
			'Confirm' => [
				'type' => 'info',
				'default' => $this->msg( 'campaignevents-delete-confirmation-text' )->text(),
			],
		];
	}

	/**
	 * @inheritDoc
	 */
	protected function alterForm( HTMLForm $form ): void {
		$form->setSubmitDestructive();
		$form->setSubmitTextMsg( 'campaignevents-delete-submit-btn' );
	}

	/**
	 * @inheritDoc
	 */
	public function onSubmit( array $data ): Status {
		$performer = new MWAuthorityProxy( $this->getAuthority() );
		return Status::wrap( $this->deleteEventCommand->deleteIfAllowed( $this->event, $performer ) );
	}

	/**
	 * @inheritDoc
	 */
	public function onSuccess(): void {
		$this->getOutput()->addHTML( Html::successBox(
			$this->msg( 'campaignevents-delete-success' )->escaped()
		) );
	}

	/**
	 * @inheritDoc
	 */
	protected function getDisplayFormat(): string {
		return 'ooui';
	}

	/**
	 * @inheritDoc
	 */
	protected function getGroupName(): string {
		return 'campaignevents';
	}

	/**
	 * @inheritDoc
	 */
	public function doesWrites(): bool {
		return true;
	}
}
