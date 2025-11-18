<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Pager;

use MediaWiki\Cache\LinkBatchFactory;
use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContribution;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionStore;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserLinker;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\MWEntity\WikiLookup;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Html\Html;
use MediaWiki\Html\TemplateParser;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Pager\CodexTablePager;
use MediaWiki\RecentChanges\ChangesList;
use MediaWiki\Title\TitleFactory;
use MediaWiki\WikiMap\WikiMap;
use OOUI\IconWidget;
use stdClass;
use UnexpectedValueException;
use Wikimedia\Rdbms\IReadableDatabase;

/**
 * Pager for displaying event contributions in a table format
 */
class EventContributionsPager extends CodexTablePager {

	/**
	 * Unique sort fields per column, including stable tiebreaker by primary key
	 */
	private const INDEX_FIELDS = [
		'article' => [ 'cec_page_prefixedtext', 'cec_wiki', 'cec_timestamp', 'cec_id' ],
		'wiki' => [ 'cec_wiki', 'cec_timestamp', 'cec_id' ],
		'username' => [ 'cec_user_name__str', 'cec_timestamp', 'cec_id' ],
		'timestamp' => [ 'cec_timestamp', 'cec_id' ],
		'bytes' => [ 'cec_bytes_delta', 'cec_timestamp', 'cec_id' ],
	];

	private readonly TemplateParser $templateParser;

	/** @var array<int,EventContribution> */
	private array $contribObjects = [];
	/** @var bool Whether performer can delete all contributions (organizer) */
	private bool $canDeleteAll = false;
	/** @var int|null Cached performer central user ID for per-row comparisons */
	private ?int $performerCentralID = null;
	/** @var bool Whether current page has at least one deletable contribution for performer */
	private bool $resultHasDeletableContribution = false;
	/** @var array<string,mixed> */
	private array $extraQuery = [];

	public function __construct(
		IReadableDatabase $db,
		private readonly PermissionChecker $permissionChecker,
		private readonly CampaignsCentralUserLookup $centralUserLookup,
		private readonly LinkBatchFactory $linkBatchFactory,
		LinkRenderer $linkRenderer,
		private readonly UserLinker $userLinker,
		private readonly TitleFactory $titleFactory,
		private readonly EventContributionStore $eventContributionStore,
		private readonly WikiLookup $wikiLookup,
		private readonly ExistingEventRegistration $event,
		IContextSource $context,
	) {
		// Set the database before calling the parent constructor, otherwise it'll use the local one.
		$this->mDb = $db;
		parent::__construct(
			$this->msg( 'campaignevents-event-details-contributions-table-caption' )->text(),
			$context,
			$linkRenderer
		);

		$this->templateParser = new TemplateParser( __DIR__ . '/../../templates' );
	}

	/**
	 * Allow callers to pass extra query parameters that should be preserved
	 * on generated links (e.g., active tab on Special:EventDetails).
	 *
	 * @param array<string,mixed> $params
	 */
	public function setExtraQuery( array $params ): void {
		$this->extraQuery = $params;
	}

	/**
	 * @inheritDoc
	 * @return array<string,mixed>
	 */
	public function getQueryInfo(): array {
		$userCanViewPrivateParticipants = $this->permissionChecker->userCanViewPrivateParticipants(
			$this->getAuthority(),
			$this->event
		);

		$queryInfo = [
			'tables' => [
				'cec' => 'ce_event_contributions',
				'cep' => 'ce_participants'
			],
			'fields' => [
				'cec_id',
				'cec_event_id',
				'cec_page_prefixedtext',
				'cec_wiki',
				'cec_user_id',
				'cec_user_name',
				'cec_user_name__str' => 'COALESCE(cec_user_name, "")',
				'cec_timestamp',
				'cec_bytes_delta',
				'cec_links_delta',
				'cec_edit_flags',
				'cec_revision_id',
				'cec_page_id',
				'cec_deleted',
				'cep_private'
			],
			'conds' => [
				'cec.cec_event_id' => $this->event->getID(),
				'cec.cec_deleted' => 0
			],
			'join_conds' => [
				'cep' => [
					'JOIN',
					[
						'cec.cec_event_id = cep.cep_event_id',
						'cec.cec_user_id = cep.cep_user_id',
						'cep.cep_unregistered_at' => null
					]
				]
			]
		];

		if ( !$userCanViewPrivateParticipants ) {
			$orExpr = null;
			try {
				$centralId = $this->centralUserLookup
					->newFromAuthority( $this->getAuthority() )
					->getCentralID();
				$orExpr = $this->getDatabase()->orExpr( [
					'cep.cep_private' => 0,
					'cec.cec_user_id' => $centralId
				] );
			} catch ( UserNotGlobalException ) {
				// Keep default condition
			}
			if ( $orExpr ) {
				$queryInfo['conds'][] = $orExpr;
			} else {
				$queryInfo['conds']['cep.cep_private'] = 0;
			}
		}

		return $queryInfo;
	}

	/**
	 * @inheritDoc
	 */
	protected function isFieldSortable( $field ): bool {
		return isset( self::INDEX_FIELDS[$field] );
	}

	/**
	 * @inheritDoc
	 */
	public function getDefaultSort(): string {
		return 'timestamp';
	}

	/** @inheritDoc */
	protected function getDefaultDirections(): bool {
		return $this->mSort === 'timestamp' ? self::DIR_DESCENDING : self::DIR_ASCENDING;
	}

	/**
	 * @inheritDoc
	 * @return array<int,array<int,string>>
	 */
	public function getIndexField(): array {
		return [ self::INDEX_FIELDS[$this->mSort] ];
	}

	/**
	 * Preload usernames and user page links for all rows in the current result set
	 * to avoid per-row lookups.
	 *
	 * @param mixed $result The database result set to preprocess
	 */
	protected function preprocessResults( $result ): void {
		$userNamesMap = [];
		$nonVisibleUserIDsMap = [];
		$linkBatch = $this->linkBatchFactory->newLinkBatch();
		$linkBatch->setCaller( __METHOD__ );

		// Prepare permission context once per page
		// userCanDeleteAllContributions already checks if user is named internally
		$this->canDeleteAll = $this->permissionChecker->userCanDeleteAllContributions(
			$this->getAuthority(),
			$this->event
		);
		if ( !$this->canDeleteAll ) {
			try {
				$this->performerCentralID = $this->centralUserLookup
					->newFromAuthority( $this->getAuthority() )
					->getCentralID();
			} catch ( UserNotGlobalException ) {
				$this->performerCentralID = null;
			}
		}

		foreach ( $result as $row ) {
			// For visible names (the vast majority of them), we add them to the cache now so they're not looked up
			// again later. Deleted/hidden names are not cached because we can't tell which case it is (we use null for
			// both). But they are also rare enough that we can just look them up separately if needed.
			if ( $row->cec_user_name !== null ) {
				$userNamesMap[$row->cec_user_id] = $row->cec_user_name;
				$this->centralUserLookup->addNameToCache( (int)$row->cec_user_id, $row->cec_user_name );
			} else {
				$nonVisibleUserIDsMap[ $row->cec_user_id ] = null;
			}
			$this->contribObjects[ $row->cec_id ] = $this->eventContributionStore->newFromRow( $row );

			if ( WikiMap::isCurrentWikiId( $row->cec_wiki ) ) {
				$title = $this->titleFactory->newFromTextThrow( $row->cec_page_prefixedtext );
				$linkBatch->addObj( $title );
			}

			// Mark if this page has at least one deletable contribution
			if ( $this->canDeleteAll || ( (int)$row->cec_user_id === $this->performerCentralID ) ) {
				$this->resultHasDeletableContribution = true;
			}
		}

		// Preload titles in one batch to avoid per-row queries
		$linkBatch->execute();

		// Reset the result pointer for subsequent processing
		$result->seek( 0 );

		if ( $nonVisibleUserIDsMap ) {
			// Do a batch lookup for all deleted/hidden and let it be cached.
			$this->centralUserLookup->getNamesIncludingDeletedAndSuppressed( $nonVisibleUserIDsMap );
		}

		if ( $userNamesMap ) {
			$this->userLinker->preloadUserLinks( $userNamesMap );
		}
	}

	/**
	 * @inheritDoc
	 * @return string|null
	 */
	public function formatValue( $name, $value ) {
		$row = $this->mCurrentRow;
		return match ( $name ) {
			'article' => $this->formatArticle( $row ),
			'wiki' => $this->formatWiki( $row ),
			'username' => $this->formatUsername( $row ),
			'timestamp' => $this->formatTimestamp( $row ),
			'bytes' => $this->formatBytes( $row ),
			'actions' => $this->formatActions( $row ),
			default => throw new UnexpectedValueException( 'Unexpected column: ' . $name ),
		};
	}

	/**
	 * @inheritDoc
	 */
	protected function getFieldNames(): array {
		$fields = [
			'article' => $this->msg( 'campaignevents-event-details-contributions-article' )->text(),
			'wiki' => $this->msg( 'campaignevents-event-details-contributions-wiki' )->text(),
			'username' => $this->msg( 'campaignevents-event-details-contributions-username' )->text(),
			'timestamp' => $this->msg( 'campaignevents-event-details-contributions-timestamp' )->text(),
			'bytes' => $this->msg( 'campaignevents-event-details-contributions-bytes' )->text(),
		];

		// Show actions column only if this page has at least one deletable contribution
		if ( $this->resultHasDeletableContribution ) {
			$fields['actions'] = $this->msg( 'campaignevents-event-details-contributions-actions' )->text();
		}

		return $fields;
	}

	/**
	 * Format article column with link and creation icon
	 */
	private function formatArticle( stdClass $row ): string {
		$contrib = $this->contribObjects[ $row->cec_id ];
		$prefixedText = $contrib->getPagePrefixedtext();
		if ( WikiMap::isCurrentWikiId( $contrib->getWiki() ) ) {
			$title = $this->titleFactory->newFromTextThrow( $prefixedText );
			$link = $this->getLinkRenderer()->makeKnownLink( $title, $prefixedText );
		} else {
			$url = WikiMap::getForeignURL( $contrib->getWiki(), $prefixedText );
			$link = $this->getLinkRenderer()->makeExternalLink( $url, $prefixedText, $this->getTitle() );
		}
		$html = $link;

		// Add creation icon if this was a page creation
		if ( $contrib->isPageCreation() ) {
			$icon = new IconWidget( [
				'icon' => 'articleAdd',
				'classes' => [ 'ext-campaignevents-contributions-creation-icon' ],
				'title' => $this->msg( 'campaignevents-event-details-contributions-article-created-tooltip' )->text(),
				'label' => $this->msg( 'campaignevents-event-details-contributions-article-created-tooltip' )->text()
			] );
			$html = $icon->toString() . ' ' . $html;
		}

		return $html;
	}

	private function formatWiki( stdClass $row ): string {
		static $wikiNameCache = [];
		$wikiID = $row->cec_wiki;
		$wikiNameCache[$wikiID] ??= $this->wikiLookup->getLocalizedNames( [ $wikiID ] )[$wikiID];
		return $wikiNameCache[$wikiID];
	}

	/**
	 * Format username column with link
	 */
	private function formatUsername( stdClass $row ): string {
		$contrib = $this->contribObjects[$row->cec_id];
		$isPrivateParticipant = $row->cep_private;
		$centralUserID = $contrib->getUserId();
		$centralUser = new CentralUser( $centralUserID );
		$html = '';
		if ( $isPrivateParticipant ) {
			$icon = new IconWidget( [
				'icon' => 'lock',
				'classes' => [ 'ext-campaignevents-contributions-private-participant' ],
				'title' => $this->msg(
					'campaignevents-event-details-contributions-private-participant-tooltip'
				)->text(),
				'label' => $this->msg(
					'campaignevents-event-details-contributions-private-participant-tooltip'
				)->text()
			] );
			$html .= $icon->toString() . ' ';
		}
		$html .= $this->userLinker->generateUserLinkWithFallback(
			$this->getContext(),
			$centralUser,
			$this->getLanguage()->getCode()
		);

		return Html::rawElement(
			'span',
			[ 'class' => 'campaignevents-event-details-contributions-username-wrapper' ],
			$html
		);
	}

	/**
	 * Format timestamp column with diff link
	 */
	private function formatTimestamp( stdClass $row ): string {
		$contrib = $this->contribObjects[ $row->cec_id ];
		$formattedTime = $this->getLanguage()->userTimeAndDate(
			$contrib->getTimestamp(), $this->getAuthority()->getUser()
		);
		$prefixedText = $contrib->getPagePrefixedtext();
		$diffParams = [
			'oldid' => $contrib->getRevisionId(),
			'diff' => 'prev'
		];
		if ( WikiMap::isCurrentWikiId( $contrib->getWiki() ) ) {
			$title = $this->titleFactory->newFromTextThrow( $prefixedText );
			$link = $this->getLinkRenderer()->makeKnownLink( $title, $formattedTime, [], $diffParams );
		} else {
			$url = WikiMap::getForeignURL( $contrib->getWiki(), $prefixedText );
			$diffUrl = wfAppendQuery( $url, $diffParams );
			$link = $this->getLinkRenderer()->makeExternalLink( $diffUrl, $formattedTime, $this->getTitle() );
		}

		return $link;
	}

	/**
	 * Format bytes column with +/- indicator and color styling
	 */
	private function formatBytes( stdClass $row ): string {
		$contrib = $this->contribObjects[ $row->cec_id ];
		$bytes = $contrib->getBytesDelta();
		return ChangesList::showCharacterDifference( 0, $bytes, $this->getContext() );
	}

	/**
	 * Format actions column with delete button
	 */
	private function formatActions( stdClass $row ): string {
		$contrib = $this->contribObjects[ $row->cec_id ];
		$contribID = $row->cec_id;

		// Check if user can delete this contribution
		if ( !$this->canDeleteAll ) {
			$targetUserID = $contrib->getUserID();
			// Non-organizer can only delete their own contributions
			if ( $this->performerCentralID === null || $targetUserID !== $this->performerCentralID ) {
				return '';
			}
		}

		// Render Codex CSS-only delete button via mustache template
		return $this->templateParser->processTemplate(
			'DeleteContributionButton',
			[
				'contribId' => $contribID,
				'tooltip' => $this->msg( 'campaignevents-event-details-contributions-delete-tooltip' )->text(),
			]
		);
	}

	/**
	 * Override getDefaultQuery to ensure tab parameter is preserved
	 * @return array<string,mixed>
	 */
	public function getDefaultQuery(): array {
		return parent::getDefaultQuery() + $this->extraQuery;
	}

	protected function getTableClass(): string {
		return 'ext-campaignevents-contributions-table' . ' ' . parent::getTableClass();
	}

	protected function shouldShowVisibleCaption(): bool {
		return true;
	}
}
