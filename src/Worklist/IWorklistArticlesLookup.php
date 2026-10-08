<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Worklist;

use MediaWiki\Page\PageIdentity;

interface IWorklistArticlesLookup {
	/**
	 * Returns which of the given articles, all on one wiki, are in the given worklist.
	 *
	 * @param PageIdentity $page The worklist page
	 * @param string $wiki Wiki ID the articles belong to
	 * @param list<string> $prefixedTexts
	 * @return list<string> Those of $prefixedTexts that are in the worklist, in no particular order
	 */
	public function filterWorklistArticles( PageIdentity $page, string $wiki, array $prefixedTexts ): array;
}
