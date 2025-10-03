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
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Html\TemplateParser;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Output\OutputPage;
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
		LinkBatchFactory $linkBatchFactory
	) {
		$this->eventContributionStore = $eventContributionStore;
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
	}

	public function createContent(): Tag {
		$eventId = $this->event->getID();

		$container = new Tag( 'div' );
		$container->addClasses( [ 'ext-campaignevents-contributions-container' ] );

		$currentUser = $this->output->getUser();
		$centralUserId = null;
		$includePrivateParticipants = false;
		try {
			$centralUser = $this->centralUserLookup->newFromAuthority( $currentUser );
			$centralUserId = $centralUser->getCentralID();
			// Check if current user is an organizer of this event
			$includePrivateParticipants = $this->permissionChecker->userCanEditRegistration(
				$currentUser,
				$this->event
			);
		} catch ( UserNotGlobalException ) {
			// User is not logged in or doesn't have a global account
			$centralUserId = 0;
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
				'positiveValue' => $summaryData->getBytesAdded(),
				'negativeValue' => $summaryData->getBytesRemoved(),
				'separator' => $msgFormatter->format(
					MessageValue::new( 'campaignevents-contributions-summary-delta-separator' )
				),
				'label' => $msgFormatter->format(
					MessageValue::new( 'campaignevents-contributions-summary-bytes-changed' )
				)
			],
			'linksChangedCard' => [
				'positiveValue' => $summaryData->getLinksAdded(),
				'negativeValue' => $summaryData->getLinksRemoved(),
				'separator' => $msgFormatter->format(
					MessageValue::new( 'campaignevents-contributions-summary-delta-separator' )
				),
				'label' => $msgFormatter->format(
					MessageValue::new( 'campaignevents-contributions-summary-links-changed' )
				)
			],
		];

		$renderedSummaryHtml = $this->templateParser->processTemplate( 'EventContributionsSummary', $templateData );
		$container->appendContent( new HtmlSnippet( $renderedSummaryHtml ) );

		// Add table section
		$this->output->addModuleStyles( 'codex-styles' );

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

		$tableContainer = new Tag( 'div' );
		$tableContainer->addClasses( [ 'ext-campaignevents-contributions-table' ] );
		$tableOutput = $pager->getFullOutput();
		$tableHtml = $tableOutput->getContentHolderText();
		$tableContainer->appendContent( new HtmlSnippet( $tableHtml ) );

		$container->appendContent( $tableContainer );

		return $container;
	}
}
