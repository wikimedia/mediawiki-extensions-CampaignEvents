<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

use MediaWiki\Page\ProperPageIdentity;

/**
 * Wrapper around MediaWiki page objects, representing an event page. Note that this can represent foreign titles.
 */
class MWPageProxy {
	private ProperPageIdentity $page;
	private string $prefixedText;

	public function __construct( ProperPageIdentity $page, string $prefixedText ) {
		$this->page = $page;
		$this->prefixedText = $prefixedText;
	}

	/**
	 * @return string|false
	 */
	public function getWikiId() {
		return $this->page->getWikiId();
	}

	public function getDBkey(): string {
		return $this->page->getDBkey();
	}

	public function getNamespace(): int {
		return $this->page->getNamespace();
	}

	/**
	 * This is here because the page could belong to another wiki, and the prefixedtext is injected. See T307358
	 */
	public function getPrefixedText(): string {
		return $this->prefixedText;
	}

	public function equals( self $other ): bool {
		return $this->page->isSamePageAs( $other->getPageIdentity() );
	}

	public function getPageIdentity(): ProperPageIdentity {
		return $this->page;
	}
}
