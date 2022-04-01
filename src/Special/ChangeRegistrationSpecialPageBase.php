<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Special;

use FormSpecialPage;
use Html;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Store\EventNotFoundException;
use MediaWiki\Extension\CampaignEvents\Store\IEventLookup;

abstract class ChangeRegistrationSpecialPageBase extends FormSpecialPage {
	/** @var IEventLookup */
	private $eventLookup;
	/** @var ParticipantsStore */
	protected $participantsStore;

	/** @var int|null */
	protected $eventID;

	/**
	 * @param IEventLookup $eventLookup
	 * @param ParticipantsStore $participantsStore
	 */
	public function __construct(
		IEventLookup $eventLookup,
		ParticipantsStore $participantsStore
	) {
		parent::__construct( $this->getNameInternal() );
		$this->eventLookup = $eventLookup;
		$this->participantsStore = $participantsStore;
	}

	/**
	 * Used in the constructor so that subclasses only need to override this.
	 * @return string
	 */
	abstract protected function getNameInternal(): string;

	/**
	 * @inheritDoc
	 */
	public function execute( $par ): void {
		$this->requireLogin();
		$this->addHelpLink( 'Extension:CampaignEvents' );
		if ( $par === null ) {
			$this->setHeaders();
			$this->getOutput()->addHTML( Html::errorBox(
				$this->msg( 'campaignevents-register-error-no-event' )->escaped()
			) );
			return;
		}
		$eventID = (int)$par;
		try {
			$this->eventLookup->getEventByID( $eventID );
		} catch ( EventNotFoundException $_ ) {
			$this->setHeaders();
			$this->getOutput()->addHTML( Html::errorBox(
				$this->msg( 'campaignevents-register-error-event-not-found' )->escaped()
			) );
			return;
		}
		$this->eventID = $eventID;
		parent::execute( $par );
	}

	/**
	 * @inheritDoc
	 */
	protected function getDisplayFormat(): string {
		return 'ooui';
	}
}
