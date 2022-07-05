<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

use MediaWiki\DAO\WikiAwareEntity;
use TitleFactory;
use UnexpectedValueException;
use WikiMap;

class PageURLResolver {
	public const SERVICE_NAME = 'CampaignEventsPageURLResolver';

	/** @var TitleFactory */
	private $titleFactory;

	/** @var string[] Cached URLs */
	private $cache = [];

	/**
	 * @param TitleFactory $titleFactory
	 */
	public function __construct( TitleFactory $titleFactory ) {
		$this->titleFactory = $titleFactory;
	}

	/**
	 * @param ICampaignsPage $page
	 * @return string
	 */
	public function getFullUrl( ICampaignsPage $page ): string {
		if ( !$page instanceof MWPageProxy ) {
			throw new UnexpectedValueException( 'Unknown campaigns page implementation: ' . get_class( $page ) );
		}
		$cacheKey = $this->getCacheKey( $page );
		if ( !isset( $this->cache[$cacheKey] ) ) {
			$wikiID = $page->getWikiId();
			$this->cache[$cacheKey] = $wikiID === WikiAwareEntity::LOCAL
				? $this->titleFactory->castFromPageIdentity( $page->getPageIdentity() )->getFullURL()
				: WikiMap::getForeignURL( $wikiID, $page->getPrefixedText() );
		}
		return $this->cache[$cacheKey];
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
