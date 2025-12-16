<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\FrontendModules;

use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionStore;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Html\TemplateParser;
use MediaWiki\Output\OutputPage;
use MediaWiki\RecentChanges\ChangesList;
use OOUI\HtmlSnippet;
use OOUI\Tag;
use Wikimedia\Message\IMessageFormatterFactory;
use Wikimedia\Message\MessageValue;

readonly class EventContributionCombinedModule {

	public function __construct(
		private CampaignsCentralUserLookup $centralUserLookup,
		private PermissionChecker $permissionChecker,
		private EventContributionStore $eventContributionStore,
		private IMessageFormatterFactory $messageFormatterFactory,
		private ParticipantsStore $participantsStore,
		private ExistingEventRegistration $event,
		private OutputPage $output,
		private EventContributionEditorsModule $editorsModule,
		private EventContributionEditsModule $editsModule,
	) {
	}

	public const EDITORS_MODULE = 'editors';

	public function createContent(): Tag {
		$container = $this->getContributionsSummaryModule();
		$module = $this->output->getRequest()->getRawVal( 'module' );

		if ( $module === self::EDITORS_MODULE ) {
			$container->appendContent( $this->editorsModule->createContent() );
		} else {
			$container->appendContent( $this->editsModule->createContent() );
		}

		return $container;
	}

	private function getContributionsSummaryModule(): Tag {
		$container = new Tag();
		$eventId = $this->event->getID();
		$currentUser = $this->output->getAuthority();
		$templateParser = new TemplateParser( __DIR__ . '/../../templates' );
		try {
			$centralUser = $this->centralUserLookup->newFromAuthority( $currentUser );
			$participant = $this->participantsStore->getEventParticipant( $eventId, $centralUser, true );
			$includePrivateParticipants = $this->permissionChecker->userCanViewPrivateParticipants(
				$currentUser,
				$this->event
			);
			$participantIsPrivate = $participant?->isPrivateRegistration();
		} catch ( UserNotGlobalException ) {
			// User is not logged in or doesn't have a global account
			$centralUser = null;
			$includePrivateParticipants = false;
			$participantIsPrivate = false;
		}
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
		$showMessage = ( !$participantIsPrivate && $privateCount > 0 ) ||
			( $participantIsPrivate && $privateCount > 1 );
		if ( !$includePrivateParticipants && $showMessage ) {
			$messageKey = $participantIsPrivate
				? 'campaignevents-contributions-notice-other-private-participants-excluded'
				: 'campaignevents-contributions-notice-private-participants-excluded';
			$notice = [
				'status' => 'notice',
				'message' => $msgFormatter->format(
					MessageValue::new( $messageKey )
				)
			];
			$renderedNotice = $templateParser->processTemplate( 'Message', $notice );
			$container->appendContent( new HtmlSnippet( $renderedNotice ) );
		}
		$renderedSummaryHtml = $templateParser->processTemplate( 'EventContributionsSummary', $templateData );
		return $container->appendContent( new HtmlSnippet( $renderedSummaryHtml ) );
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
