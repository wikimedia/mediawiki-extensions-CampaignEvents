<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\FrontendModules;

use MediaWiki\Cache\LinkBatchFactory;
use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionStore;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserLinker;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\Pager\EventContributionsPager;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Html\TemplateParser;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Output\OutputPage;
use MediaWiki\RecentChanges\ChangesList;
use MediaWiki\Title\TitleFactory;
use OOUI\HtmlSnippet;
use OOUI\Tag;
use Wikimedia\Message\IMessageFormatterFactory;
use Wikimedia\Message\MessageValue;

class EventContributionsModule {

	private TemplateParser $templateParser;
	private IMessageFormatterFactory $messageFormatterFactory;
	private ExistingEventRegistration $event;
	private PermissionChecker $permissionChecker;
	private LinkRenderer $linkRenderer;
	private CampaignsDatabaseHelper $databaseHelper;
	private CampaignsCentralUserLookup $centralUserLookup;
	private OutputPage $output;
	private UserLinker $userLinker;
	private TitleFactory $titleFactory;
	private EventContributionStore $eventContributionStore;
	private LinkBatchFactory $linkBatchFactory;
	private ParticipantsStore $participantsStore;

	public function __construct(
		IMessageFormatterFactory $messageFormatterFactory,
		ExistingEventRegistration $event,
		PermissionChecker $permissionChecker,
		CampaignsCentralUserLookup $centralUserLookup,
		LinkRenderer $linkRenderer,
		UserLinker $userLinker,
		CampaignsDatabaseHelper $databaseHelper,
		TitleFactory $titleFactory,
		EventContributionStore $eventContributionStore,
		OutputPage $output,
		LinkBatchFactory $linkBatchFactory,
		ParticipantsStore $participantsStore,
	) {
		$this->messageFormatterFactory = $messageFormatterFactory;
		$this->templateParser = new TemplateParser( __DIR__ . '/../../templates' );
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
		$this->participantsStore = $participantsStore;
	}

	public function createContent(): Tag {
		$eventId = $this->event->getID();

		$this->output->addModuleStyles( 'codex-styles' );
		$container = new Tag( 'div' );
		$container->addClasses( [ 'ext-campaignevents-contributions-container' ] );

		$currentUser = $this->output->getAuthority();
		try {
			$centralUser = $this->centralUserLookup->newFromAuthority( $currentUser );
			$centralUserId = $centralUser->getCentralID();
			$includePrivateParticipants = $this->permissionChecker->userCanViewPrivateParticipants(
				$currentUser,
				$this->event
			);
			$participantIsPrivate =
				$this->participantsStore->getEventParticipant( $eventId, $centralUser, true )?->isPrivateRegistration();
		} catch ( UserNotGlobalException ) {
			// User is not logged in or doesn't have a global account
			$centralUserId = 0;
			$includePrivateParticipants = false;
			$participantIsPrivate = false;
		}

		$summaryData = $this->eventContributionStore->getEventSummaryData(
			$eventId,
			$centralUserId,
			$includePrivateParticipants
		);
		$msgFormatter = $this->messageFormatterFactory->getTextFormatter( $this->output->getLanguage()->getCode() );

		$templateData = [
			'participantsCard' => [
				'value' => $summaryData->getParticipantsCount(),
				'label' => $msgFormatter->format(
					MessageValue::new( 'campaignevents-contributions-summary-participants' )
				)
			],
			'wikisEditedCard' => [
				'value' => $summaryData->getWikisEditedCount(),
				'label' => $msgFormatter->format(
					MessageValue::new( 'campaignevents-contributions-summary-wikis-edited' )
				)
			],
			'articlesCreatedCard' => [
				'value' => $summaryData->getArticlesCreatedCount(),
				'label' => $msgFormatter->format(
					MessageValue::new( 'campaignevents-contributions-summary-articles-created' )
				)
			],
			'articlesEditedCard' => [
				'value' => $summaryData->getArticlesEditedCount(),
				'label' => $msgFormatter->format(
					MessageValue::new( 'campaignevents-contributions-summary-articles-edited' )
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
			$this->event
		);

		// Ensure the pager gets the correct context with request parameters
		$pager->setContext( $this->output->getContext() );
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
