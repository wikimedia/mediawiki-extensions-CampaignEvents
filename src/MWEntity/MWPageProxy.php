<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

use MediaWiki\Page\ProperPageIdentity;

class MWPageProxy implements ICampaignsPage {
	/** @var ProperPageIdentity */
	private $page;

	/**
	 * @param ProperPageIdentity $page
	 */
	public function __construct( ProperPageIdentity $page ) {
		$this->page = $page;
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
	 * @return ProperPageIdentity
	 */
	public function getPageIdentity(): ProperPageIdentity {
		return $this->page;
	}
}
