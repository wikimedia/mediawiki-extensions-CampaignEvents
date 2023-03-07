<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

/**
 * This interface is similar to MediaWiki's Authority, in that represents the authority for a given operation
 * and is used for permission checks.
 */
interface ICampaignsAuthority {
	/**
	 * @param string $right
	 * @return bool
	 */
	public function hasRight( string $right ): bool;

	/**
	 * @return bool Whether the user is blocked and the block is sitewide
	 */
	public function isSitewideBlocked(): bool;

	/**
	 * @return bool
	 */
	public function isRegistered(): bool;

	/**
	 * @return string
	 */
	public function getName(): string;
}
