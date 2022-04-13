<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Special;

use MediaWiki\Extension\CampaignEvents\Event\EditEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\EventFactory;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFormatter;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;

class SpecialCreateEventRegistration extends AbstractEventRegistrationSpecialPage {

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
			'CreateEventRegistration',
			PermissionChecker::CREATE_REGISTRATIONS_RIGHT,
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
			'success' => 'campaignevents-create-success-msg',
			'form-legend' => 'campaignevents-edit-form-legend',
			'submit' => 'campaignevents-create-form-submit',
		];
	}
}
