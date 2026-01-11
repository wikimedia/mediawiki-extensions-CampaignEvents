<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\FrontendModules;

use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\Event\EventTypesRegistry;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionStore;
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
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Output\OutputPage;
use MediaWiki\Page\LinkBatchFactory;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\UserFactory;
use Wikimedia\Message\IMessageFormatterFactory;

class FrontendModulesFactory {
	public const SERVICE_NAME = 'CampaignEventsFrontendModulesFactory';

	public function __construct(
		private readonly IMessageFormatterFactory $messageFormatterFactory,
		private readonly OrganizersStore $organizersStore,
		private readonly ParticipantsStore $participantsStore,
		private readonly UserLinker $userLinker,
		private readonly CampaignsCentralUserLookup $centralUserLookup,
		private readonly PermissionChecker $permissionChecker,
		private readonly EventTimeFormatter $eventTimeFormatter,
		private readonly UserFactory $userFactory,
		private readonly TrackingToolRegistry $trackingToolRegistry,
		private readonly CampaignsUserMailer $userMailer,
		private readonly ParticipantAnswersStore $answersStore,
		private readonly EventAggregatedAnswersStore $aggregatedAnswersStore,
		private readonly EventQuestionsRegistry $questionsRegistry,
		private readonly CampaignEventsHookRunner $hookRunner,
		private readonly WikiLookup $wikiLookup,
		private readonly ITopicRegistry $topicRegistry,
		private readonly EventTypesRegistry $eventTypesRegistry,
		private readonly EventFormatter $eventFormatter,
		private readonly CampaignsDatabaseHelper $databaseHelper,
		private readonly TitleFactory $titleFactory,
		private readonly EventContributionStore $eventContributionStore,
		private readonly PageURLResolver $pageURLResolver,
		private readonly LinkBatchFactory $linkBatchFactory,
	) {
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

	public function newEventContributionsModule(
		LinkRenderer $linkRenderer,
		OutputPage $output,
		ExistingEventRegistration $event,
	): EventContributionsModule {
		return new EventContributionsModule(
			$this->messageFormatterFactory,
			$this->permissionChecker,
			$this->centralUserLookup,
			$linkRenderer,
			$this->userLinker,
			$this->databaseHelper,
			$this->titleFactory,
			$this->eventContributionStore,
			$this->linkBatchFactory,
			$this->participantsStore,
			$this->wikiLookup,
			$output,
			$event,
		);
	}
}
