<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Title\TitleFactory;
use MediaWiki\WikiMap\WikiMap;
use UnexpectedValueException;

class PageURLResolver {
	public const SERVICE_NAME = 'CampaignEventsPageURLResolver';

	private TitleFactory $titleFactory;

	/** @var string[] Cached results of getUrl() */
	private array $urlCache = [];
	/** @var string[] Cached results of getFullUrl() */
	private array $fullUrlCache = [];

	/**
	 * @param TitleFactory $titleFactory
	 */
	public function __construct( TitleFactory $titleFactory ) {
		$this->titleFactory = $titleFactory;
	}

	/**
	 * Returns the URL of a page. This could be a local URL (for local pages) or a full URL (for
	 * foreign wiki pages).
	 * @param ICampaignsPage $page
	 * @return string
	 */
	public function getUrl( ICampaignsPage $page ): string {
		if ( !$page instanceof MWPageProxy ) {
			throw new UnexpectedValueException( 'Unknown campaigns page implementation: ' . get_class( $page ) );
		}
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
	 * @param ICampaignsPage $page
	 * @return string
	 */
	public function getFullUrl( ICampaignsPage $page ): string {
		if ( !$page instanceof MWPageProxy ) {
			throw new UnexpectedValueException( 'Unknown campaigns page implementation: ' . get_class( $page ) );
		}
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
	 * @param ICampaignsPage $page
	 * @return string
	 */
	private function getCacheKey( ICampaignsPage $page ): string {
		// No need to actually convert it to the wiki ID if it's local.
		$wikiIDStr = $page->getWikiId() === WikiAwareEntity::LOCAL ? '' : $page->getWikiId();
		return $wikiIDStr . '|' . $page->getPrefixedText();
	}
}
