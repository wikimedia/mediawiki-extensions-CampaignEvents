<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\FrontendModules;

use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionStore;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserLinker;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\MWEntity\WikiLookup;
use MediaWiki\Extension\CampaignEvents\Pager\EventContributionsEditsPager;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Output\OutputPage;
use MediaWiki\Page\LinkBatchFactory;
use MediaWiki\Title\TitleFactory;
use OOUI\HtmlSnippet;
use OOUI\Tag;

readonly class EventContributionEditsModule {
	public function __construct(
		private PermissionChecker $permissionChecker,
		private CampaignsCentralUserLookup $centralUserLookup,
		private UserLinker $userLinker,
		private CampaignsDatabaseHelper $databaseHelper,
		private TitleFactory $titleFactory,
		private EventContributionStore $eventContributionStore,
		private LinkBatchFactory $linkBatchFactory,
		private ParticipantsStore $participantsStore,
		private WikiLookup $wikiLookup,
		private LinkRenderer $linkRenderer,
		private OutputPage $output,
		private ExistingEventRegistration $event,
	) {
	}

	public function createContent(): Tag {
		$container = new Tag();
		$eventId = $this->event->getID();
		$this->output->addModuleStyles( 'codex-styles' );
		$container->addClasses( [ 'ext-campaignevents-contributions-container' ] );

		$currentUser = $this->output->getAuthority();
		try {
			$centralUser = $this->centralUserLookup->newFromAuthority( $currentUser );
			$participant = $this->participantsStore->getEventParticipant( $eventId, $centralUser, true );
			$userCanAddContributions = $this->permissionChecker->userCanAddAnyValidContribution(
					$currentUser,
					$this->event
				) || $participant;
		} catch ( UserNotGlobalException ) {
			// User is not logged in or doesn't have a global account
			$centralUser = null;
			$userCanAddContributions = false;
		}

		$this->output->addJsConfigVars( [ 'wgCampaignEventsCanAddContributions' => $userCanAddContributions ] );

		$editsPager = new EventContributionsEditsPager(
			$this->databaseHelper->getDBConnection( DB_REPLICA ),
			$this->permissionChecker,
			$this->centralUserLookup,
			$this->linkBatchFactory,
			$this->linkRenderer,
			$this->userLinker,
			$this->titleFactory,
			$this->eventContributionStore,
			$this->wikiLookup,
			$this->event,
			$this->output->getContext()
		);

		// Keep the Contributions tab active when interacting with the pager
		$editsPager->setExtraQuery( [ 'tab' => 'ContributionsPanel' ] );

		$tableContainer = new Tag( 'div' );
		$tableContainer->addClasses( [ 'ext-campaignevents-contributions-table' ] );
		$editsTableOutput = $editsPager->getFullOutput();
		$editsTableHtml = $editsTableOutput->getContentHolderText();
		$tableContainer->appendContent( new HtmlSnippet( $editsTableHtml ) );

		$container->appendContent( $tableContainer );
		return $container;
	}
}
