<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Pager;

use IContextSource;
use MediaWiki\Cache\LinkBatchFactory;
use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFactory;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageURLResolver;
use MediaWiki\Linker\LinkRenderer;

class EventsPagerFactory {
	public const SERVICE_NAME = 'CampaignEventsEventsPagerFactory';

	private CampaignsDatabaseHelper $databaseHelper;
	private CampaignsPageFactory $campaignsPageFactory;
	private PageURLResolver $pageURLResolver;
	private LinkBatchFactory $linkBatchFactory;

	/**
	 * @param CampaignsDatabaseHelper $databaseHelper
	 * @param CampaignsPageFactory $campaignsPageFactory
	 * @param PageURLResolver $pageURLResolver
	 * @param LinkBatchFactory $linkBatchFactory
	 */
	public function __construct(
		CampaignsDatabaseHelper $databaseHelper,
		CampaignsPageFactory $campaignsPageFactory,
		PageURLResolver $pageURLResolver,
		LinkBatchFactory $linkBatchFactory
	) {
		$this->databaseHelper = $databaseHelper;
		$this->campaignsPageFactory = $campaignsPageFactory;
		$this->pageURLResolver = $pageURLResolver;
		$this->linkBatchFactory = $linkBatchFactory;
	}

	/**
	 * @param IContextSource $context
	 * @param LinkRenderer $linkRenderer
	 * @param CentralUser $user
	 * @param string $search
	 * @param string $status One of the EventsPager::STATUS_* constants
	 * @return EventsPager
	 */
	public function newPager(
		IContextSource $context,
		LinkRenderer $linkRenderer,
		CentralUser $user,
		string $search,
		string $status
	): EventsPager {
		return new EventsPager(
			$context,
			$linkRenderer,
			$this->databaseHelper,
			$this->campaignsPageFactory,
			$this->pageURLResolver,
			$this->linkBatchFactory,
			$user,
			$search,
			$status
		);
	}
}
