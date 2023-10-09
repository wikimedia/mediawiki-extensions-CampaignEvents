<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Special;

use MediaWiki\Extension\CampaignEvents\Event\EditEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\EventFactory;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\Hooks\CampaignEventsHookRunner;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\PolicyMessagesLookup;
use MediaWiki\Extension\CampaignEvents\Questions\EventQuestionsRegistry;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolRegistry;

class SpecialEnableEventRegistration extends AbstractEventRegistrationSpecialPage {
	public const PAGE_NAME = 'EnableEventRegistration';

	/**
	 * @param IEventLookup $eventLookup
	 * @param EventFactory $eventFactory
	 * @param EditEventCommand $editEventCommand
	 * @param PolicyMessagesLookup $policyMessagesLookup
	 * @param OrganizersStore $organizersStore
	 * @param PermissionChecker $permissionChecker
	 * @param CampaignsCentralUserLookup $centralUserLookup
	 * @param TrackingToolRegistry $trackingToolRegistry
	 * @param EventQuestionsRegistry $eventQuestionsRegistry
	 * @param CampaignEventsHookRunner $hookRunner
	 */
	public function __construct(
		IEventLookup $eventLookup,
		EventFactory $eventFactory,
		EditEventCommand $editEventCommand,
		PolicyMessagesLookup $policyMessagesLookup,
		OrganizersStore $organizersStore,
		PermissionChecker $permissionChecker,
		CampaignsCentralUserLookup $centralUserLookup,
		TrackingToolRegistry $trackingToolRegistry,
		EventQuestionsRegistry $eventQuestionsRegistry,
		CampaignEventsHookRunner $hookRunner
	) {
		parent::__construct(
			self::PAGE_NAME,
			PermissionChecker::ENABLE_REGISTRATIONS_RIGHT,
			$eventLookup,
			$eventFactory,
			$editEventCommand,
			$policyMessagesLookup,
			$organizersStore,
			$permissionChecker,
			$centralUserLookup,
			$trackingToolRegistry,
			$eventQuestionsRegistry,
			$hookRunner
		);
	}

	/**
	 * @inheritDoc
	 */
	protected function getFormMessages(): array {
		return [
			'success' => 'campaignevents-enable-registration-success-msg',
			'details-section-subtitle' => 'campaignevents-edit-form-details-subtitle',
			'submit' => 'campaignevents-enable-registration-form-submit',
		];
	}

	/**
	 * @inheritDoc
	 */
	protected function getValidationFlags(): int {
		return EventFactory::VALIDATE_ALL;
	}
}
