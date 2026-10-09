<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Worklist;

use LogicException;
use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\Utils;
use MediaWiki\Page\PageIdentity;
use stdClass;
use Wikimedia\Assert\Assert;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use Wikimedia\Timestamp\TimestampFormat;

/**
 * Paginated lookup for worklist pages that reads from the worklist wikipage, and merges in
 * ancillary data from the DB store.
 * This guarantees up-to-date results, but is potentially expensive in that it may need to read all the entries in
 * the database in order to apply sorting and offset/filtering. Therefore, it should be used sparingly and only when
 * fresh data is required.
 */
class WikiPageBasedWorklistPagesLookup implements IWorklistPagesLookup {
	/**
	 * Sort fields to use internally for each sort type. Fields must be valid property names in the hydrated results.
	 * The leftmost segment consisting only of primary data, if it exists, is used as a preliminary filter before
	 * hydration, with the rest of the sorting applied after hydration.
	 */
	private const INTERNAL_SORT_FIELDS = [
		self::SORT_TITLE => [ 'prefixedtext', 'wiki' ],
		self::SORT_WIKI => [ 'wiki', 'timestamp', 'id' ],
		self::SORT_TIMESTAMP => [ 'timestamp', 'id' ],
	];

	private const PRIMARY_SORT_FIELDS = [ 'wiki', 'prefixedtext', 'id' ];

	private const DB_QUERY_LIMIT = 2500;

	public function __construct(
		private readonly WorklistArticleHelper $worklistArticleHelper,
		private readonly WorklistSecondaryStore $worklistSecondaryStore,
		private readonly WorklistPagesSecondaryStore $worklistPagesSecondaryStore,
		private readonly CampaignsDatabaseHelper $databaseHelper,
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

		$rawContent = $this->worklistArticleHelper->getRawWorklistContentCached( $this->worklistPage );
		if ( !$rawContent ) {
			return [ 'result' => [], 'paginationOffsets' => [] ];
		}
		$wikiIDString = Utils::getWikiIDString( $this->worklistPage->getWikiId() );
		$pageID = $this->worklistPage->getId( $this->worklistPage->getWikiId() );
		$worklistID = $this->worklistSecondaryStore->getWorklistIDFromPage( $wikiIDString, $pageID );

		$sortFields = self::INTERNAL_SORT_FIELDS[$sort];

		$offset = $offset !== '' ? $offset : null;
		$offsetMap = $offset ? self::splitOffsetString( $sortFields, $offset ) : null;

		// Increase the limit so we can tell if there's going to be a next page in the result set.
		$effectiveLimit = $limit + 1;

		// Note that, when the primary sort order is ancillary data, we could in principle run the DB query first with
		// the appropriate sorting and offset, then intersect that with what's actually in the wikipage (repeating
		// the query if needed to account for leftover rows that haven't been cleaned up yet). However, we would need to
		// account for entries that don't yet have a corresponding DB row: sometimes they're at the beginning of the
		// result set, so we'd need to add those before the actual DB rows. The problem is, we can't tell what those
		// entries are without reading the entire DB table anyway. So, for simplicity, we always do the sorting in PHP,
		// after having queried the DB for all pages in the worklist.
		// @phan-suppress-next-next-line PhanTypeMismatchArgument https://github.com/phan/phan/issues/5577
		$result = $this->getResultsFromContent(
			$worklistID, $rawContent, $sortFields, $effectiveLimit, $offsetMap, $direction, $isBackwards, false
		);

		$extraItem = null;
		if ( count( $result ) > $limit ) {
			// If we did end up getting an extra row, strip it away
			$extraItem = $isBackwards ? array_shift( $result ) : array_pop( $result );
		}

		$paginationOffsets = $this->getPaginationOffsetsFromResult(
			$result,
			$extraItem,
			$worklistID,
			$rawContent,
			$sortFields,
			$effectiveLimit,
			$offset,
			$offsetMap,
			$direction,
			$isBackwards
		);

		return [ 'result' => self::cleanResult( $result ), 'paginationOffsets' => $paginationOffsets ];
	}

	/**
	 * Returns results by querying the wikipage directly, then adding ancillary data from the database.
	 *
	 * @param int|null $worklistID
	 * @param array<string,string[]> $rawContent
	 * @param non-empty-list<string> $sortFields
	 * @param positive-int $limit
	 * @param array<string,string>|null $offsetMap Mapping sort field to offset for comparison
	 * @param bool $direction
	 * @param bool $isBackwards
	 * @param bool $includeOffset
	 * @return array<string,array{id:int,wiki:string,prefixedtext:string,timestamp:int,placeholders:bool}>
	 */
	private function getResultsFromContent(
		?int $worklistID,
		array $rawContent,
		array $sortFields,
		int $limit,
		?array $offsetMap,
		bool $direction,
		bool $isBackwards,
		bool $includeOffset,
	): array {
		$primarySort = self::getPrimarySortFields( $sortFields );
		$needsSecondPass = $primarySort !== $sortFields;

		if ( $offsetMap ) {
			$primaryOffsetMap = array_intersect_key( $offsetMap, array_fill_keys( $primarySort, true ) );
		} else {
			$primaryOffsetMap = null;
		}

		$flatContent = self::flattenRawContent( $rawContent );
		$effectiveDirection = $isBackwards ? self::invertDirection( $direction ) : $direction;

		// First get a superset of the requested result page including ties, to be sliced later based on ancillary data
		// if needed. This can only use primary data fields.
		if ( $primarySort ) {
			$primarySortedResult = $this->getResultPageFromContentIncludingTies(
				$flatContent,
				$primarySort,
				$limit,
				$primaryOffsetMap,
				$effectiveDirection,
				$includeOffset,
				$needsSecondPass,
			);
		} else {
			$primarySortedResult = $flatContent;
		}

		$hydratedResult = $this->hydrateResultWithDBData( $worklistID, $primarySortedResult );

		// Do a second pass if needed, to sort out any ties from the first pass. This needs to repeat fields from the
		// first pass to make sure that filtering and sorting are always done on the entire tuple, and not a subset of
		// it: the comparison result would be different than that of the entire tuple. For example, if sorting by
		// (wiki,timestamp), we'd have (bwiki,5) > (awiki,20), but if we were to discard the wiki for the second pass,
		// we'd have ( 5 ) < ( 20 ) which puts items in the wrong order.
		if ( $needsSecondPass ) {
			$hydratedResult = $this->getResultPageFromContentIncludingTies(
				$hydratedResult, $sortFields, $limit, $offsetMap, $effectiveDirection, $includeOffset, false
			);
			// There should no longer be any ties at this point, but we don't bother verifying.
		} elseif ( count( $hydratedResult ) > $limit ) {
			// This should only happen if a sorting order has no ultimate tiebreaker
			throw new LogicException( 'Detected sort order with ties: ' . implode( ',', $sortFields ) );
		}

		$finalResult = $isBackwards ? array_reverse( $hydratedResult ) : $hydratedResult;
		return array_slice( $finalResult, 0, $limit );
	}

	/**
	 * Given a list of sort fields from {@link self::INTERNAL_SORT_FIELDS}, extract a leftmost segment of primary fields
	 * to sort and filter by before hydration.
	 *
	 * @param non-empty-list<string> $fields
	 * @return list<string>
	 */
	private static function getPrimarySortFields( array $fields ): array {
		$primarySegmentCount = 0;
		foreach ( $fields as $field ) {
			if ( !in_array( $field, self::PRIMARY_SORT_FIELDS, true ) ) {
				break;
			}
			$primarySegmentCount++;
		}

		return array_slice( $fields, 0, $primarySegmentCount );
	}

	/**
	 * Returns a page of results, sorted by the given sort fields, including all ties, such that the return value might
	 * have more results than requested if there are ties.
	 * This works with both hydrated and non-hydrated content, but it's up to the caller to make sure not to request
	 * sorting by ancillary data if the content isn't hydrated.
	 *
	 * @phpcs:ignore Generic.Files.LineLength
	 * @param array<string,array{id:int,wiki:string,prefixedtext:string,timestamp?:int,placeholders?:bool}> $flatContent
	 * @param non-empty-list<string> $sortFields
	 * @param positive-int $limit
	 * @param ?array<string,string> $offsetValueMap
	 * @param bool $direction
	 * @param bool $includeOffset
	 * @param bool $isPreliminaryPass
	 * @return array<string,array{id:int,wiki:string,prefixedtext:string,timestamp?:int,placeholders?:bool}>
	 */
	private function getResultPageFromContentIncludingTies(
		array $flatContent,
		array $sortFields,
		int $limit,
		?array $offsetValueMap,
		bool $direction,
		bool $includeOffset,
		bool $isPreliminaryPass,
	): array {
		// Always include the (partial) offset if we are going to perform a second pass
		$includeOffset = $includeOffset || $isPreliminaryPass;

		/**
		 * @param array<string,string|int> $x
		 * @param array<string,string|int> $y
		 */
		$compareOffsetMaps = static function ( array $x, array $y ) use ( $sortFields, $direction ): int {
			foreach ( $sortFields as $sortField ) {
				$xComponent = $x[$sortField];
				$yComponent = $y[$sortField];

				$baseCmp = strnatcmp( (string)$xComponent, (string)$yComponent );
				$comparisonRes = $direction === self::DIR_ASCENDING ? $baseCmp : -$baseCmp;
				if ( $comparisonRes !== 0 ) {
					return $comparisonRes;
				}
			}
			return 0;
		};
		/**
		 * @param array<string,string|int> $curValueMap
		 */
		$shouldIncludeValue = static function (
			array $curValueMap
		) use ( $offsetValueMap, $includeOffset, $compareOffsetMaps ): bool {
			if ( $offsetValueMap === null ) {
				return true;
			}
			$compareRes = $compareOffsetMaps( $curValueMap, $offsetValueMap );
			return $includeOffset ? $compareRes >= 0 : $compareRes > 0;
		};

		// Group results by offset field value, so we can keep all tied results, while still limiting the result set as
		// much as possible (by including as many groups as needed to be >= the requested limit).
		$resultsByOffsetValue = [];
		foreach ( $flatContent as $key => $item ) {
			$curOffsetValue = self::buildOffsetString( $sortFields, $item, false );
			if ( !$shouldIncludeValue( self::splitKnownValidOffsetString( $sortFields, $curOffsetValue ) ) ) {
				continue;
			}
			$resultsByOffsetValue[$curOffsetValue] ??= [];
			$resultsByOffsetValue[$curOffsetValue][$key] = $item;
		}

		uksort(
			$resultsByOffsetValue,
			static function ( mixed $x, mixed $y ) use ( $sortFields, $compareOffsetMaps ): int {
				$xComponents = self::splitKnownValidOffsetString( $sortFields, $x );
				$yComponents = self::splitKnownValidOffsetString( $sortFields, $y );
				return $compareOffsetMaps( $xComponents, $yComponents );
			},
		);

		// Flatten the result and apply the requested limit. If this is the preliminary pass (meaning we'll sort and
		// filter again after hydration), we apply a higher limit than requested because the second pass might discard
		// results from this pass. In particular, we check whether the first group is an exact match with the (partial)
		// offset and add it in its entirety without counting it towards the limit, then add as many other (whole)
		// groups as needed until we get above the requested limit (the worst-case scenario being that the entire first
		// group gets discarded by the second pass).
		$res = [];
		$remainingCount = $limit;
		foreach ( $resultsByOffsetValue as $offsetValue => $items ) {
			$res = array_merge( $res, $items );
			if (
				!$isPreliminaryPass ||
				$offsetValueMap === null ||
				self::splitOffsetString( $sortFields, $offsetValue ) !== $offsetValueMap
			) {
				$remainingCount -= count( $items );
			}
			if ( $remainingCount <= 0 ) {
				break;
			}
		}

		return $res;
	}

	/**
	 * Given a flat list from the wikipage, hydrate it with DB data.
	 *
	 * @param int|null $worklistID
	 * @param array<string,array{id:int,wiki:string,prefixedtext:string}> $rawResult
	 * @return array<string,array{id:int,wiki:string,prefixedtext:string,timestamp:int,placeholders:bool}>
	 */
	private function hydrateResultWithDBData( ?int $worklistID, array $rawResult ): array {
		if ( $worklistID === null ) {
			// This should typically not be null, as the deferred update that creates the worklist entity can be
			// expected to run immediately. Still, it might in principle be possible for it not to run, or to error out,
			// or the read here might be from a lagged replica. So, in that case, return placeholders only.
			/**
			 * @param array{id:int,wiki:string,prefixedtext:string} $item
			 * @return array{id:int,wiki:string,prefixedtext:string,timestamp:int,placeholders:bool}
			 */
			$placeholderFiller = static fn ( array $item ): array => self::hydrateResultItem( $item, null );
			return array_map( $placeholderFiller, $rawResult );
		}
		$queryInfo = $this->worklistPagesSecondaryStore->getQueryInfo( $worklistID );
		$dbr = $this->databaseHelper->getReplicaConnection();
		$dbConcat = $dbr->buildConcat( [ 'cewp_wiki', $dbr->addQuotes( '|' ), 'cewp_page_prefixedtext' ] );
		$concatAlias = 'page_wiki_prefixedtext';
		$makePageKey = static fn ( string $wiki, string $prefixedText ): string => $wiki . '|' . $prefixedText;

		$result = [];
		foreach ( array_chunk( $rawResult, self::DB_QUERY_LIMIT, true ) as $pageBatch ) {
			$res = $dbr->newSelectQueryBuilder()
				->queryInfo( $queryInfo )
				->fields( [ $concatAlias => $dbConcat ] )
				->where( [
					$dbConcat => array_keys( $pageBatch ),
				] )
				->caller( __METHOD__ )
				->fetchResultSet();
			$rowsByPageKey = [];
			foreach ( $res as $row ) {
				$pageKey = $makePageKey( $row->cewp_wiki, $row->cewp_page_prefixedtext );
				$rowsByPageKey[$pageKey] = $row;
			}

			foreach ( $pageBatch as $pageKey => $pageItem ) {
				$result[$pageKey] = self::hydrateResultItem( $pageItem, $rowsByPageKey[$pageKey] ?? null );
			}
		}

		return $result;
	}

	/**
	 * @phpcs:disable Generic.Files.LineLength
	 * @param array<string,array{id:int,wiki:string,prefixedtext:string,timestamp:int,placeholders:bool}> $result
	 * @param array{id:int,wiki:string,prefixedtext:string,timestamp:int,placeholders:bool}|null $extraItem
	 * @param int|null $worklistID
	 * @param array<string,string[]> $rawContent
	 * @param non-empty-list<string> $sortFields
	 * @param int $effectiveLimit
	 * @param string|null $offset
	 * @param array<string,string>|null $offsetMap
	 * @param bool $direction
	 * @param bool $isBackwards
	 * @return array{prev?:string|null,next?:string|null,cur:string|null,first?:string|null,last?:string|null}
	 * @phpcs:enable Generic.Files.LineLength
	 */
	private function getPaginationOffsetsFromResult(
		array $result,
		?array $extraItem,
		?int $worklistID,
		array $rawContent,
		array $sortFields,
		int $effectiveLimit,
		?string $offset,
		?array $offsetMap,
		bool $direction,
		bool $isBackwards,
	): array {
		if ( $offsetMap === null ) {
			$isExtremePage = true;
		} else {
			// Look for the next item in the opposite direction, including the offset, to see if we have more results.
			$oppositeDir = self::invertDirection( $direction );
			// TODO: As a future optimization, we should be able to determine what this element is without re-running a
			// full lookup from scratch:
			// - When applying filtering by offset, keep track of the discarded items, looking for either
			//     a) the largest value less than the offset, when in ascending order, or
			//     b) the smallest value greater than the offset, when in descending order
			// - Note that there might be multiple such tied values, if the sort type has a secondary component to be
			//   done after hydration.
			// - Hydrate those values as well
			// - Apply the remaining sort fields to choose a winner value (largest or smallest of them, in ascending or
			//    descending order respectively).
			// - Pass that value in here.
			$oppositeResult = $this->getResultsFromContent(
				$worklistID, $rawContent, $sortFields, 1, $offsetMap, $oppositeDir, $isBackwards, true
			);
			$isExtremePage = $oppositeResult === [];
		}

		$resultCount = count( $result );
		if ( $extraItem ) {
			$resultCount++;
		}

		if ( $resultCount ) {
			$firstItemShown = array_first( $result );
			$lastItemShown = array_last( $result );
		} else {
			$firstItemShown = $lastItemShown = null;
		}

		if ( $isBackwards === self::BACKWARDS ) {
			$isFirst = $resultCount < $effectiveLimit;
			$isLast = $isExtremePage;
		} else {
			$isFirst = $isExtremePage;
			$isLast = $resultCount < $effectiveLimit;
		}

		// Note we avoid exposing placeholders in offsets. Is this always the best approach?
		/**
		 * @param array{id:int,wiki:string,prefixedtext:string,timestamp:int,placeholders:bool} $item
		 */
		$makeOffset = static fn ( array $item ): string => self::buildOffsetString( $sortFields, $item, true );

		$paginationOffsets = [];
		if ( $isBackwards ) {
			$paginationOffsets[self::PAGINATION_CUR] = $extraItem ? $makeOffset( $extraItem ) : null;
		} else {
			$paginationOffsets[self::PAGINATION_CUR] = $offset;
		}
		if ( !$isFirst ) {
			$paginationOffsets[self::PAGINATION_PREV] = $firstItemShown ? $makeOffset( $firstItemShown ) : null;
			$paginationOffsets[self::PAGINATION_FIRST] = null;
		}
		if ( !$isLast ) {
			$paginationOffsets[self::PAGINATION_NEXT] = $lastItemShown ? $makeOffset( $lastItemShown ) : null;
			$paginationOffsets[self::PAGINATION_LAST] = null;
		}

		return $paginationOffsets;
	}

	/**
	 * Builds an offset string that can be fed back to this class to paginate through results.
	 *
	 * @param string[] $sortFields
	 * @param array{id:int,wiki:string,prefixedtext:string,timestamp:int,placeholders:bool} $offsetItem
	 * @param bool $dropPlaceholders
	 */
	private static function buildOffsetString( array $sortFields, array $offsetItem, bool $dropPlaceholders ): string {
		if ( $dropPlaceholders ) {
			$offsetItem = self::dropPlaceholders( $offsetItem );
		}

		$offsetComponents = [];
		foreach ( $sortFields as $field ) {
			$offsetComponents[] = $offsetItem[$field];
		}
		return implode( '|', $offsetComponents );
	}

	/**
	 * Given a list of sort fields and an offset for those fields which may potentially be invalid (e.g. user-supplied),
	 * split the offset into a map of values for each sort field.
	 *
	 * @param string[] $sortFields
	 * @param string $offset
	 * @return array<string,string|int>|null Null when the offset is invalid.
	 */
	private static function splitOffsetString( array $sortFields, string $offset ): ?array {
		$offsetParts = explode( '|', $offset );
		if ( count( $sortFields ) !== count( $offsetParts ) ) {
			return null;
		}

		$offsetValues = [];
		foreach ( $sortFields as $i => $field ) {
			// Empty values are allowed for placeholder only. Otherwise the offset is treated as invalid.
			if ( $offsetParts[$i] === '' ) {
				$placeholders = self::getPlaceholderValues();
				if ( !array_key_exists( $field, $placeholders ) ) {
					return null;
				}
				$offsetValues[$field] = $placeholders[$field];
			} else {
				$offsetValues[$field] = $offsetParts[$i];
			}
		}
		return $offsetValues;
	}

	/**
	 * Given a list of sort fields and an offset for those fields which is known to be valid, split the offset into a
	 * map of values for each sort field.
	 *
	 * @param string[] $sortFields
	 * @param string $offset
	 * @return array<string,string|int>
	 */
	private static function splitKnownValidOffsetString( array $sortFields, string $offset ): array {
		$tolerantRes = self::splitOffsetString( $sortFields, $offset );
		if ( $tolerantRes === null ) {
			throw new LogicException( "Invalid offset $offset for fields " . implode( ',', $sortFields ) );
		}
		return $tolerantRes;
	}

	/**
	 * Flattens the content into a map from unique page identifier to page data, adding a numeric identifier based
	 * on the ordering of the items themselves (similary to an AUTOINCREMENT DB column).
	 *
	 * @param array<string,string[]> $rawContent
	 * @return array<string,array{id:int,wiki:string,prefixedtext:string}>
	 */
	private static function flattenRawContent( array $rawContent ): array {
		$ret = [];
		$i = 1;
		foreach ( $rawContent as $wiki => $pages ) {
			foreach ( $pages as $page ) {
				$pageKey = $wiki . '|' . $page;
				$ret[$pageKey] = [
					'id' => $i++,
					'wiki' => $wiki,
					'prefixedtext' => $page,
				];
			}
		}
		return $ret;
	}

	private static function invertDirection( bool $direction ): bool {
		return $direction === self::DIR_ASCENDING ? self::DIR_DESCENDING : self::DIR_ASCENDING;
	}

	/**
	 * Returns a map of placeholder values to use when a database row is not yet available, as well as for offsets
	 * in pagination. The values chosen depend on the wanted semantics for each field when sorting by that field.
	 * These are for internal use by this class and are stripped away before returning a result set outside.
	 *
	 * @return array<string,string|int>
	 */
	private static function getPlaceholderValues(): array {
		static $placeholders;
		if ( !$placeholders ) {
			$placeholders = [
				// Rows without a timestamp are recently-inserted, so use the current time as placeholder
				'timestamp' => (int)ConvertibleTimestamp::now( TimestampFormat::UNIX ),
			];
		}
		return $placeholders;
	}

	/**
	 * Creates an item for the return value, merging information from the wikipage and the database.
	 * It also adds a flag to indicate whether placeholders were used and should be stripped later.
	 *
	 * @param array{id:int,wiki:string,prefixedtext:string} $item
	 * @param stdClass|null $dbRow From ce_worklist_pages, if present
	 * @return array{id:int,wiki:string,prefixedtext:string,timestamp:int,placeholders:bool}
	 */
	private static function hydrateResultItem( array $item, ?stdClass $dbRow ): array {
		if ( $dbRow ) {
			$ancillaryData = [
				'timestamp' => (int)ConvertibleTimestamp::convert( TimestampFormat::UNIX, $dbRow->cewp_timestamp ),
				'placeholders' => false,
			];
		} else {
			$ancillaryData = [
				...self::getPlaceholderValues(),
				'placeholders' => true,
			];
		}

		return array_merge( $item, $ancillaryData );
	}

	/**
	 * Cleans a result page from internal data so it can be returned outside this class.
	 *
	 * @phpcs:ignore Generic.Files.LineLength
	 * @param array<string,array{id:int,wiki:string,prefixedtext:string,timestamp:int,placeholders:bool}> $rawResult
	 * @return list<array{wiki:string,prefixedtext:string,timestamp:int|null}>
	 */
	private static function cleanResult( array $rawResult ): array {
		$result = [];
		foreach ( $rawResult as $item ) {
			$itemsWithoutPlaceholders = self::dropPlaceholders( $item );
			$result[] = [
				'wiki' => $itemsWithoutPlaceholders['wiki'],
				'prefixedtext' => $itemsWithoutPlaceholders['prefixedtext'],
				'timestamp' => $itemsWithoutPlaceholders['timestamp'],
			];
		}
		return $result;
	}

	/**
	 * Drops placeholder values from an item, without further modifications.
	 *
	 * @param array{id:int,wiki:string,prefixedtext:string,timestamp:int,placeholders:bool} $item
	 * @return array{id:int,wiki:string,prefixedtext:string,timestamp:int|null,placeholders:bool}
	 */
	private static function dropPlaceholders( array $item ): array {
		if ( $item['placeholders'] ) {
			$item['timestamp'] = null;
		}
		return $item;
	}
}
