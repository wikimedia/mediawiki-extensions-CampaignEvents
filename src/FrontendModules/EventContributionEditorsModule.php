<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\FrontendModules;

use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionStore;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserLinker;
use MediaWiki\Extension\CampaignEvents\Pager\EventContributionsEditorsPager;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Output\OutputPage;
use MediaWiki\Page\LinkBatchFactory;
use OOUI\HtmlSnippet;
use OOUI\Tag;

readonly class EventContributionEditorsModule {

	public function __construct(
		private CampaignsDatabaseHelper $databaseHelper,
		private OutputPage $output,
		private ExistingEventRegistration $event,
		private UserLinker $userLinker,
		private PermissionChecker $permissionChecker,
		private CampaignsCentralUserLookup $centralUserLookup,
		private EventContributionStore $eventContributionStore,
		private LinkBatchFactory $linkBatchFactory,
		private LinkRenderer $linkRenderer,
	) {
	}

	public function createContent(): Tag {
		$container = new Tag();
		$this->output->addModuleStyles( 'codex-styles' );
		$container->addClasses( [ 'ext-campaignevents-editors-container' ] );

		$pager = new EventContributionsEditorsPager(
			$this->databaseHelper->getDBConnection( DB_REPLICA ),
			$this->linkRenderer,
			$this->linkBatchFactory,
			$this->userLinker,
			$this->permissionChecker,
			$this->centralUserLookup,
			$this->event,
			$this->eventContributionStore,
			$this->output->getContext()
		);

		// Keep the Contributions tab active when interacting with the pager
		$pager->setExtraQuery( [ 'tab' => 'ContributionsPanel' ] );

		$tableContainer = new Tag( 'div' );
		$tableContainer->addClasses( [ 'ext-campaignevents-editors-table' ] );

		$tableHtml = $pager->getFullOutput()->getContentHolderText();
		$tableContainer->appendContent( new HtmlSnippet( $tableHtml ) );

		$container->appendContent( $tableContainer );
		return $container;
	}
}
