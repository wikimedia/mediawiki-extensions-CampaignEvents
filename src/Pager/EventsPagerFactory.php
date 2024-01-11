<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Pager;

use IContextSource;
use MediaWiki\Cache\LinkBatchFactory;
use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFactory;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageURLResolver;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserLinker;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\User\Options\UserOptionsLookup;

class EventsPagerFactory {
	public const SERVICE_NAME = 'CampaignEventsEventsPagerFactory';

	private CampaignsDatabaseHelper $databaseHelper;
	private CampaignsPageFactory $campaignsPageFactory;
	private PageURLResolver $pageURLResolver;
	private LinkBatchFactory $linkBatchFactory;
	private UserLinker $userLinker;
	private OrganizersStore $organiserStore;
	private UserOptionsLookup $options;

	/**
	 * @param CampaignsDatabaseHelper $databaseHelper
	 * @param CampaignsPageFactory $campaignsPageFactory
	 * @param PageURLResolver $pageURLResolver
	 * @param LinkBatchFactory $linkBatchFactory
	 * @param UserLinker $userLinker
	 * @param OrganizersStore $organiserStore
	 */
	public function __construct(
		CampaignsDatabaseHelper $databaseHelper,
		CampaignsPageFactory $campaignsPageFactory,
		PageURLResolver $pageURLResolver,
		LinkBatchFactory $linkBatchFactory,
		UserLinker $userLinker,
		OrganizersStore $organiserStore,
		UserOptionsLookup $options
	) {
		$this->databaseHelper = $databaseHelper;
		$this->campaignsPageFactory = $campaignsPageFactory;
		$this->pageURLResolver = $pageURLResolver;
		$this->linkBatchFactory = $linkBatchFactory;
		$this->userLinker = $userLinker;
		$this->organiserStore = $organiserStore;
		$this->options = $options;
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
	 * @param string $search
	 * @param string $status
	 * @param string $startDate
	 * @param string $endDate
	 * @return EventsListPager
	 */
	public function newListPager(
		string $search, string $status, string $startDate, string $endDate ): EventsListPager {
		return new EventsListPager(
			$this->userLinker,
			$this->campaignsPageFactory,
			$this->pageURLResolver,
			$this->organiserStore,
			$this->linkBatchFactory,
			$this->options,
			$this->databaseHelper,
			$search,
			$status,
			$startDate,
			$endDate
		);
	}
}
