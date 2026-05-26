<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Pager;

use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\WikiLookup;
use MediaWiki\Html\Html;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Page\LinkBatchFactory;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Pager\CodexTablePager;
use MediaWiki\Title\TitleFactory;
use MediaWiki\WikiMap\WikiMap;
use stdClass;
use UnexpectedValueException;
use Wikimedia\Rdbms\IResultWrapper;

/**
 * Pager that lists the worklist pages associated with an event, rendered in the "Worklist" tab
 * on Special:EventDetails.
 */
class WorklistPagesPager extends CodexTablePager {

	/**
	 * Unique sort fields per column, including stable tiebreaker by primary key.
	 */
	private const INDEX_FIELDS = [
		'page' => [ 'cewp_page_prefixedtext', 'cewp_wiki', 'cewp_timestamp', 'cewp_id' ],
		'wiki' => [ 'cewp_wiki', 'cewp_timestamp', 'cewp_id' ],
		'timestamp' => [ 'cewp_timestamp', 'cewp_id' ],
	];

	/** @var array<string,mixed> */
	private array $extraQuery = [];

	public function __construct(
		CampaignsDatabaseHelper $databaseHelper,
		private readonly LinkBatchFactory $linkBatchFactory,
		private readonly TitleFactory $titleFactory,
		private readonly WikiLookup $wikiLookup,
		IContextSource $context,
		LinkRenderer $linkRenderer,
		private readonly ExistingEventRegistration $event,
		private readonly ?PageIdentity $worklistPage = null,
	) {
		// Set the database before calling the parent constructor, otherwise it'll use the local one.
		$this->mDb = $databaseHelper->getReplicaConnection();
		parent::__construct(
			$this->msg( 'campaignevents-worklist-table-header' )->text(),
			$context,
			$linkRenderer
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
		// Pages belong to a worklist (cewp_cew_id), and worklists are associated with events via
		// ce_worklist_events, so the event filter is applied through that join.
		return [
			'tables' => [
				'cewp' => 'ce_worklist_pages',
				'cewe' => 'ce_worklist_events',
			],
			'fields' => [
				'cewp_id',
				'cewp_page_prefixedtext',
				'cewp_wiki',
				'cewp_timestamp',
			],
			'conds' => [
				'cewe.cewe_event_id' => $this->event->getID(),
			],
			'join_conds' => [
				'cewe' => [ 'JOIN', 'cewp.cewp_cew_id = cewe.cewe_cew_id' ],
			],
		];
	}

	/** @inheritDoc */
	protected function isFieldSortable( $field ): bool {
		return isset( self::INDEX_FIELDS[$field] );
	}

	/** @inheritDoc */
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
	 * @param IResultWrapper $result
	 */
	public function preprocessResults( $result ): void {
		$linkBatch = $this->linkBatchFactory->newLinkBatch();
		$linkBatch->setCaller( __METHOD__ );

		foreach ( $result as $row ) {
			// Batch preload page titles for current wiki only
			if ( WikiMap::isCurrentWikiId( $row->cewp_wiki ) ) {
				$title = $this->titleFactory->newFromTextThrow( $row->cewp_page_prefixedtext );
				$linkBatch->addObj( $title );
			}
		}

		$result->seek( 0 );

		$linkBatch->execute();
	}

	/** @inheritDoc */
	public function formatValue( $name, $value ): ?string {
		$row = $this->mCurrentRow;
		return match ( $name ) {
			'page' => $this->formatPage( $row ),
			'wiki' => $this->formatWiki( $row ),
			'timestamp' => $this->formatTimestamp( $row ),
			default => throw new UnexpectedValueException( 'Unexpected column: ' . $name ),
		};
	}

	/** @inheritDoc */
	protected function getFieldNames(): array {
		return [
			'page' => $this->msg( 'campaignevents-worklist-table-article-column-header' )->text(),
			'wiki' => $this->msg( 'campaignevents-worklist-table-wiki-column-header' )->text(),
			'timestamp' => $this->msg( 'campaignevents-worklist-table-date-column-header' )->text(),
		];
	}

	/** @inheritDoc */
	protected function getEmptyBody(): string {
		$colspan = count( $this->getFieldNames() );
		$msgEmpty = $this->msg( 'campaignevents-worklist-empty-state' )->text();
		return Html::rawElement( 'tr', [ 'class' => 'cdx-table__table__empty-state' ],
			Html::element(
				'td',
				[ 'class' => 'cdx-table__table__empty-state-content', 'colspan' => $colspan ],
				$msgEmpty
			)
		);
	}

	private function formatPage( stdClass $row ): string {
		$prefixedText = $row->cewp_page_prefixedtext;
		if ( WikiMap::isCurrentWikiId( $row->cewp_wiki ) ) {
			$title = $this->titleFactory->newFromTextThrow( $prefixedText );
			return $this->getLinkRenderer()->makeLink( $title, $prefixedText );
		}
		$url = WikiMap::getForeignURL( $row->cewp_wiki, $prefixedText );
		return $this->getLinkRenderer()->makeExternalLink( $url, $prefixedText, $this->getTitle() );
	}

	private function formatWiki( stdClass $row ): string {
		static $escapedNamesCache = [];
		$wikiID = $row->cewp_wiki;
		$escapedNamesCache[$wikiID] ??= htmlspecialchars(
			$this->wikiLookup->getLocalizedNames( [ $wikiID ] )[$wikiID]
		);
		return $escapedNamesCache[$wikiID];
	}

	private function formatTimestamp( stdClass $row ): string {
		return htmlspecialchars( $this->getLanguage()->userTimeAndDate(
			$row->cewp_timestamp,
			$this->getAuthority()->getUser()
		) );
	}

	/**
	 * Override getDefaultQuery to ensure tab parameter is preserved
	 * @return array<string,mixed>
	 */
	public function getDefaultQuery(): array {
		return parent::getDefaultQuery() + $this->extraQuery;
	}

	protected function shouldShowVisibleCaption(): bool {
		return true;
	}
}
