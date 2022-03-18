<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

interface ICampaignsUser {
	/**
	 * @return int
	 */
	public function getLocalID(): int;

	/**
	 * @param string $right
	 * @return bool
	 */
	public function hasRight( string $right ): bool;
}
