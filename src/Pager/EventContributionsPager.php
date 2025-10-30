<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Pager;

use MediaWiki\Cache\LinkBatchFactory;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContribution;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionStore;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserLinker;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Pager\CodexTablePager;
use MediaWiki\RecentChanges\ChangesList;
use MediaWiki\Title\TitleFactory;
use MediaWiki\WikiMap\WikiMap;
use OOUI\IconWidget;
use stdClass;
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
		'username' => [ 'cec_user_name', 'cec_timestamp', 'cec_id' ],
		'timestamp' => [ 'cec_timestamp', 'cec_id' ],
		'bytes' => [ 'cec_bytes_delta', 'cec_timestamp', 'cec_id' ],
	];

	private ExistingEventRegistration $event;
	private PermissionChecker $permissionChecker;
	private LinkBatchFactory $linkBatchFactory;
	private LinkRenderer $linkRenderer;
	private CampaignsCentralUserLookup $centralUserLookup;

	private UserLinker $userLinker;
	private TitleFactory $titleFactory;
	private EventContributionStore $eventContributionStore;
	/** @var array<int,EventContribution> */
	private array $contribObjects = [];
	/** @var array<string,mixed> */
	private array $extraQuery = [];

	public function __construct(
		IReadableDatabase $db,
		PermissionChecker $permissionChecker,
		CampaignsCentralUserLookup $centralUserLookup,
		LinkBatchFactory $linkBatchFactory,
		LinkRenderer $linkRenderer,
		UserLinker $userLinker,
		TitleFactory $titleFactory,
		EventContributionStore $eventContributionStore,
		ExistingEventRegistration $event
	) {
		$this->event = $event;
		$this->permissionChecker = $permissionChecker;
		$this->centralUserLookup = $centralUserLookup;
		$this->linkBatchFactory = $linkBatchFactory;
		$this->linkRenderer = $linkRenderer;
		$this->userLinker = $userLinker;
		$this->titleFactory = $titleFactory;
		$this->eventContributionStore = $eventContributionStore;
		$this->mDb = $db;

		parent::__construct(
			$this->msg( 'campaignevents-event-details-contributions-table-caption' )->text()
		);
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
		switch ( $name ) {
			case 'article':
				return $this->formatArticle( $row );
			case 'wiki':
				return $this->formatWiki( $row );
			case 'username':
				return $this->formatUsername( $row );
			case 'timestamp':
				return $this->formatTimestamp( $row );
			case 'bytes':
				return $this->formatBytes( $row );
		}
	}

	/**
	 * @inheritDoc
	 */
	protected function getFieldNames(): array {
		return [
			'article' => $this->msg( 'campaignevents-event-details-contributions-article' )->text(),
			'wiki' => $this->msg( 'campaignevents-event-details-contributions-wiki' )->text(),
			'username' => $this->msg( 'campaignevents-event-details-contributions-username' )->text(),
			'timestamp' => $this->msg( 'campaignevents-event-details-contributions-timestamp' )->text(),
			'bytes' => $this->msg( 'campaignevents-event-details-contributions-bytes' )->text(),
		];
	}

	/**
	 * Format article column with link and creation icon
	 */
	private function formatArticle( stdClass $row ): string {
		$contrib = $this->contribObjects[ $row->cec_id ];
		$prefixedText = $contrib->getPagePrefixedtext();
		if ( WikiMap::isCurrentWikiId( $contrib->getWiki() ) ) {
			$title = $this->titleFactory->newFromTextThrow( $prefixedText );
			$link = $this->linkRenderer->makeKnownLink( $title, $prefixedText );
		} else {
			$url = WikiMap::getForeignURL( $contrib->getWiki(), $prefixedText );
			$link = $this->linkRenderer->makeExternalLink( $url, $prefixedText, $this->getTitle() );
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

	/**
	 * Format wiki column
	 */
	private function formatWiki( stdClass $row ): string {
		return WikiMap::getWikiName( $row->cec_wiki );
	}

	/**
	 * Format username column with link
	 */
	private function formatUsername( stdClass $row ): string {
		$contrib = $this->contribObjects[$row->cec_id];
		$centralUserID = $contrib->getUserId();
		$centralUser = new CentralUser( $centralUserID );
		return $this->userLinker->generateUserLinkWithFallback(
			$this->getContext(),
			$centralUser,
			$this->getLanguage()->getCode()
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
			$link = $this->linkRenderer->makeKnownLink( $title, $formattedTime, [], $diffParams );
		} else {
			$url = WikiMap::getForeignURL( $contrib->getWiki(), $prefixedText );
			$diffUrl = wfAppendQuery( $url, $diffParams );
			$link = $this->linkRenderer->makeExternalLink( $diffUrl, $formattedTime, $this->getTitle() );
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
	 * Override getDefaultQuery to ensure tab parameter is preserved
	 * @return array<string,mixed>
	 */
	public function getDefaultQuery(): array {
		return parent::getDefaultQuery() + $this->extraQuery;
	}
}
