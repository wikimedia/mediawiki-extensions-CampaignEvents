<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Invitation;

use InvalidArgumentException;
use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Page\PageStoreFactory;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\Assert\Assert;

class WorklistParser {
	public const SERVICE_NAME = 'CampaignEventsWorklistParser';

	public const ARTICLES_LIMIT = 300;

	private PageStoreFactory $pageStoreFactory;

	public function __construct(
		PageStoreFactory $pageStoreFactory
	) {
		$this->pageStoreFactory = $pageStoreFactory;
	}

	/**
	 * Converts a list of raw page titles into a Worklist object, validating them in the process.
	 *
	 * @param array<string,string[]> $pageNamesByWiki $pageNamesByWiki Wiki IDs should always be string, and not use
	 * WikiAwareEntity::LOCAL to avoid fun autocasting issues where PHP turns `false` into `0` when used as array key.
	 * @return Worklist
	 * @todo Change exceptions to user-facing errors.
	 */
	public function parseWorklist( array $pageNamesByWiki ): Worklist {
		Assert::parameterKeyType( 'string', $pageNamesByWiki, '$pageNamesByWiki' );
		$totalPageCount = array_sum( array_map( 'count', $pageNamesByWiki ) );
		if ( $totalPageCount > self::ARTICLES_LIMIT ) {
			throw new InvalidArgumentException( "The worklist has more than " . self::ARTICLES_LIMIT . ' articles' );
		}

		$curWikiID = WikiMap::getCurrentWikiId();
		$pagesByWiki = [];
		foreach ( $pageNamesByWiki as $wikiID => $pageNames ) {
			$pageStore = $this->pageStoreFactory->getPageStore(
				$wikiID !== $curWikiID ? $wikiID : WikiAwareEntity::LOCAL
			);
			foreach ( $pageNames as $pageName ) {
				// Note: If $pageName happens to contain a namespace identifier, or really anything that cannot be
				// parsed in the context of the current wiki, this method won't behave correctly due to T353916. There
				// doesn't seem to be much that we can do about it.
				// FIXME: Batching!
				$page = $pageStore->getPageByText( $pageName );
				if ( !$page ) {
					throw new InvalidArgumentException( "Invalid title: $pageName" );
				} elseif ( !$page->exists() ) {
					throw new InvalidArgumentException( "Page does not exist: $pageName" );
				} elseif ( $page->getNamespace() !== NS_MAIN ) {
					throw new InvalidArgumentException( "Page is not in the mainspace: $pageName" );
				}
				$pagesByWiki[$wikiID] ??= [];
				$pagesByWiki[$wikiID][] = $page;
			}
		}

		if ( !$pagesByWiki ) {
			throw new InvalidArgumentException( "Empty list of articles" );
		}

		return new Worklist( $pagesByWiki );
	}
}
