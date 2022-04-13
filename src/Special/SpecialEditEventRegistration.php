<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Special;

use MediaWiki\Extension\CampaignEvents\Event\EditEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\EventFactory;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventNotFoundException;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFormatter;

class SpecialEditEventRegistration extends AbstractEventRegistrationSpecialPage {

	/**
	 * @param IEventLookup $eventLookup
	 * @param EventFactory $eventFactory
	 * @param CampaignsPageFormatter $campaignsPageFormatter
	 * @param EditEventCommand $editEventCommand
	 */
	public function __construct(
		IEventLookup $eventLookup,
		EventFactory $eventFactory,
		CampaignsPageFormatter $campaignsPageFormatter,
		EditEventCommand $editEventCommand
	) {
		parent::__construct(
			'EditEventRegistration',
			'',
			$eventLookup,
			$eventFactory,
			$campaignsPageFormatter,
			$editEventCommand
		);
	}

	/**
	 * @inheritDoc
	 */
	protected function getFormMessages(): array {
		return [
			'success' => 'campaignevents-edit-success-msg',
			'form-legend' => 'campaignevents-edit-form-legend',
			'submit' => 'campaignevents-edit-form-submit',
		];
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $par ): void {
		if ( $par === null ) {
			$this->outputErrorBox( 'campaignevents-edit-no-event-id-provided' );
			return;
		}
		$this->eventID = (int)$par;
		if ( (string)$this->eventID !== $par ) {
			$this->outputErrorBox( 'campaignevents-edit-invalid-id' );
			return;
		}
		try {
			$this->event = $this->eventLookup->getEventByID( $this->eventID );
		} catch ( EventNotFoundException $_ ) {
			$this->outputErrorBox( 'campaignevents-edit-event-notfound' );
			return;
		}
		parent::execute( $par );
	}
}
