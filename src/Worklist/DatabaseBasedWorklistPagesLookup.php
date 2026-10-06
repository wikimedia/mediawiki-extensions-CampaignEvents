<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Worklist;

use MediaWiki\Context\DerivativeContext;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\CampaignEvents\Pager\WorklistPagesPagerFactory;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Request\FauxRequest;
use Wikimedia\Assert\Assert;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use Wikimedia\Timestamp\TimestampFormat as TS;

/**
 * Paginated lookup for worklist pages that reads directly from the database table.
 * This is more performant than reading from the wikipage, for every combination of pagination parameters.
 * However, the database may not be up-to-date with the wikipage, in which case this lookup may return stale data.
 *
 * Internally, this uses a pager class from the IndexPager hierarchy, converting inputs and outputs as needed, to avoid
 * reinventing the wheel. This code would ideally become cleaner some day, when the IndexPager hierarchy is refactored
 * to follow MVC (T384957).
 */
class DatabaseBasedWorklistPagesLookup implements IWorklistPagesLookup {
	private const PAGER_SORT_MAP = [
		self::SORT_TITLE => 'page',
		self::SORT_WIKI => 'wiki',
		self::SORT_TIMESTAMP => 'timestamp',
	];
	private const OFFSET_CONSTANT_MAP = [
		self::PAGINATION_PREV => 'prev',
		self::PAGINATION_NEXT => 'next',
		self::PAGINATION_FIRST => 'first',
		self::PAGINATION_LAST => 'last',
	];

	public function __construct(
		private readonly WorklistPagesPagerFactory $worklistPagesPagerFactory,
		private readonly LinkRenderer $linkRenderer,
		private readonly PageIdentity $worklistPage,
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function getWorklistPages(
		string $sort,
		int $limit,
		?string $offset,
		bool $direction,
		bool $isBackwards,
	): array {
		Assert::parameter( $limit > 0, '$limit', 'must be positive' );

		// Use a fake request to set properties with no setter
		$reqSortKey = $direction === self::DIR_ASCENDING ? 'asc' : 'desc';
		$fakeRequest = new FauxRequest( [
			'sort' => self::PAGER_SORT_MAP[$sort],
			$reqSortKey => 1,
			'dir' => $isBackwards ? 'prev' : '',
		] );
		$context = new DerivativeContext( RequestContext::getMain() );
		$context->setRequest( $fakeRequest );
		$pager = $this->worklistPagesPagerFactory->newPager(
			$context,
			$this->linkRenderer,
			$this->worklistPage,
		);
		$pager->setLimit( $limit );
		// Refresh the limit, as the pager has an upper bound of 5000.
		$limit = $pager->getLimit();
		$pager->setOffset( $offset ?? '' );

		$pager->doQuery();
		$pagerRes = iterator_to_array( $pager->getResult() );
		// Skip the extra row added at the end for pagination checks
		if ( count( $pagerRes ) > $limit ) {
			array_pop( $pagerRes );
		}
		if ( $isBackwards ) {
			$pagerRes = array_reverse( $pagerRes );
		}
		$res = [];
		foreach ( $pagerRes as $row ) {
			$res[] = [
				'wiki' => $row->cewp_wiki,
				'prefixedtext' => $row->cewp_page_prefixedtext,
				'timestamp' => (int)ConvertibleTimestamp::convert( TS::UNIX, $row->cewp_timestamp ),
			];
		}

		$pagerOffsets = $pager->getPagingQueries();
		$transformPagerOffset = static fn ( ?string $offset ): ?string => $offset !== '' ? $offset : null;

		$mappedOffsets = [];
		foreach ( self::OFFSET_CONSTANT_MAP as $const => $pagerKey ) {
			if ( $pagerOffsets[$pagerKey] !== false ) {
				$mappedOffsets[$const] = $transformPagerOffset( $pagerOffsets[$pagerKey]['offset'] );
			}
		}
		$mappedOffsets[self::PAGINATION_CUR] = $transformPagerOffset( $pager->getOffsetQuery() );

		return [ 'result' => $res, 'paginationOffsets' => $mappedOffsets ];
	}
}
