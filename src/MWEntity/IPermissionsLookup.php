<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

/**
 * This class can be used to perform permission checks on users other than the performer of the request (for that,
 * use ICampaignsAuthority). Methods in this class take bare usernames as to avoid creating a new abstraction to
 * represent local users.
 */
interface IPermissionsLookup {
	/**
	 * @param string $username Callers should make sure that the username is valid
	 * @param string $right
	 * @return bool
	 */
	public function userHasRight( string $username, string $right ): bool;

	/**
	 * @param string $username Callers should make sure that the username is valid
	 * @return bool
	 */
	public function userIsNamed( string $username ): bool;

	/**
	 * @param string $username Callers should make sure that the username is valid
	 * @return bool
	 */
	public function userIsSitewideBlocked( string $username ): bool;
}
