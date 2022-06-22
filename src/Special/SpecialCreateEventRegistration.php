<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Special;

use MediaWiki\Extension\CampaignEvents\Event\EditEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\EventFactory;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;

class SpecialCreateEventRegistration extends AbstractEventRegistrationSpecialPage {
	public const PAGE_NAME = 'CreateEventRegistration';

	/**
	 * @param IEventLookup $eventLookup
	 * @param EventFactory $eventFactory
	 * @param EditEventCommand $editEventCommand
	 */
	public function __construct(
		IEventLookup $eventLookup,
		EventFactory $eventFactory,
		EditEventCommand $editEventCommand
	) {
		parent::__construct(
			self::PAGE_NAME,
			PermissionChecker::CREATE_REGISTRATIONS_RIGHT,
			$eventLookup,
			$eventFactory,
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

	/**
	 * @inheritDoc
	 */
	protected function getValidationFlags(): int {
		return EventFactory::VALIDATE_ALL;
	}
}
