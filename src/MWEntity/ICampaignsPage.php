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
}
