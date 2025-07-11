<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\FrontendModules;

use MediaWiki\Extension\CampaignEvents\Event\EventTypesRegistry;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Formatters\EventFormatter;
use MediaWiki\Extension\CampaignEvents\Hooks\CampaignEventsHookRunner;
use MediaWiki\Extension\CampaignEvents\Messaging\CampaignsUserMailer;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageURLResolver;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserLinker;
use MediaWiki\Extension\CampaignEvents\MWEntity\WikiLookup;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\Questions\EventAggregatedAnswersStore;
use MediaWiki\Extension\CampaignEvents\Questions\EventQuestionsRegistry;
use MediaWiki\Extension\CampaignEvents\Questions\ParticipantAnswersStore;
use MediaWiki\Extension\CampaignEvents\Time\EventTimeFormatter;
use MediaWiki\Extension\CampaignEvents\Topics\ITopicRegistry;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolRegistry;
use MediaWiki\Language\Language;
use MediaWiki\User\UserFactory;
use Wikimedia\Message\IMessageFormatterFactory;

class FrontendModulesFactory {
	public const SERVICE_NAME = 'CampaignEventsFrontendModulesFactory';

	private IMessageFormatterFactory $messageFormatterFactory;
	private OrganizersStore $organizersStore;
	private ParticipantsStore $participantsStore;
	private PageURLResolver $pageURLResolver;
	private UserLinker $userLinker;
	private CampaignsCentralUserLookup $centralUserLookup;
	private PermissionChecker $permissionChecker;
	private EventTimeFormatter $eventTimeFormatter;
	private UserFactory $userFactory;
	private TrackingToolRegistry $trackingToolRegistry;
	private CampaignsUserMailer $userMailer;
	private ParticipantAnswersStore $answersStore;
	private EventAggregatedAnswersStore $aggregatedAnswersStore;
	private EventQuestionsRegistry $questionsRegistry;
	private CampaignEventsHookRunner $hookRunner;
	private WikiLookup $wikiLookup;
	private ITopicRegistry $topicRegistry;
	private EventTypesRegistry $eventTypesRegistry;
	private EventFormatter $eventFormatter;

	public function __construct(
		IMessageFormatterFactory $messageFormatterFactory,
		OrganizersStore $organizersStore,
		ParticipantsStore $participantsStore,
		PageURLResolver $pageURLResolver,
		UserLinker $userLinker,
		CampaignsCentralUserLookup $centralUserLookup,
		PermissionChecker $permissionChecker,
		EventTimeFormatter $eventTimeFormatter,
		UserFactory $userFactory,
		TrackingToolRegistry $trackingToolRegistry,
		CampaignsUserMailer $userMailer,
		ParticipantAnswersStore $answersStore,
		EventAggregatedAnswersStore $aggregatedAnswersStore,
		EventQuestionsRegistry $questionsRegistry,
		CampaignEventsHookRunner $hookRunner,
		WikiLookup $wikiLookup,
		ITopicRegistry $topicRegistry,
		EventTypesRegistry $eventTypesRegistry,
		EventFormatter $eventFormatter,
	) {
		$this->messageFormatterFactory = $messageFormatterFactory;
		$this->organizersStore = $organizersStore;
		$this->participantsStore = $participantsStore;
		$this->pageURLResolver = $pageURLResolver;
		$this->userLinker = $userLinker;
		$this->centralUserLookup = $centralUserLookup;
		$this->permissionChecker = $permissionChecker;
		$this->eventTimeFormatter = $eventTimeFormatter;
		$this->userFactory = $userFactory;
		$this->trackingToolRegistry = $trackingToolRegistry;
		$this->userMailer = $userMailer;
		$this->answersStore = $answersStore;
		$this->aggregatedAnswersStore = $aggregatedAnswersStore;
		$this->questionsRegistry = $questionsRegistry;
		$this->hookRunner = $hookRunner;
		$this->wikiLookup = $wikiLookup;
		$this->topicRegistry = $topicRegistry;
		$this->eventTypesRegistry = $eventTypesRegistry;
		$this->eventFormatter = $eventFormatter;
	}

	public function newEventDetailsModule(
		ExistingEventRegistration $registration,
		Language $language
	): EventDetailsModule {
		return new EventDetailsModule(
			$this->messageFormatterFactory,
			$this->organizersStore,
			$this->pageURLResolver,
			$this->userLinker,
			$this->eventTimeFormatter,
			$this->trackingToolRegistry,
			$this->hookRunner,
			$this->permissionChecker,
			$this->wikiLookup,
			$this->topicRegistry,
			$this->eventTypesRegistry,
			$this->eventFormatter,
			$registration,
			$language
		);
	}

	public function newEventDetailsParticipantsModule(
		Language $language,
		string $statisticsTabUrl
	): EventDetailsParticipantsModule {
		return new EventDetailsParticipantsModule(
			$this->messageFormatterFactory,
			$this->userLinker,
			$this->participantsStore,
			$this->centralUserLookup,
			$this->permissionChecker,
			$this->userFactory,
			$this->userMailer,
			$this->questionsRegistry,
			$language,
			$statisticsTabUrl
		);
	}

	public function newEmailParticipantsModule(): EmailParticipantsModule {
		return new EmailParticipantsModule(
			$this->messageFormatterFactory
		);
	}

	public function newResponseStatisticsModule(
		ExistingEventRegistration $event,
		Language $language
	): ResponseStatisticsModule {
		return new ResponseStatisticsModule(
			$this->messageFormatterFactory,
			$this->answersStore,
			$this->aggregatedAnswersStore,
			$this->questionsRegistry,
			$this,
			$event,
			$language
		);
	}

	public function newClickwrapFormModule(
		ExistingEventRegistration $event,
		Language $language
	): ClickwrapFormModule {
		return new ClickwrapFormModule(
			$event,
			$this->organizersStore,
			$this->messageFormatterFactory,
			$language,
			$this->centralUserLookup
		);
	}
}
