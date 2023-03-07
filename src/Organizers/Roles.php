<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Organizers;

/**
 * Class with constants for organizer roles
 */
class Roles {
	public const ROLE_CREATOR = 'creator';
	// This is for a generic organizer
	public const ROLE_ORGANIZER = 'organizer';

	// FIXME HACK: Add a fake value that is only needed in tests where we need at least 3 roles. Remove this
	// in favour of using an actual role as soon as we add more of them.
	public const ROLE_TEST = 'this-is-just-a-test-role';
}
