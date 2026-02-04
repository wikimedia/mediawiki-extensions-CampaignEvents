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
use MediaWiki\Extension\CampaignEvents\Pager\EventContributionsPager;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Html\TemplateParser;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Output\OutputPage;
use MediaWiki\Page\LinkBatchFactory;
use MediaWiki\RecentChanges\ChangesList;
use MediaWiki\Title\TitleFactory;
use OOUI\HtmlSnippet;
use OOUI\Tag;
use Wikimedia\Message\IMessageFormatterFactory;
use Wikimedia\Message\MessageValue;

class EventContributionsModule {
	private TemplateParser $templateParser;

	public function __construct(
		private readonly IMessageFormatterFactory $messageFormatterFactory,
		private readonly PermissionChecker $permissionChecker,
		private readonly CampaignsCentralUserLookup $centralUserLookup,
		private readonly LinkRenderer $linkRenderer,
		private readonly UserLinker $userLinker,
		private readonly CampaignsDatabaseHelper $databaseHelper,
		private readonly TitleFactory $titleFactory,
		private readonly EventContributionStore $eventContributionStore,
		private readonly LinkBatchFactory $linkBatchFactory,
		private readonly ParticipantsStore $participantsStore,
		private readonly WikiLookup $wikiLookup,
		private readonly OutputPage $output,
		private readonly ExistingEventRegistration $event,
	) {
		$this->templateParser = new TemplateParser( __DIR__ . '/../../templates' );
	}

	public function createContent(): Tag {
		$eventId = $this->event->getID();

		$this->output->addModuleStyles( 'codex-styles' );
		$container = new Tag( 'div' );
		$container->addClasses( [ 'ext-campaignevents-contributions-container' ] );

		$currentUser = $this->output->getAuthority();
		try {
			$centralUser = $this->centralUserLookup->newFromAuthority( $currentUser );
			$participant = $this->participantsStore->getEventParticipant( $eventId, $centralUser, true );
			$userCanAddContributions = $this->permissionChecker->userCanAddAnyValidContribution(
				$currentUser,
				$this->event
			) || $participant;
			$includePrivateParticipants = $this->permissionChecker->userCanViewPrivateParticipants(
				$currentUser,
				$this->event
			);
			$participantIsPrivate =
				$participant?->isPrivateRegistration();
		} catch ( UserNotGlobalException ) {
			// User is not logged in or doesn't have a global account
			$centralUser = null;
			$includePrivateParticipants = false;
			$participantIsPrivate = false;
			$userCanAddContributions = false;
		}

		$this->output->addJsConfigVars( [ 'wgCampaignEventsCanAddContributions' => $userCanAddContributions ] );

		$summaryData = $this->eventContributionStore->getEventSummaryData(
			$eventId,
			$centralUser,
			$includePrivateParticipants
		);
		$msgFormatter = $this->messageFormatterFactory->getTextFormatter( $this->output->getLanguage()->getCode() );
		$language = $this->output->getLanguage();

		$templateData = [
			'participantsCard' => [
				'value' => $language->formatNum( $summaryData->getParticipantsCount() ),
				'label' => $msgFormatter->format(
					MessageValue::new( 'campaignevents-contributions-summary-participants' )
				)
			],
			'wikisEditedCard' => [
				'value' => $language->formatNum( $summaryData->getWikisEditedCount() ),
				'label' => $msgFormatter->format(
					MessageValue::new( 'campaignevents-contributions-summary-wikis-edited' )
				)
			],
			'articlesCreatedCard' => [
				'value' => $language->formatNum( $summaryData->getArticlesCreatedCount() ),
				'label' => $msgFormatter->format(
					MessageValue::new( 'campaignevents-contributions-summary-articles-created' )
				)
			],
			'articlesEditedCard' => [
				'value' => $language->formatNum( $summaryData->getArticlesEditedCount() ),
				'label' => $msgFormatter->format(
					MessageValue::new( 'campaignevents-contributions-summary-articles-edited' )
				)
			],
			'editCountCard' => [
				'value' => $language->formatNum( $summaryData->getEditCount() ),
				'label' => $msgFormatter->format(
					MessageValue::new( 'campaignevents-contributions-summary-edit-count' )
				)
			],
			'bytesChangedCard' => [
				'value' => $this->formatDeltas( $summaryData->getBytesAdded(), $summaryData->getBytesRemoved() ),
				'label' => $msgFormatter->format(
					MessageValue::new( 'campaignevents-contributions-summary-bytes-changed' )
				)
			],
			'linksChangedCard' => [
				'value' => $this->formatDeltas( $summaryData->getLinksAdded(), $summaryData->getLinksRemoved() ),
				'label' => $msgFormatter->format(
					MessageValue::new( 'campaignevents-contributions-summary-links-changed' )
				)
			],
		];

		$privateCount = $this->participantsStore->getPrivateParticipantCountForEvent( $eventId );
		$showMessage = ( !$participantIsPrivate && $privateCount > 0 )
			|| ( $participantIsPrivate && $privateCount > 1 );
		if ( !$includePrivateParticipants && $showMessage ) {
			$messageKey = $participantIsPrivate
			 ?
				'campaignevents-contributions-notice-other-private-participants-excluded'
				: 'campaignevents-contributions-notice-private-participants-excluded';
			$notice = [
				'status' => 'notice',
				'message' => $msgFormatter->format(
					MessageValue::new( $messageKey )
				)
			];
			$renderedNotice = $this->templateParser->processTemplate( 'Message', $notice );
			$container->appendContent( new HtmlSnippet( $renderedNotice ) );
		}
		$renderedSummaryHtml = $this->templateParser->processTemplate( 'EventContributionsSummary', $templateData );
		$container->appendContent( new HtmlSnippet( $renderedSummaryHtml ) );

		// Add table section
		$pager = new EventContributionsPager(
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
		$pager->setExtraQuery( [ 'tab' => 'ContributionsPanel' ] );

		$tableContainer = new Tag( 'div' );
		$tableContainer->addClasses( [ 'ext-campaignevents-contributions-table' ] );
		$tableOutput = $pager->getFullOutput();
		$tableHtml = $tableOutput->getContentHolderText();
		$tableContainer->appendContent( new HtmlSnippet( $tableHtml ) );

		$container->appendContent( $tableContainer );

		return $container;
	}

	/**
	 * Formats a negative and a positive deltas for a summary card.
	 */
	private function formatDeltas( int $added, int $removed ): string {
		// XXX: We are using `showCharacterDifference` even for things that aren't bytes/characters. That should be
		// fine, hopefully.
		return ChangesList::showCharacterDifference( 0, $added, $this->output->getContext() ) .
			$this->output->msg( 'campaignevents-contributions-summary-delta-separator' )->escaped() .
			ChangesList::showCharacterDifference( -$removed, 0, $this->output->getContext() );
	}
}
