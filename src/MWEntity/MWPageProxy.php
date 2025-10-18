<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

use MediaWiki\Page\PageIdentityValue;
use MediaWiki\Page\ProperPageIdentity;

/**
 * Wrapper around MediaWiki page objects, representing an event page. Note that this can represent foreign titles.
 */
class MWPageProxy {
	public function __construct(
		private ProperPageIdentity $page,
		private string $prefixedText,
	) {
	}

	/**
	 * @return string|false
	 */
	public function getWikiId(): string|bool {
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

	/** @return array{page:array<string,mixed>,prefixedText:string} */
	public function __serialize(): array {
		return [
			'page' => [
				'id' => $this->page->getId( $this->page->getWikiId() ),
				'namespace' => $this->page->getNamespace(),
				'dbKey' => $this->page->getDBkey(),
				'wikiId' => $this->page->getWikiId(),
			],
			'prefixedText' => $this->prefixedText,
		];
	}

	/** @param array{page:array<string,mixed>,prefixedText:string} $data */
	public function __unserialize( array $data ): void {
		$this->page = new PageIdentityValue(
			$data['page']['id'],
			$data['page']['namespace'],
			$data['page']['dbKey'],
			$data['page']['wikiId']
		);
		$this->prefixedText = $data['prefixedText'];
	}
}
