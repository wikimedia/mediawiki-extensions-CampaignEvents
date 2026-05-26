<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Pager;

use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MediaWikiEventIngress\WorklistPageEventIngress;
use MediaWiki\Extension\CampaignEvents\MWEntity\WikiLookup;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Page\LinkBatchFactory;
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
	) {
	}

	public function newPager(
		IContextSource $context,
		LinkRenderer $linkRenderer,
		ExistingEventRegistration $event,
	): WorklistPagesPager {
		// Resolve the worklist page for this event when it lives on the local wiki, so the pager can
		// gate the remove action through MediaWiki's permission system (probablyCan). For a foreign
		// event the page can't be resolved to a local title, so null is passed and only lightweight
		// checks apply (the full checks happen at edit time).
		$worklistPage = null;
		if ( $event->isOnLocalWiki() ) {
			$worklistPage = $this->titleFactory->newFromText(
				$event->getPage()->getPrefixedText() . '/' . WorklistPageEventIngress::WORKLIST_SUBPAGE
			);
		}

		return new WorklistPagesPager(
			$this->databaseHelper,
			$this->linkBatchFactory,
			$this->titleFactory,
			$this->wikiLookup,
			$context,
			$linkRenderer,
			$event,
			$worklistPage,
		);
	}
}
