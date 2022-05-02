<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

use MalformedTitleException;
use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Page\PageStoreFactory;
use TitleFormatter;
use TitleParser;
use WikiMap;

class CampaignsPageFactory {
	public const SERVICE_NAME = 'CampaignEventsPageFactory';

	/** @var PageStoreFactory */
	private $pageStoreFactory;
	/** @var TitleParser */
	private $titleParser;
	/** @var TitleFormatter */
	private $titleFormatter;

	/**
	 * @param PageStoreFactory $pageStoreFactory
	 * @param TitleParser $titleParser
	 * @param TitleFormatter $titleFormatter
	 */
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
	 * @param int $namespace
	 * @param string $dbKey
	 * @param string $prefixedText
	 * @param string|false $wikiID
	 * @return ICampaignsPage
	 * @throws PageNotFoundException
	 */
	public function newExistingPage( int $namespace, string $dbKey, string $prefixedText, $wikiID ): ICampaignsPage {
		if ( $wikiID !== WikiAwareEntity::LOCAL ) {
			// Event pages stored in the database always have a string wiki ID, so we need to check if they're
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
		return new MWPageProxy( $page, $prefixedText );
	}

	/**
	 * @param string $titleStr
	 * @return ICampaignsPage
	 * @throws InvalidTitleStringException
	 * @throws UnexpectedInterwikiException If the page title has an interwiki prefix
	 * @throws PageNotFoundException
	 */
	public function newLocalExistingPageFromString( string $titleStr ): ICampaignsPage {
		try {
			$pageTitle = $this->titleParser->parseTitle( $titleStr );
		} catch ( MalformedTitleException $e ) {
			throw new InvalidTitleStringException( $titleStr, $e->getErrorMessage(), $e->getErrorMessageParameters() );
		}

		if ( $pageTitle->getInterwiki() !== '' ) {
			throw new UnexpectedInterwikiException( $pageTitle->getInterwiki() );
		}

		return $this->newExistingPage(
			$pageTitle->getNamespace(),
			$pageTitle->getDBkey(),
			$this->titleFormatter->getPrefixedText( $pageTitle ),
			WikiAwareEntity::LOCAL
		);
	}
}
