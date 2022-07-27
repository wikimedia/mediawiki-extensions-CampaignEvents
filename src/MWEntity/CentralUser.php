<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

/**
 * This class represents a global user account, which could potentially be local depending on
 * what CentralIdLookup is used. This has no matching MW class, but it's similar to UserIdentity
 * with the addition of the central ID.
 * Note that this class only stored the ID because that's the only piece of information provided by
 * CentralIdLookup. Anything else (e.g., the name) would require a second query to be retrieved, which
 * is unnecessarily expensive.
 */
class CentralUser {
	/** @var int */
	private $centralID;

	/**
	 * @param int $centralID
	 */
	public function __construct( int $centralID ) {
		$this->centralID = $centralID;
	}

	/**
	 * @return int
	 */
	public function getCentralID(): int {
		return $this->centralID;
	}

	/**
	 * @param CentralUser $other
	 * @return bool
	 */
	public function equals( self $other ): bool {
		return $this->centralID === $other->centralID;
	}
}
