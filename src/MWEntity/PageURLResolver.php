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
		$wikiID = $page->getWikiId();
		return $wikiID === WikiAwareEntity::LOCAL
			? $this->titleFactory->castFromPageIdentity( $page->getPageIdentity() )->getFullURL()
			: WikiMap::getForeignURL( $wikiID, $page->getPrefixedText() );
	}
}
