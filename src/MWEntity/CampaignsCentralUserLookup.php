<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

use CentralIdLookup;
use MediaWiki\User\UserFactory;

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
	 * @throws UserNotCentralException
	 * @note This ignores permissions!
	 */
	public function getCentralID( ICampaignsUser $user ): int {
		$mwUser = $this->userFactory->newFromId( $user->getLocalID() );
		$centralID = $this->centralIDLookup->centralIdFromLocalUser( $mwUser, CentralIdLookup::AUDIENCE_RAW );
		if ( $centralID === 0 ) {
			throw new UserNotCentralException( $mwUser->getName() );
		}
		return $centralID;
	}

	/**
	 * @param int $centralID
	 * @return ICampaignsUser
	 * @throws UserNotFoundException
	 * @note This ignores permissions!
	 */
	public function getLocalUser( int $centralID ): ICampaignsUser {
		$mwUser = $this->centralIDLookup->localUserFromCentralId( $centralID, CentralIdLookup::AUDIENCE_RAW );
		if ( !$mwUser ) {
			throw new UserNotFoundException( $centralID );
		}

		return new MWUserProxy(
			$mwUser,
			$this->userFactory->newFromUserIdentity( $mwUser )
		);
	}
}
