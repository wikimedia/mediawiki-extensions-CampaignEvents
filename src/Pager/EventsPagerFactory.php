<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Pager;

use IContextSource;
use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFactory;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageURLResolver;
use MediaWiki\Linker\LinkRenderer;

class EventsPagerFactory {
	public const SERVICE_NAME = 'CampaignEventsEventsPagerFactory';

	/** @var CampaignsDatabaseHelper */
	private $databaseHelper;
	/** @var CampaignsCentralUserLookup */
	private $centralUserLookup;
	/** @var CampaignsPageFactory */
	private $campaignsPageFactory;
	/** @var PageURLResolver */
	private $pageURLResolver;

	/**
	 * @param CampaignsDatabaseHelper $databaseHelper
	 * @param CampaignsCentralUserLookup $centralUserLookup
	 * @param CampaignsPageFactory $campaignsPageFactory
	 * @param PageURLResolver $pageURLResolver
	 */
	public function __construct(
		CampaignsDatabaseHelper $databaseHelper,
		CampaignsCentralUserLookup $centralUserLookup,
		CampaignsPageFactory $campaignsPageFactory,
		PageURLResolver $pageURLResolver
	) {
		$this->databaseHelper = $databaseHelper;
		$this->centralUserLookup = $centralUserLookup;
		$this->campaignsPageFactory = $campaignsPageFactory;
		$this->pageURLResolver = $pageURLResolver;
	}

	/**
	 * @param IContextSource $context
	 * @param LinkRenderer $linkRenderer
	 * @param string $search
	 * @param string $status One of the EventsPager::STATUS_* constants
	 * @return EventsPager
	 */
	public function newPager(
		IContextSource $context,
		LinkRenderer $linkRenderer,
		string $search,
		string $status
	): EventsPager {
		return new EventsPager(
			$context,
			$linkRenderer,
			$this->databaseHelper,
			$this->centralUserLookup,
			$this->campaignsPageFactory,
			$this->pageURLResolver,
			$search,
			$status
		);
	}
}
