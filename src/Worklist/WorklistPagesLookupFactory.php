<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Worklist;

use MediaWiki\Extension\CampaignEvents\Pager\WorklistPagesPagerFactory;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Page\PageIdentity;

/**
 * Factory for worklist lookup objects, responsible of choosing the appropriate lookup implementation for a given
 * worklist.
 */
class WorklistPagesLookupFactory {
	public const SERVICE_NAME = 'CampaignEventsWorklistPagesLookupFactory';

	public function __construct(
		private readonly WorklistPagesPagerFactory $worklistPagesPagerFactory,
		private readonly LinkRenderer $linkRenderer,
	) {
	}

	public function getLookupForWorklist( PageIdentity $worklistPage ): IWorklistPagesLookup {
		return new DatabaseBasedWorklistPagesLookup(
			$this->worklistPagesPagerFactory,
			$this->linkRenderer,
			$worklistPage,
		);
	}
}
