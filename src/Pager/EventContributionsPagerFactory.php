<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Pager;

use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionStore;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserLinker;
use MediaWiki\Extension\CampaignEvents\MWEntity\WikiLookup;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Page\LinkBatchFactory;
use MediaWiki\Title\TitleFactory;

/**
 * Factory service for event contribution pagers.
 */
class EventContributionsPagerFactory {
	public const SERVICE_NAME = 'CampaignEventsEventContributionsPagerFactory';

	public function __construct(
		private readonly CampaignsDatabaseHelper $databaseHelper,
		private readonly PermissionChecker $permissionChecker,
		private readonly CampaignsCentralUserLookup $centralUserLookup,
		private readonly LinkBatchFactory $linkBatchFactory,
		private readonly UserLinker $userLinker,
		private readonly TitleFactory $titleFactory,
		private readonly EventContributionStore $eventContributionStore,
		private readonly WikiLookup $wikiLookup,
	) {
	}

	public function newEditsPager(
		IContextSource $context,
		LinkRenderer $linkRenderer,
		ExistingEventRegistration $event,
	): EventContributionsEditsPager {
		return new EventContributionsEditsPager(
			$this->databaseHelper,
			$this->permissionChecker,
			$this->centralUserLookup,
			$this->linkBatchFactory,
			$this->userLinker,
			$this->titleFactory,
			$this->eventContributionStore,
			$this->wikiLookup,
			$context,
			$linkRenderer,
			$event,
		);
	}

	public function newEditorsPager(
		IContextSource $context,
		LinkRenderer $linkRenderer,
		ExistingEventRegistration $event,
	): EventContributionsEditorsPager {
		return new EventContributionsEditorsPager(
			$this->databaseHelper,
			$this->linkBatchFactory,
			$this->userLinker,
			$this->permissionChecker,
			$this->centralUserLookup,
			$this->eventContributionStore,
			$context,
			$linkRenderer,
			$event,
		);
	}
}
