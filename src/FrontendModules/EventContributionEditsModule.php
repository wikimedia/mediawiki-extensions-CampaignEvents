<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\FrontendModules;

use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\Pager\EventContributionsPagerFactory;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Output\OutputPage;
use OOUI\HtmlSnippet;
use OOUI\Tag;

readonly class EventContributionEditsModule {
	public function __construct(
		private PermissionChecker $permissionChecker,
		private CampaignsCentralUserLookup $centralUserLookup,
		private ParticipantsStore $participantsStore,
		private EventContributionsPagerFactory $eventContributionsPagerFactory,
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

		// Do not show the button if the edit isn't ongoing, T413391.
		$canAddContributions = $userCanAddContributions && $this->event->isOngoing();
		$this->output->addJsConfigVars( [ 'wgCampaignEventsCanAddContributions' => $canAddContributions ] );

		$editsPager = $this->eventContributionsPagerFactory->newEditsPager(
			$this->output->getContext(),
			$this->linkRenderer,
			$this->event,
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
