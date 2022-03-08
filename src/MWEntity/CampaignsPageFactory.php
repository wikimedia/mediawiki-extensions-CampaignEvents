<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Page\PageStoreFactory;
use WikiMap;

class CampaignsPageFactory {
	public const SERVICE_NAME = 'CampaignEventsPageFactory';

	/** @var PageStoreFactory */
	private $pageStoreFactory;

	/**
	 * @param PageStoreFactory $pageStoreFactory
	 */
	public function __construct(
		PageStoreFactory $pageStoreFactory
	) {
		$this->pageStoreFactory = $pageStoreFactory;
	}

	/**
	 * @param int $namespace
	 * @param string $dbKey
	 * @param string|false $wikiID
	 * @return ICampaignsPage
	 * @throws PageNotFoundException
	 */
	public function newExistingPage( int $namespace, string $dbKey, $wikiID ): ICampaignsPage {
		if ( $wikiID !== WikiAwareEntity::LOCAL ) {
			// Event pages stored in the database always have a wiki ID, so we need to check if they're
			// actually local.
			$adjustedWikiID = WikiMap::isCurrentWikiId( $wikiID ) ? WikiAwareEntity::LOCAL : $wikiID;
		} else {
			$adjustedWikiID = $wikiID;
		}
		$pageStore = $this->pageStoreFactory->getPageStore( $adjustedWikiID );
		$page = $pageStore->getPageByName( $namespace, $dbKey );
		if ( !$page ) {
			throw new PageNotFoundException( $namespace, $dbKey, $wikiID );
		}
		return new MWPageProxy( $page );
	}
}
