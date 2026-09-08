<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Worklist;

use MediaWiki\Page\PageIdentity;

interface IWorklistArticlesLookup {

	public const string TIMESTAMP_SORT = 'timestamp';
	public const string PAGE_SORT = 'page';
	public const string WIKI_SORT = 'wiki';

	public const string ASCENDING = 'asc';
	public const string DESCENDING = 'desc';

	/**
	 * Returns the articles listed in the given worklist.
	 *
	 * @param PageIdentity $page The worklist page
	 * @param int $limit Articles to return at most, or 0 for all of them
	 * @param int $offset Articles to skip
	 * @param string $direction self::ASCENDING or self::DESCENDING
	 * @param string $sort One of self::TIMESTAMP_SORT, self::PAGE_SORT or self::WIKI_SORT
	 * @return list<array{wiki: string, prefixedtext: string}> Articles as wiki ID and prefixed title
	 */
	public function getWorklistArticles(
		PageIdentity $page,
		int $limit,
		int $offset,
		string $direction,
		string $sort
	): array;
}
