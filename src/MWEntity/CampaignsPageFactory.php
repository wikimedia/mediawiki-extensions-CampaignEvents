<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

use MediaWiki\Page\PageStoreFactory;

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
	 * @param string $wikiID
	 * @return ICampaignsPage
	 */
	public function newExistingPage( int $namespace, string $dbKey, string $wikiID ): ICampaignsPage {
		$pageStore = $this->pageStoreFactory->getPageStore( $wikiID );
		$page = $pageStore->getPageByName( $namespace, $dbKey );
		if ( !$page ) {
			throw new PageNotFoundException( $namespace, $dbKey, $wikiID );
		}
		return new MWPageProxy( $page );
	}
}
