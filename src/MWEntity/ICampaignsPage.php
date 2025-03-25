<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

interface ICampaignsPage {
	/**
	 * @return string|false
	 */
	public function getWikiId();

	/**
	 * @return string
	 */
	public function getDBkey(): string;

	/**
	 * @return int
	 */
	public function getNamespace(): int;

	/**
	 * This is here because the page could belong to another wiki, and the prefixedtext is injected. See T307358
	 * @return string
	 */
	public function getPrefixedText(): string;

	public function equals( self $other ): bool;
}
