<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Config\Config;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\WikiLookup;
use MediaWiki\Extension\CampaignEvents\Worklist\IWorklistArticlesLookup;
use MediaWiki\Extension\CampaignEvents\Worklist\WorklistEventsStore;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Linker\LinkRendererFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\Page\LinkBatchFactory;
use MediaWiki\Parser\Sanitizer;
use MediaWiki\Rest\HttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWiki\WikiMap\WikiMap;

/**
 * GET endpoint listing the articles in an event's worklist.
 *
 * Gated on CampaignEventsEnableWorklistCardView, like the card view it feeds.
 *
 * Worklists are public (they are ordinary wiki pages), and so is the Worklist tab on
 * Special:EventDetails, so this endpoint performs no permission checks beyond requiring the event
 * to exist.
 *
 * The whole list is returned in one response rather than page by page: the card view searches and
 * paginates it client-side, which has to happen without a page load, so the client needs every
 * article up front.
 */
class GetWorklistPagesHandler extends SimpleHandler {
	use EventIDParamTrait;

	public function __construct(
		private readonly Config $config,
		private readonly IEventLookup $eventLookup,
		private readonly IWorklistArticlesLookup $worklistArticlesLookup,
		private readonly WikiLookup $wikiLookup,
		private readonly TitleFactory $titleFactory,
		private readonly LinkBatchFactory $linkBatchFactory,
		private readonly LinkRendererFactory $linkRendererFactory,
		private readonly WorklistEventsStore $worklistEventsStore,
	) {
	}

	protected function run( int $eventID ): Response {
		if ( !$this->config->get( 'CampaignEventsEnableWorklistCardView' ) ) {
			// The card view is what reads this, so the endpoint is gated with it: on a wiki where
			// the feature is off it should look as though it does not exist. Not localised: the
			// flag is temporary, and the endpoint is unreachable from the UI while it is off.
			throw new HttpException( 'The worklist card view is not enabled on this wiki', 404 );
		}

		$event = $this->getRegistrationOrThrow( $this->eventLookup, $eventID );

		// A worklist whose page has not been created yet holds no articles.
		$worklistPage = $this->worklistEventsStore->getWorklistPageForEvent( $event );
		$pages = $worklistPage->exists() ? $this->worklistArticlesLookup->getWorklistArticles(
			$worklistPage,
			// No limit or offset: the client needs every article, because it paginates them itself.
			0,
			0,
			IWorklistArticlesLookup::DESCENDING,
			IWorklistArticlesLookup::TIMESTAMP_SORT
		) : [];
		// Resolved once: a worklist can hold thousands of pages, and WikiMap works the current
		// wiki's ID out from its database domain on every call.
		$currentWiki = WikiMap::getCurrentWikiId();
		$localTitles = $this->preloadLocalTitles( $pages, $currentWiki );
		$wikis = array_values( array_unique( array_column( $pages, 'wiki' ) ) );
		$wikiNames = $this->wikiLookup->getLocalizedNames( $wikis );

		// URLs are expanded because a worklist can list articles from other wikis, and a relative
		// path would be ambiguous for those: on a farm whose wikis share a domain it would resolve
		// against the wrong one.
		$linkRenderer = $this->linkRendererFactory->create();
		$linkRenderer->setExpandURLs( PROTO_RELATIVE );

		$respVal = [];
		foreach ( $pages as $page ) {
			$wiki = $page['wiki'];
			$prefixedText = $page['prefixedtext'];
			$respVal[] = [
				'wiki' => $wiki,
				// Relative to the wiki answering this request, as the link attributes are. The
				// card view asks its own wiki, so that is the reader's; the articles themselves
				// come from the shared tables and do not depend on who answers.
				'is_local' => $wiki === $currentWiki,
				'title' => $prefixedText,
			] + $this->linkAttributes(
				$linkRenderer,
				$wiki === $currentWiki,
				$wiki,
				$prefixedText,
				$localTitles[$prefixedText] ?? null
			);
		}

		// Keyed by wiki ID rather than repeated on every page, to keep the response small: a
		// worklist of a few thousand pages spans a handful of wikis at most, so repeating each
		// wiki's data per page would be almost entirely duplication, and this response is what
		// the reader waits on.
		$wikiInfo = [];
		foreach ( $wikis as $wiki ) {
			$wikiInfo[$wiki] = [
				'name' => $wikiNames[$wiki],
				'api_url' => $this->getApiUrl( $wiki ),
			];
		}

		return $this->getResponseFactory()->createJson( [
			// Cast so that a worklist with no pages still yields an object, not an empty list.
			'wikis' => (object)$wikiInfo,
			'pages' => $respVal,
		] );
	}

	/**
	 * The link to one article, as the attributes the server-rendered list would have given it.
	 *
	 * Rendering the link here rather than handing the client a bare URL keeps the two lists
	 * consistent: the red-link class for a page still to be created, the `external` class for an
	 * article on another wiki, and anything the HtmlPageLinkRendererEnd hook adds, all come from
	 * the same place the worklist table gets them. See UserLinker::getUserPagePath, which does the
	 * same for user links.
	 *
	 * @return array{url: string, classes: string}
	 */
	private function linkAttributes(
		LinkRenderer $linkRenderer,
		bool $isLocal,
		string $wiki,
		string $prefixedText,
		?Title $localTitle
	): array {
		if ( $isLocal ) {
			if ( !$localTitle ) {
				// A title this wiki cannot parse has nothing to link to.
				return [ 'url' => '', 'classes' => '' ];
			}
			$html = $linkRenderer->makeLink( $localTitle, $prefixedText );
		} else {
			$url = WikiMap::getForeignURL( $wiki, $prefixedText );
			if ( $url === false ) {
				return [ 'url' => '', 'classes' => '' ];
			}
			// The response is not rendered on any one page, so there is no context title to give.
			// Special:Badtitle stands in for it, as Linker::makeExternalLink does; it only affects
			// the namespace exception for nofollow, which does not apply to the special page the
			// worklist is shown on.
			$html = $linkRenderer->makeExternalLink(
				$url,
				$prefixedText,
				SpecialPage::getTitleFor( 'Badtitle' )
			);
		}

		$attribs = Sanitizer::decodeTagAttributes( $html );
		return [
			'url' => $attribs['href'] ?? '',
			'classes' => $attribs['class'] ?? '',
		];
	}

	/**
	 * The api.php URL of the wiki an article belongs to, so the client can read data about the
	 * article from the wiki that holds it. Always absolute, because the reader is not necessarily on
	 * the wiki answering this request. Null when a foreign wiki cannot be resolved from $wgConf.
	 *
	 * The ScriptPath is resolved per wiki, falling back to the local one, in the same way as the
	 * rest.php URL in WorklistModule.
	 */
	private function getApiUrl( string $wiki ): ?string {
		if ( WikiMap::isCurrentWikiId( $wiki ) ) {
			// Resolved from local config, which works whether or not this wiki is part of a farm.
			return $this->config->get( MainConfigNames::CanonicalServer ) .
				$this->config->get( MainConfigNames::ScriptPath ) . '/api.php';
		}
		$foreignWiki = WikiMap::getWiki( $wiki );
		if ( !$foreignWiki ) {
			return null;
		}
		$scriptPath = $this->wikiLookup->getScriptPath( $wiki ) ??
			$this->config->get( MainConfigNames::ScriptPath );
		return $foreignWiki->getCanonicalServer() . $scriptPath . '/api.php';
	}

	/**
	 * Titles for the rows that live on this wiki, keyed by prefixed text, preloaded in a single
	 * batch so that rendering their links does not run a query per row.
	 *
	 * A title the local wiki cannot parse is skipped rather than fatal: worklist content validates
	 * titles on write, so this should not happen, but one bad row must not fail the whole list.
	 *
	 * @param list<array{wiki: string, prefixedtext: string}> $pages
	 * @return array<string,Title>
	 */
	private function preloadLocalTitles( array $pages, string $currentWiki ): array {
		$titles = [];
		$linkBatch = $this->linkBatchFactory->newLinkBatch();
		$linkBatch->setCaller( __METHOD__ );
		foreach ( $pages as $page ) {
			$prefixedText = $page['prefixedtext'];
			if ( $page['wiki'] !== $currentWiki ) {
				continue;
			}
			$title = $this->titleFactory->newFromText( $prefixedText );
			if ( $title ) {
				$titles[$prefixedText] = $title;
				$linkBatch->addObj( $title );
			}
		}
		$linkBatch->execute();
		return $titles;
	}

	/**
	 * @inheritDoc
	 */
	public function getParamSettings(): array {
		return $this->getIDParamSetting();
	}
}
