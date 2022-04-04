<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Pager;

use IContextSource;
use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Linker\LinkRenderer;

class EventsPagerFactory {
	public const SERVICE_NAME = 'CampaignEventsEventsPagerFactory';

	/** @var CampaignsDatabaseHelper */
	private $databaseHelper;
	/** @var CampaignsCentralUserLookup */
	private $centralUserLookup;

	/**
	 * @param CampaignsDatabaseHelper $databaseHelper
	 * @param CampaignsCentralUserLookup $centralUserLookup
	 */
	public function __construct(
		CampaignsDatabaseHelper $databaseHelper,
		CampaignsCentralUserLookup $centralUserLookup
	) {
		$this->databaseHelper = $databaseHelper;
		$this->centralUserLookup = $centralUserLookup;
	}

	/**
	 * @param IContextSource $context
	 * @param LinkRenderer $linkRenderer
	 * @param string $search
	 * @return EventsPager
	 */
	public function newPager(
		IContextSource $context,
		LinkRenderer $linkRenderer,
		string $search
	): EventsPager {
		return new EventsPager(
			$context,
			$linkRenderer,
			$this->databaseHelper,
			$this->centralUserLookup,
			$search
		);
	}
}
