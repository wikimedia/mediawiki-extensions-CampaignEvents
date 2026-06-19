<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Pager;

use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\WikiLookup;
use MediaWiki\Html\Html;
use MediaWiki\Html\TemplateParser;
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

	private readonly TemplateParser $templateParser;

	/** Whether the performer may remove worklist articles. Memoized. */
	private ?bool $canRemoveArticles = null;

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
		$this->templateParser = new TemplateParser( __DIR__ . '/../../templates' );
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

	/**
	 * Whether the performer may remove worklist articles. The rule is: a named (logged-in) user who
	 * can edit the worklist page may remove articles. When the worklist page is local we defer to
	 * MediaWiki's permission system via probablyCan( 'edit', ... ), which also accounts for
	 * page-specific (non-sitewide) blocks. When the worklist page is foreign ($worklistPage is null,
	 * as it can't be resolved to a local title), permissions can't be evaluated here, so isNamed() is
	 * used as a quick proxy and the full checks run at edit time (via ForeignApi). This only controls
	 * button visibility.
	 */
	private function canRemoveArticles(): bool {
		$performer = $this->getAuthority();
		$this->canRemoveArticles ??= $performer->isNamed()
			&& ( $this->worklistPage === null
				|| $performer->probablyCan( 'edit', $this->worklistPage ) );
		return $this->canRemoveArticles;
	}

	/** @inheritDoc */
	public function formatValue( $name, $value ): ?string {
		$row = $this->mCurrentRow;
		return match ( $name ) {
			'page' => $this->formatPage( $row ),
			'wiki' => $this->formatWiki( $row ),
			'timestamp' => $this->formatTimestamp( $row ),
			'actions' => $this->formatActions( $row ),
			default => throw new UnexpectedValueException( 'Unexpected column: ' . $name ),
		};
	}

	/** @inheritDoc */
	protected function getFieldNames(): array {
		$fields = [
			'page' => $this->msg( 'campaignevents-worklist-table-article-column-header' )->text(),
			'wiki' => $this->msg( 'campaignevents-worklist-table-wiki-column-header' )->text(),
			'timestamp' => $this->msg( 'campaignevents-worklist-table-date-column-header' )->text(),
		];

		// Show the actions column only if the performer may remove articles. The column
		// intentionally has no header label.
		if ( $this->canRemoveArticles() ) {
			$fields['actions'] = '';
		}

		return $fields;
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
	 * Render the remove button. Under the MVP rule any named user may remove any article, so the
	 * button is shown on every row whenever the performer is eligible. It carries the wiki and the
	 * prefixed title, which is how the REST PATCH endpoint identifies the article.
	 */
	private function formatActions( stdClass $row ): string {
		if ( !$this->canRemoveArticles() ) {
			return '';
		}

		return $this->templateParser->processTemplate(
			'RemoveWorklistArticleButton',
			[
				'wiki' => $row->cewp_wiki,
				'title' => $row->cewp_page_prefixedtext,
				'tooltip' => $this->msg( 'campaignevents-worklist-table-remove-button-label' )->text(),
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

	/**
	 * Show the visible "Worklist" caption. Besides labelling the table, the caption is rendered
	 * inside the `.cdx-table__header` element, which the frontend appends the header controls
	 * (add-article dialog, worklist-page link) to.
	 */
	protected function shouldShowVisibleCaption(): bool {
		return true;
	}
}
