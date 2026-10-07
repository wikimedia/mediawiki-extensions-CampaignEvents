<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Pager;

use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\MWEntity\WikiLookup;
use MediaWiki\Extension\CampaignEvents\Worklist\WorklistPagesSecondaryStore;
use MediaWiki\Extension\CampaignEvents\Worklist\WorklistSecondaryStore;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Page\LinkBatchFactory;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Title\TitleFactory;

/**
 * Factory service for {@see WorklistPagesPager}.
 */
class WorklistPagesPagerFactory {
	public const SERVICE_NAME = 'CampaignEventsWorklistPagesPagerFactory';

	public function __construct(
		private readonly CampaignsDatabaseHelper $databaseHelper,
		private readonly LinkBatchFactory $linkBatchFactory,
		private readonly TitleFactory $titleFactory,
		private readonly WikiLookup $wikiLookup,
		private readonly WorklistSecondaryStore $worklistSecondaryStore,
		private readonly WorklistPagesSecondaryStore $worklistPagesSecondaryStore,
	) {
	}

	public function newPager(
		IContextSource $context,
		LinkRenderer $linkRenderer,
		PageIdentity $worklistPage,
	): WorklistPagesPager {
		return new WorklistPagesPager(
			$this->databaseHelper,
			$this->linkBatchFactory,
			$this->titleFactory,
			$this->wikiLookup,
			$this->worklistSecondaryStore,
			$this->worklistPagesSecondaryStore,
			$context,
			$linkRenderer,
			$worklistPage,
		);
	}
}
