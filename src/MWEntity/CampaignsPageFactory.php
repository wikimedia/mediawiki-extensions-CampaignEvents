<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Page\PageIdentityValue;
use MediaWiki\Page\PageStoreFactory;
use MediaWiki\Title\MalformedTitleException;
use MediaWiki\Title\TitleFormatter;
use MediaWiki\Title\TitleParser;
use MediaWiki\WikiMap\WikiMap;

class CampaignsPageFactory {
	public const SERVICE_NAME = 'CampaignEventsPageFactory';

	private PageStoreFactory $pageStoreFactory;
	private TitleParser $titleParser;
	private TitleFormatter $titleFormatter;

	public function __construct(
		PageStoreFactory $pageStoreFactory,
		TitleParser $titleParser,
		TitleFormatter $titleFormatter
	) {
		$this->pageStoreFactory = $pageStoreFactory;
		$this->titleParser = $titleParser;
		$this->titleFormatter = $titleFormatter;
	}

	/**
	 * Creates a page object from a DB record. This does NOT require that the page exists.
	 *
	 * @param int $namespace
	 * @param string $dbKey
	 * @param string $prefixedText
	 * @param string|false $wikiID
	 * @return MWPageProxy
	 */
	public function newPageFromDB( int $namespace, string $dbKey, string $prefixedText, $wikiID ): MWPageProxy {
		// Event pages stored in the database always have a string wiki ID, so we need to check if they're
		// actually local.
		$adjustedWikiID = WikiMap::isCurrentWikiId( $wikiID ) ? WikiAwareEntity::LOCAL : $wikiID;
		$pageStore = $this->pageStoreFactory->getPageStore( $adjustedWikiID );
		$page = $pageStore->getPageByName( $namespace, $dbKey );
		if ( !$page ) {
			// The page does not exist; this can happen e.g. if the event page was deleted.
			$page = new PageIdentityValue( 0, $namespace, $dbKey, $adjustedWikiID );
		}
		return new MWPageProxy( $page, $prefixedText );
	}

	/**
	 * @param string $titleStr
	 * @return MWPageProxy
	 * @throws InvalidTitleStringException
	 * @throws UnexpectedInterwikiException If the page title has an interwiki prefix
	 * @throws UnexpectedVirtualNamespaceException
	 * @throws UnexpectedSectionAnchorException
	 * @throws PageNotFoundException If the page does not exist
	 */
	public function newLocalExistingPageFromString( string $titleStr ): MWPageProxy {
		// This is similar to PageStore::getPageByText, but with better error handling
		// and it also requires that the page exists.
		try {
			$pageTitle = $this->titleParser->parseTitle( $titleStr );
		} catch ( MalformedTitleException $e ) {
			throw new InvalidTitleStringException( $titleStr, $e->getErrorMessage(), $e->getErrorMessageParameters() );
		}

		if ( $pageTitle->isExternal() ) {
			throw new UnexpectedInterwikiException( $pageTitle->getInterwiki() );
		}

		$namespace = $pageTitle->getNamespace();
		if ( $namespace < 0 ) {
			throw new UnexpectedVirtualNamespaceException( $namespace );
		}

		if ( $pageTitle->hasFragment() ) {
			throw new UnexpectedSectionAnchorException( $pageTitle->getFragment() );
		}

		$dbKey = $pageTitle->getDBkey();
		$page = $this->pageStoreFactory->getPageStore()->getPageByName( $namespace, $dbKey );
		if ( !$page ) {
			throw new PageNotFoundException( $namespace, $dbKey, WikiAwareEntity::LOCAL );
		}
		return new MWPageProxy( $page, $this->titleFormatter->getPrefixedText( $pageTitle ) );
	}

	/**
	 * Convert a MW page interface (LinkTarget or ProperPageIdentity) into an MWPageProxy, without
	 * further checks (e.g. existence).
	 *
	 * @param PageIdentity|LinkTarget $page Must be a page in the local wiki
	 * @return MWPageProxy
	 */
	public function newFromLocalMediaWikiPage( $page ): MWPageProxy {
		$page->assertWiki( WikiAwareEntity::LOCAL );
		if ( $page instanceof LinkTarget ) {
			$page = $this->pageStoreFactory->getPageStore()->getPageForLink( $page );
		}
		return new MWPageProxy( $page, $this->titleFormatter->getPrefixedText( $page ) );
	}
}
