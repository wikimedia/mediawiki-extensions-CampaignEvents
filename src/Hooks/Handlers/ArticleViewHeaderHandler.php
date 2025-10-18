<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Hooks\Handlers;

use MediaWiki\Config\Config;
use MediaWiki\Extension\CampaignEvents\EventPage\EventPageDecoratorFactory;
use MediaWiki\Page\Hook\ArticleViewHeaderHook;

class ArticleViewHeaderHandler implements ArticleViewHeaderHook {
	public function __construct(
		private readonly EventPageDecoratorFactory $eventPageDecoratorFactory,
		private readonly Config $wikiConfig,
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function onArticleViewHeader( $article, &$outputDone, &$pcache ): void {
		$wikiPage = $article->getPage();
		if ( !$wikiPage->exists() || $wikiPage->isRedirect() ) {
			return;
		}

		// For performance, do not query the database if the namespace is no longer allowed. This will effectively
		// make event registration disappear from those pages. However, the alternative of making a DB query on
		// each and every page view is just not sustainable without clever caching strategies, see T392784 and
		// T392784#10773243 in particular. We can reconsider this later, if needed.
		if ( !in_array( $wikiPage->getNamespace(), $this->wikiConfig->get( 'CampaignEventsEventNamespaces' ), true ) ) {
			return;
		}

		$ctx = $article->getContext();
		$decorator = $this->eventPageDecoratorFactory->newDecorator(
			$ctx->getLanguage(),
			$ctx->getAuthority(),
			$ctx->getOutput()
		);
		$decorator->decoratePage( $wikiPage );
	}
}
