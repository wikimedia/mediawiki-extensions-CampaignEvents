<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Hooks\Handlers;

use MediaWiki\Extension\CampaignEvents\EventPage\EventPageDecoratorFactory;
use MediaWiki\Page\Hook\ArticleViewHeaderHook;

class ArticleViewHeaderHandler implements ArticleViewHeaderHook {
	private EventPageDecoratorFactory $eventPageDecoratorFactory;

	/**
	 * @param EventPageDecoratorFactory $eventPageDecoratorFactory
	 */
	public function __construct( EventPageDecoratorFactory $eventPageDecoratorFactory ) {
		$this->eventPageDecoratorFactory = $eventPageDecoratorFactory;
	}

	/**
	 * @inheritDoc
	 */
	public function onArticleViewHeader( $article, &$outputDone, &$pcache ): void {
		$wikiPage = $article->getPage();
		if ( $wikiPage->getNamespace() !== NS_EVENT || !$wikiPage->exists() || $wikiPage->isRedirect() ) {
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
