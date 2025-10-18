<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Organizers;

use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;

class Organizer {
	/**
	 * @param CentralUser $user
	 * @param string[] $roles List of Roles::ROLE_* constants
	 * @param int $organizerID Unique ID which identifies this specific organizer, for a specific event, in the DB.
	 * @param bool $clickwrapAcceptance boolean which indicates if the user has accepted the PII clickwrap agreement
	 */
	public function __construct(
		private readonly CentralUser $user,
		private readonly array $roles,
		private readonly int $organizerID,
		private readonly bool $clickwrapAcceptance,
	) {
	}

	public function getUser(): CentralUser {
		return $this->user;
	}

	/**
	 * @return string[]
	 */
	public function getRoles(): array {
		return $this->roles;
	}

	public function hasRole( string $role ): bool {
		return in_array( $role, $this->roles, true );
	}

	public function getOrganizerID(): int {
		return $this->organizerID;
	}

	public function getClickwrapAcceptance(): bool {
		return $this->clickwrapAcceptance;
	}
}
