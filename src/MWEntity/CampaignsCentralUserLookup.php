<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

use CentralIdLookup;
use MediaWiki\User\UserFactory;

/**
 * @todo Audience checks can be improved, but having them in a storage layer (like CentralIdLookup is) makes things
 * harder in the first place.
 */
class CampaignsCentralUserLookup {
	public const SERVICE_NAME = 'CampaignEventsCentralUserLookup';

	/** @var CentralIdLookup */
	private $centralIDLookup;
	/** @var UserFactory */
	private $userFactory;

	/**
	 * @param CentralIdLookup $centralIdLookup
	 * @param UserFactory $userFactory
	 */
	public function __construct(
		CentralIdLookup $centralIdLookup,
		UserFactory $userFactory
	) {
		$this->centralIDLookup = $centralIdLookup;
		$this->userFactory = $userFactory;
	}

	/**
	 * @param ICampaignsUser $user
	 * @return int
	 * @throws CentralUserNotFoundException
	 * @note This does not check if the user is deleted. This seems easier, and
	 * the CentralAuth provider ignored $audience anyway.
	 */
	public function getCentralID( ICampaignsUser $user ): int {
		$mwUser = $this->userFactory->newFromId( $user->getLocalID() );
		$centralID = $this->centralIDLookup->centralIdFromLocalUser( $mwUser, CentralIdLookup::AUDIENCE_RAW );
		if ( $centralID === 0 ) {
			throw new CentralUserNotFoundException( $mwUser->getName() );
		}
		return $centralID;
	}

	/**
	 * @param int $centralID
	 * @return ICampaignsUser
	 * @throws LocalUserNotFoundException
	 * @note This considers deleted users as non-existent.
	 */
	public function getLocalUser( int $centralID ): ICampaignsUser {
		$mwUser = $this->centralIDLookup->localUserFromCentralId( $centralID );
		if ( !$mwUser ) {
			throw new LocalUserNotFoundException( $centralID );
		}

		return new MWUserProxy(
			$mwUser,
			$this->userFactory->newFromUserIdentity( $mwUser )
		);
	}
}
