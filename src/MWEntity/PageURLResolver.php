<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWiki\WikiMap\WikiMap;
use RuntimeException;

class PageURLResolver {
	public const SERVICE_NAME = 'CampaignEventsPageURLResolver';

	/** @var string[] Cached results of getUrl() */
	private array $urlCache = [];
	/** @var string[] Cached results of getFullUrl() */
	private array $fullUrlCache = [];
	/** @var string[] Cached results of getCanonicalUrl() */
	private array $canonicalUrlCache = [];

	public function __construct(
		private readonly TitleFactory $titleFactory,
	) {
	}

	/**
	 * Returns the URL of a page. This could be a local URL (for local pages) or a full URL (for
	 * foreign wiki pages).
	 */
	public function getUrl( MWPageProxy $page ): string {
		$cacheKey = $this->getCacheKey( $page );
		if ( !isset( $this->urlCache[$cacheKey] ) ) {
			$wikiID = $page->getWikiId();
			$this->urlCache[$cacheKey] = $wikiID === WikiAwareEntity::LOCAL
				? $this->titleFactory->castFromPageIdentity( $page->getPageIdentity() )->getLocalURL()
				: WikiMap::getForeignURL( $wikiID, $page->getPrefixedText() );
		}
		return $this->urlCache[$cacheKey];
	}

	/**
	 * Returns the full URL of a page. Unlike getUrl, this is guaranteed to be the full URL even for local pages.
	 */
	public function getFullUrl( MWPageProxy $page ): string {
		$cacheKey = $this->getCacheKey( $page );
		if ( !isset( $this->fullUrlCache[$cacheKey] ) ) {
			$wikiID = $page->getWikiId();
			$this->fullUrlCache[$cacheKey] = $wikiID === WikiAwareEntity::LOCAL
				? $this->titleFactory->castFromPageIdentity( $page->getPageIdentity() )->getFullURL()
				: WikiMap::getForeignURL( $wikiID, $page->getPrefixedText() );
		}
		return $this->fullUrlCache[$cacheKey];
	}

	/**
	 * Returns the canonical URL of a page. This should be used for things like email notifications.
	 * @see Title::getCanonicalURL()
	 */
	public function getCanonicalUrl( MWPageProxy $page ): string {
		$cacheKey = $this->getCacheKey( $page );
		if ( !isset( $this->canonicalUrlCache[$cacheKey] ) ) {
			$wikiID = $page->getWikiId();
			if ( $wikiID === WikiAwareEntity::LOCAL ) {
				$this->canonicalUrlCache[$cacheKey] = $this->titleFactory
					->castFromPageIdentity( $page->getPageIdentity() )
					->getCanonicalURL();
			} else {
				$wiki = WikiMap::getWiki( $wikiID );
				if ( !$wiki ) {
					throw new RuntimeException( "Cannot obtain reference to wiki $wikiID" );
				}
				$this->canonicalUrlCache[$cacheKey] = $wiki->getCanonicalUrl( $page->getPrefixedText() );
			}
		}
		return $this->canonicalUrlCache[$cacheKey];
	}

	private function getCacheKey( MWPageProxy $page ): string {
		// No need to actually convert it to the wiki ID if it's local.
		$wikiIDStr = $page->getWikiId() === WikiAwareEntity::LOCAL ? '' : $page->getWikiId();
		return $wikiIDStr . '|' . $page->getPrefixedText();
	}
}
