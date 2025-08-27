<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\FrontendModules;

use MediaWiki\Cache\LinkBatchFactory;
use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionStore;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserLinker;
use MediaWiki\Extension\CampaignEvents\Pager\EventContributionsPager;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Output\OutputPage;
use MediaWiki\Title\TitleFactory;
use OOUI\HtmlSnippet;
use OOUI\Tag;

class EventContributionsModule {

	private ExistingEventRegistration $event;
	private PermissionChecker $permissionChecker;
	private LinkRenderer $linkRenderer;
	private CampaignsDatabaseHelper $databaseHelper;
	private OutputPage $output;
	private CampaignsCentralUserLookup $centralUserLookup;
	private UserLinker $userLinker;
	private TitleFactory $titleFactory;
	private EventContributionStore $eventContributionStore;
	private LinkBatchFactory $linkBatchFactory;

	public function __construct(
		ExistingEventRegistration $event,
		PermissionChecker $permissionChecker,
		CampaignsCentralUserLookup $centralUserLookup,
		LinkRenderer $linkRenderer,
		UserLinker $userLinker,
		CampaignsDatabaseHelper $databaseHelper,
		TitleFactory $titleFactory,
		EventContributionStore $eventContributionStore,
		OutputPage $output,
		LinkBatchFactory $linkBatchFactory
	) {
		$this->event = $event;
		$this->permissionChecker = $permissionChecker;
		$this->centralUserLookup = $centralUserLookup;
		$this->linkRenderer = $linkRenderer;
		$this->userLinker = $userLinker;
		$this->databaseHelper = $databaseHelper;
		$this->titleFactory = $titleFactory;
		$this->eventContributionStore = $eventContributionStore;
		$this->output = $output;
		$this->linkBatchFactory = $linkBatchFactory;
	}

	public function createContent(): Tag {
		$output = $this->output;
		$pager = new EventContributionsPager(
			$this->databaseHelper->getDBConnection( DB_REPLICA ),
			$this->permissionChecker,
			$this->centralUserLookup,
			$this->linkBatchFactory,
			$this->linkRenderer,
			$this->userLinker,
			$this->titleFactory,
			$this->eventContributionStore,
			$this->event
		);

		// Ensure the pager gets the correct context with request parameters
		$pager->setContext( $output->getContext() );
		// Keep the Contributions tab active when interacting with the pager
		$pager->setExtraQuery( [ 'tab' => 'ContributionsPanel' ] );

		$content = new Tag( 'div' );
		$content->addClasses( [ 'ext-campaignevents-contributions-table' ] );
		$tableOutput = $pager->getFullOutput();
		$tableHtml = $tableOutput->getContentHolderText();
		$content->appendContent( new HtmlSnippet( $tableHtml ) );

		return $content;
	}
}
