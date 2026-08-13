<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\FrontendModules;

use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionStore;
use MediaWiki\Extension\CampaignEvents\EventGoal\GoalProgressFormatter;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\Special\SpecialEventDetails;
use MediaWiki\Html\Html;
use MediaWiki\Html\TemplateParser;
use MediaWiki\Output\OutputPage;
use MediaWiki\RecentChanges\ChangesList;
use OOUI\HtmlSnippet;
use OOUI\Tag;
use Wikimedia\Codex\Component\HtmlSnippet as CodexHtmlSnippet;
use Wikimedia\Codex\Localization\MediaWikiLocalization;
use Wikimedia\Codex\Utility\Codex;
use Wikimedia\Message\IMessageFormatterFactory;
use Wikimedia\Message\MessageValue;

readonly class EventContributionCombinedModule {

	private TemplateParser $templateParser;
	private Codex $codex;

	public function __construct(
		private CampaignsCentralUserLookup $centralUserLookup,
		private PermissionChecker $permissionChecker,
		private EventContributionStore $eventContributionStore,
		private GoalProgressFormatter $goalProgressFormatter,
		private IMessageFormatterFactory $messageFormatterFactory,
		private ParticipantsStore $participantsStore,
		private EventContributionEditorsModule $editorsModule,
		private EventContributionEditsModule $editsModule,
		private ExistingEventRegistration $event,
		private OutputPage $output,
	) {
		$this->templateParser = new TemplateParser( __DIR__ . '/../../templates' );
		$this->codex = new Codex( new MediaWikiLocalization( $output ) );
	}

	public const EDITORS_MODULE = 'editors';
	public const EDITS_MODULE = 'edits';

	public function createContent(): Tag {
		$container = new Tag();

		$goalProgressData = $this->goalProgressFormatter->getProgressData(
			$this->event,
			$this->output->getAuthority(),
			$this->output->getLanguage()->getCode()
		);
		if ( $goalProgressData ) {
			$goalProgressHtml = $this->templateParser->processTemplate( 'GoalProgressBar', $goalProgressData );
			$container->appendContent( new HtmlSnippet( $goalProgressHtml ) );
		}

		$container->appendContent( $this->getContributionsSummaryModule() );
		$title = $this->output->getTitle();
		$editorsLink = $title->getLinkURL(
			[
				'module' => self::EDITORS_MODULE,
				'tab' => SpecialEventDetails::CONTRIBUTIONS_PANEL
			]
		);
		$editsLink = $title->getLinkURL(
			[
				'module' => self::EDITS_MODULE,
				'tab' => SpecialEventDetails::CONTRIBUTIONS_PANEL
			]
		);
		$module = $this->output->getRequest()->getRawVal( 'module' );
		$buttonContainer = ( new Tag() )->addClasses(
			[ 'ext-campaignevents-eventdetails-contributions-button-container' ]
		);
		$editorsButton = $this->codex->button(
			label: $this->output->msg(
				'campaignevents-event-details-contributions-editors-button-label'
			)->text(),
			action: $module === self::EDITORS_MODULE ? 'progressive' : 'default',
			weight: 'primary',
			attributes: [ 'class' => 'ext-campaignevents-eventdetails-contributions-editors-button' ],
			href: $editorsLink
		)->getHtml();

		$editsButton = $this->codex->button(
			label: $this->output->msg(
				'campaignevents-event-details-contributions-edits-button-label'
			)->text(),
			action: $module === self::EDITS_MODULE || $module === null ? 'progressive' : 'default',
			weight: 'primary',
			attributes: [ 'class' => 'ext-campaignevents-eventdetails-contributions-edits-button' ],
			href: $editsLink
		)->getHtml();
		$buttonContainer->appendContent( [
			new HtmlSnippet( $editsButton ),
			new HtmlSnippet( $editorsButton ),
		] );
		$container->appendContent( $buttonContainer );

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
		$cards = [
			$this->makeCard(
				$language->formatNum( $summaryData->getParticipantsCount() ),
				$msgFormatter->format( MessageValue::new( 'campaignevents-contributions-summary-participants' ) )
			),
			$this->makeCard(
				$language->formatNum( $summaryData->getWikisEditedCount() ),
				$msgFormatter->format( MessageValue::new( 'campaignevents-contributions-summary-wikis-edited' ) )
			),
			$this->makeCard(
				$language->formatNum( $summaryData->getArticlesCreatedCount() ),
				$msgFormatter->format( MessageValue::new( 'campaignevents-contributions-summary-articles-created' ) )
			),
			$this->makeCard(
				$language->formatNum( $summaryData->getArticlesEditedCount() ),
				$msgFormatter->format( MessageValue::new( 'campaignevents-contributions-summary-articles-edited' ) )
			),
			$this->makeCard(
				$language->formatNum( $summaryData->getEditCount() ),
				$msgFormatter->format( MessageValue::new( 'campaignevents-contributions-summary-edit-count' ) )
			),
			$this->makeCard(
				$this->codex->htmlSnippet(
					$this->formatDeltas( $summaryData->getBytesAdded(), $summaryData->getBytesRemoved() )
				),
				$msgFormatter->format( MessageValue::new( 'campaignevents-contributions-summary-bytes-changed' ) )
			),
			$this->makeCard(
				$this->codex->htmlSnippet(
					$this->formatDeltas( $summaryData->getLinksAdded(), $summaryData->getLinksRemoved() )
				),
				$msgFormatter->format( MessageValue::new( 'campaignevents-contributions-summary-links-changed' ) )
			),
		];
		$summaryHtml = Html::rawElement(
			'div',
			[ 'class' => 'ext-campaignevents-eventdetails-contributions-summary' ],
			implode( '', $cards )
		);
		$privateCount = $this->participantsStore->getPrivateParticipantCountForEvent( $eventId );
		$showMessage = ( !$participantIsPrivate && $privateCount > 0 ) ||
			( $participantIsPrivate && $privateCount > 1 );
		if ( !$includePrivateParticipants && $showMessage ) {
			$messageKey = $participantIsPrivate
				? 'campaignevents-contributions-notice-other-private-participants-excluded'
				: 'campaignevents-contributions-notice-private-participants-excluded';
			$renderedNotice = $this->codex->message( $msgFormatter->format( MessageValue::new( $messageKey ) ) )
				->getHtml();
			$container->appendContent( new HtmlSnippet( $renderedNotice ) );
		}

		return $container->appendContent( new HtmlSnippet( $summaryHtml ) );
	}

	/**
	 * Renders a summary card. Values containing raw HTML (e.g. delta formatting) must be passed
	 * as an HtmlSnippet, which Codex leaves unescaped; plain strings are escaped for us.
	 */
	private function makeCard( string|CodexHtmlSnippet $value, string $label ): string {
		return $this->codex->card(
			title: $value,
			description: $label
		)->getHtml();
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
