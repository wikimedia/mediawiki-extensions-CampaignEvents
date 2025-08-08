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
	 * @phan-param list<string> $filterEventTypes
	 * @phan-param list<string> $filterWiki
	 * @phan-param list<string> $filterTopics
	 */
	public function newListPager(
		IContextSource $context,
		string $search,
		array $filterEventTypes,
		?string $startDate,
		?string $endDate,
		?int $participationOptions,
		?string $country,
		array $filterWiki,
		array $filterTopics,
		bool $includeAllWikis

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
			$filterEventTypes,
			$startDate,
			$endDate,
			$participationOptions,
			$country,
			$filterWiki,
			$filterTopics,
			$includeAllWikis,
		);
	}

	/**
	 * @phan-param list<string> $filterEventTypes
	 * @phan-param list<string> $filterWiki
	 * @phan-param list<string> $filterTopics
	 */
	public function newOngoingListPager(
		IContextSource $context,
		string $search,
		array $filterEventTypes,
		string $startDate,
		?int $participationOptions,
		?string $country,
		array $filterWiki,
		array $filterTopics,
		bool $includeAllWikis
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
			$filterEventTypes,
			$startDate,
			$participationOptions,
			$country,
			$filterWiki,
			$filterTopics,
			$includeAllWikis
		);
	}
}
