<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

use MediaWiki\Page\ProperPageIdentity;

class MWPageProxy implements ICampaignsPage {
	private ProperPageIdentity $page;
	private string $prefixedText;

	/**
	 * @param ProperPageIdentity $page
	 * @param string $prefixedText
	 */
	public function __construct( ProperPageIdentity $page, string $prefixedText ) {
		$this->page = $page;
		$this->prefixedText = $prefixedText;
	}

	/**
	 * @inheritDoc
	 */
	public function getWikiId() {
		return $this->page->getWikiId();
	}

	/**
	 * @inheritDoc
	 */
	public function getDBkey(): string {
		return $this->page->getDBkey();
	}

	/**
	 * @inheritDoc
	 */
	public function getNamespace(): int {
		return $this->page->getNamespace();
	}

	/**
	 * @inheritDoc
	 */
	public function getPrefixedText(): string {
		return $this->prefixedText;
	}

	/**
	 * @return ProperPageIdentity
	 */
	public function getPageIdentity(): ProperPageIdentity {
		return $this->page;
	}
}
