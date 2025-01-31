<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

/**
 * This interface is similar to MediaWiki's Authority, in that represents the authority for a given operation
 * and is used for permission checks. Note that the authority is associated to the current request context,
 * and should not be used for users other than the one performing the action.
 */
interface ICampaignsAuthority {
	public function hasRight( string $right ): bool;

	/**
	 * @return bool Whether the user is blocked and the block is sitewide
	 */
	public function isSitewideBlocked(): bool;

	public function isNamed(): bool;

	public function getName(): string;

	public function getLocalUserID(): int;
}
