<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Hooks\Handlers;

use MediaWiki\Extension\CampaignEvents\EventPage\EventPageDecorator;
use MediaWiki\Page\Hook\ArticleViewHeaderHook;

class ArticleViewHeaderHandler implements ArticleViewHeaderHook {
	/** @var EventPageDecorator */
	private $eventPageDecorator;

	/**
	 * @param EventPageDecorator $eventPageDecorator
	 */
	public function __construct( EventPageDecorator $eventPageDecorator ) {
		$this->eventPageDecorator = $eventPageDecorator;
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
		$this->eventPageDecorator->decoratePage(
			$ctx->getOutput(),
			$wikiPage,
			$ctx->getLanguage(),
			$ctx->getUser(),
			$ctx->getAuthority()
		);
	}
}
