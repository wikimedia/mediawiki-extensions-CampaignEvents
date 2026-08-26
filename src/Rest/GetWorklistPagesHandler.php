<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Config\Config;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MediaWikiEventIngress\WorklistPageEventIngress;
use MediaWiki\Extension\CampaignEvents\Worklist\IWorklistArticlesLookup;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Linker\LinkRendererFactory;
use MediaWiki\Page\LinkBatchFactory;
use MediaWiki\Page\PageStoreFactory;
use MediaWiki\Page\ProperPageIdentity;
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
 * The whole list is returned in a single response rather than page by page: the Worklist tab
 * filters and paginates it client-side, which has to happen without a page load, so the client
 * needs the complete list up front.
 */
class GetWorklistPagesHandler extends SimpleHandler {
	use EventIDParamTrait;

	public function __construct(
		private readonly Config $config,
		private readonly IEventLookup $eventLookup,
		private readonly IWorklistArticlesLookup $worklistArticlesLookup,
		private readonly TitleFactory $titleFactory,
		private readonly LinkBatchFactory $linkBatchFactory,
		private readonly PageStoreFactory $pageStoreFactory,
		private readonly LinkRendererFactory $linkRendererFactory,
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
		$worklistPage = $this->getWorklistPage( $event );
		$pages = $worklistPage === null ? [] : $this->worklistArticlesLookup->getWorklistArticles(
			$worklistPage,
			// No limit or offset: the client needs every article, because it paginates them itself.
			0,
			0,
			IWorklistArticlesLookup::DESCENDING,
			IWorklistArticlesLookup::TIMESTAMP_SORT
		);
		$localTitles = $this->preloadLocalTitles( $pages );

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
				'title' => $prefixedText,
			] + $this->linkAttributes(
				$linkRenderer,
				$wiki,
				$prefixedText,
				$localTitles[$prefixedText] ?? null
			);
		}

		return $this->getResponseFactory()->createJson( $respVal );
	}

	/**
	 * The worklist page of an event, which holds the articles: a fixed subpage of the event page,
	 * and so on the same wiki as it, which is not necessarily this one.
	 *
	 * Null when no such page exists, which is the case until the first article is added: the
	 * worklist page is created by that edit.
	 */
	private function getWorklistPage( ExistingEventRegistration $event ): ?ProperPageIdentity {
		$eventPage = $event->getPage();
		return $this->pageStoreFactory->getPageStore( $eventPage->getWikiId() )
			->getPageByName(
				$eventPage->getNamespace(),
				$eventPage->getDBkey() . '/' . WorklistPageEventIngress::WORKLIST_SUBPAGE
			);
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
		string $wiki,
		string $prefixedText,
		?Title $localTitle
	): array {
		if ( WikiMap::isCurrentWikiId( $wiki ) ) {
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
	 * Titles for the rows that live on this wiki, keyed by prefixed text, preloaded in a single
	 * batch so that rendering their links does not run a query per row.
	 *
	 * A title the local wiki cannot parse is skipped rather than fatal: worklist content validates
	 * titles on write, so this should not happen, but one bad row must not fail the whole list.
	 *
	 * @param list<array{wiki: string, prefixedtext: string}> $pages
	 * @return array<string,Title>
	 */
	private function preloadLocalTitles( array $pages ): array {
		$titles = [];
		$linkBatch = $this->linkBatchFactory->newLinkBatch();
		$linkBatch->setCaller( __METHOD__ );
		foreach ( $pages as $page ) {
			$prefixedText = $page['prefixedtext'];
			if ( !WikiMap::isCurrentWikiId( $page['wiki'] ) ) {
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
