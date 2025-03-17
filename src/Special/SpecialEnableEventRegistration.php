<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Special;

use MediaWiki\Config\Config;
use MediaWiki\Extension\CampaignEvents\Event\EditEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\EventFactory;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\Hooks\CampaignEventsHookRunner;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageURLResolver;
use MediaWiki\Extension\CampaignEvents\MWEntity\WikiLookup;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\PolicyMessagesLookup;
use MediaWiki\Extension\CampaignEvents\Questions\EventQuestionsRegistry;
use MediaWiki\Extension\CampaignEvents\Topics\ITopicRegistry;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolRegistry;

class SpecialEnableEventRegistration extends AbstractEventRegistrationSpecialPage {
	public const PAGE_NAME = 'EnableEventRegistration';

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
		CampaignEventsHookRunner $hookRunner,
		PageURLResolver $pageURLResolver,
		WikiLookup $wikiLookup,
		ITopicRegistry $topicRegistry,
		Config $wikiConfig
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
			$hookRunner,
			$pageURLResolver,
			$wikiLookup,
			$topicRegistry,
			$wikiConfig
		);
	}

	/**
	 * @inheritDoc
	 */
	protected function getFormMessages(): array {
		return [
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
