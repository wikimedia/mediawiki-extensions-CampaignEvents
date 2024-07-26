<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Pager;

use MediaWiki\Cache\LinkBatchFactory;
use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
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
	private UserOptionsLookup $userOptionsLookup;
	private CampaignsCentralUserLookup $centralUserLookup;

	public function __construct(
		CampaignsDatabaseHelper $databaseHelper,
		CampaignsPageFactory $campaignsPageFactory,
		PageURLResolver $pageURLResolver,
		LinkBatchFactory $linkBatchFactory,
		UserLinker $userLinker,
		OrganizersStore $organiserStore,
		UserOptionsLookup $userOptionsLookup,
		CampaignsCentralUserLookup $centralUserLookup
	) {
		$this->databaseHelper = $databaseHelper;
		$this->campaignsPageFactory = $campaignsPageFactory;
		$this->pageURLResolver = $pageURLResolver;
		$this->linkBatchFactory = $linkBatchFactory;
		$this->userLinker = $userLinker;
		$this->organiserStore = $organiserStore;
		$this->userOptionsLookup = $userOptionsLookup;
		$this->centralUserLookup = $centralUserLookup;
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

	public function newListPager(
		string $search,
		?int $meetingType,
		string $startDate,
		string $endDate,
		bool $showOngoing
	): EventsListPager {
		return new EventsListPager(
			$this->userLinker,
			$this->campaignsPageFactory,
			$this->pageURLResolver,
			$this->organiserStore,
			$this->linkBatchFactory,
			$this->userOptionsLookup,
			$this->databaseHelper,
			$this->centralUserLookup,
			$search,
			$meetingType,
			$startDate,
			$endDate,
			$showOngoing
		);
	}
}
