<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

use MalformedTitleException;
use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Interwiki\InterwikiLookup;
use MediaWiki\Page\PageStoreFactory;
use TitleParser;
use WikiMap;

class CampaignsPageFactory {
	public const SERVICE_NAME = 'CampaignEventsPageFactory';

	/** @var PageStoreFactory */
	private $pageStoreFactory;
	/** @var TitleParser */
	private $titleParser;
	/** @var InterwikiLookup */
	private $interwikiLookup;

	/**
	 * @param PageStoreFactory $pageStoreFactory
	 * @param TitleParser $titleParser
	 * @param InterwikiLookup $interwikiLookup
	 */
	public function __construct(
		PageStoreFactory $pageStoreFactory,
		TitleParser $titleParser,
		InterwikiLookup $interwikiLookup
	) {
		$this->pageStoreFactory = $pageStoreFactory;
		$this->titleParser = $titleParser;
		$this->interwikiLookup = $interwikiLookup;
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

	/**
	 * @param string $titleStr
	 * @return ICampaignsPage
	 * @throws InvalidTitleStringException
	 * @throws PageNotFoundException
	 */
	public function newExistingPageFromString( string $titleStr ): ICampaignsPage {
		try {
			$pageTitle = $this->titleParser->parseTitle( $titleStr );
		} catch ( MalformedTitleException $e ) {
			throw new InvalidTitleStringException( $titleStr, $e->getErrorMessage(), $e->getErrorMessageParameters() );
		}

		if ( $pageTitle->getInterwiki() !== '' ) {
			$interwiki = $this->interwikiLookup->fetch( $pageTitle->getInterwiki() );
			if ( !$interwiki ) {
				throw new InvalidInterwikiException( $pageTitle->getInterwiki() );
			}
			$wikiID = $interwiki->getWikiID();
		} else {
			$wikiID = WikiAwareEntity::LOCAL;
		}

		return $this->newExistingPage( $pageTitle->getNamespace(), $pageTitle->getDBkey(), $wikiID );
	}
}
