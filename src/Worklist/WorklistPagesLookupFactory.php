<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Worklist;

use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\Pager\WorklistPagesPagerFactory;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Page\PageStoreFactory;

/**
 * Factory for worklist lookup objects, responsible of choosing the appropriate lookup implementation for a given
 * worklist.
 */
class WorklistPagesLookupFactory {
	public const SERVICE_NAME = 'CampaignEventsWorklistPagesLookupFactory';

	public function __construct(
		private readonly WorklistSecondaryStore $worklistSecondaryStore,
		private readonly PageStoreFactory $pageStoreFactory,
		private readonly WorklistPagesPagerFactory $worklistPagesPagerFactory,
		private readonly LinkRenderer $linkRenderer,
		private readonly WorklistArticleHelper $worklistArticleHelper,
		private readonly WorklistPagesSecondaryStore $worklistPagesSecondaryStore,
		private readonly CampaignsDatabaseHelper $databaseHelper,
	) {
	}

	public function getLookupForWorklist( PageIdentity $worklistPage ): IWorklistPagesLookup {
		$latestRev = $this->pageStoreFactory->getPageStore( $worklistPage->getWikiId() )
			->getPageByReference( $worklistPage )
			?->getLatest( $worklistPage->getWikiId() );

		// If the page was just created, the replica we're reading from might not have seen it yet. We treat the
		// worklist as unsynced in that case.
		$dbIsUpToDate = false;
		if ( $latestRev !== null ) {
			$syncedRev = $this->worklistSecondaryStore->getWorklistContentSyncedRevFromPage( $worklistPage );
			$dbIsUpToDate = $syncedRev === $latestRev;
		}

		if ( $dbIsUpToDate ) {
			return new DatabaseBasedWorklistPagesLookup(
				$this->worklistPagesPagerFactory,
				$this->linkRenderer,
				$worklistPage,
			);
		}

		return new WikiPageBasedWorklistPagesLookup(
			$this->worklistArticleHelper,
			$this->worklistSecondaryStore,
			$this->worklistPagesSecondaryStore,
			$this->databaseHelper,
			$worklistPage,
		);
	}
}
