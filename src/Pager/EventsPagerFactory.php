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

	public function __construct(
		private readonly CampaignsDatabaseHelper $databaseHelper,
		private readonly IEventLookup $eventLookup,
		private readonly CampaignsPageFactory $campaignsPageFactory,
		private readonly PageURLResolver $pageURLResolver,
		private readonly LinkBatchFactory $linkBatchFactory,
		private readonly UserLinker $userLinker,
		private readonly OrganizersStore $organiserStore,
		private readonly UserOptionsLookup $userOptionsLookup,
		private readonly CampaignsCentralUserLookup $centralUserLookup,
		private readonly WikiLookup $wikiLookup,
		private readonly ITopicRegistry $topicRegistry,
		private readonly EventTypesRegistry $eventTypesRegistry,
		private readonly CountryProvider $countryProvider,
	) {
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
