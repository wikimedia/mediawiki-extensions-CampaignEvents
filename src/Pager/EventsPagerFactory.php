<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Pager;

use MediaWiki\Cache\LinkBatchFactory;
use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\CampaignEvents\Address\CountryProvider;
use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\Event\EventTypesRegistry;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFactory;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageURLResolver;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserLinker;
use MediaWiki\Extension\CampaignEvents\MWEntity\WikiLookup;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Topics\ITopicRegistry;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\User\Options\UserOptionsLookup;

class EventsPagerFactory {
	public const SERVICE_NAME = 'CampaignEventsEventsPagerFactory';

	private CampaignsDatabaseHelper $databaseHelper;
	private IEventLookup $eventLookup;
	private CampaignsPageFactory $campaignsPageFactory;
	private PageURLResolver $pageURLResolver;
	private LinkBatchFactory $linkBatchFactory;
	private UserLinker $userLinker;
	private OrganizersStore $organiserStore;
	private UserOptionsLookup $userOptionsLookup;
	private CampaignsCentralUserLookup $centralUserLookup;
	private WikiLookup $wikiLookup;
	private ITopicRegistry $topicRegistry;
	private EventTypesRegistry $eventTypesRegistry;
	private CountryProvider $countryProvider;

	public function __construct(
		CampaignsDatabaseHelper $databaseHelper,
		IEventLookup $eventLookup,
		CampaignsPageFactory $campaignsPageFactory,
		PageURLResolver $pageURLResolver,
		LinkBatchFactory $linkBatchFactory,
		UserLinker $userLinker,
		OrganizersStore $organiserStore,
		UserOptionsLookup $userOptionsLookup,
		CampaignsCentralUserLookup $centralUserLookup,
		WikiLookup $wikiLookup,
		ITopicRegistry $topicRegistry,
		EventTypesRegistry $eventTypesRegistry,
		CountryProvider $countryProvider,
	) {
		$this->databaseHelper = $databaseHelper;
		$this->eventLookup = $eventLookup;
		$this->campaignsPageFactory = $campaignsPageFactory;
		$this->pageURLResolver = $pageURLResolver;
		$this->linkBatchFactory = $linkBatchFactory;
		$this->userLinker = $userLinker;
		$this->organiserStore = $organiserStore;
		$this->userOptionsLookup = $userOptionsLookup;
		$this->centralUserLookup = $centralUserLookup;
		$this->wikiLookup = $wikiLookup;
		$this->topicRegistry = $topicRegistry;
		$this->eventTypesRegistry = $eventTypesRegistry;
		$this->countryProvider = $countryProvider;
	}

	/**
	 * @param IContextSource $context
	 * @param LinkRenderer $linkRenderer
	 * @param string $search
	 * @param string|null $status One of the EventsTablePager::STATUS_* constants
	 * @param CentralUser $user
	 * @return EventsTablePager
	 */
	public function newTablePager(
		IContextSource $context,
		LinkRenderer $linkRenderer,
		string $search,
		?string $status,
		CentralUser $user
	): EventsTablePager {
		return new EventsTablePager(
			$context,
			$linkRenderer,
			$this->databaseHelper,
			$this->campaignsPageFactory,
			$this->pageURLResolver,
			$this->linkBatchFactory,
			$search,
			$status,
			$user,
		);
	}

	/**
	 * @phan-param list<string> $filterWiki
	 * @phan-param list<string> $filterTopics
	 * @phan-param list<string> $filterEventTypes
	 */
	public function newListPager(
		IContextSource $context,
		string $search,
		?int $participationOptions,
		?string $country,
		?string $startDate,
		?string $endDate,
		array $filterWiki,
		bool $includeAllWikis,
		array $filterTopics,
		array $filterEventTypes
	): EventsListPager {
		return new EventsListPager(
			$this->eventLookup,
			$this->userLinker,
			$this->campaignsPageFactory,
			$this->pageURLResolver,
			$this->organiserStore,
			$this->linkBatchFactory,
			$this->userOptionsLookup,
			$this->databaseHelper,
			$this->centralUserLookup,
			$this->wikiLookup,
			$this->topicRegistry,
			$this->eventTypesRegistry,
			$context,
			$this->countryProvider,
			$search,
			$participationOptions,
			$country,
			$startDate,
			$endDate,
			$filterWiki,
			$includeAllWikis,
			$filterTopics,
			$filterEventTypes,
		);
	}

	/**
	 * @phan-param list<string> $filterWiki
	 * @phan-param list<string> $filterTopics
	 * @phan-param list<string> $filterEventTypes
	 */
	public function newOngoingListPager(
		IContextSource $context,
		string $search,
		?int $participationOptions,
		?string $country,
		string $startDate,
		array $filterWiki,
		bool $includeAllWikis,
		array $filterTopics,
		array $filterEventTypes
	): OngoingEventsListPager {
		return new OngoingEventsListPager(
			$this->eventLookup,
			$this->userLinker,
			$this->campaignsPageFactory,
			$this->pageURLResolver,
			$this->organiserStore,
			$this->linkBatchFactory,
			$this->userOptionsLookup,
			$this->databaseHelper,
			$this->centralUserLookup,
			$this->wikiLookup,
			$this->topicRegistry,
			$this->eventTypesRegistry,
			$context,
			$this->countryProvider,
			$search,
			$participationOptions,
			$country,
			$startDate,
			$filterWiki,
			$includeAllWikis,
			$filterTopics,
			$filterEventTypes,
		);
	}
}
