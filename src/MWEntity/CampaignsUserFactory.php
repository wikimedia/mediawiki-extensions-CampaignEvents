<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

use CentralIdLookup;
use MediaWiki\User\UserFactory;

class CampaignsUserFactory {
	public const SERVICE_NAME = 'CampaignEventsUserFactory';

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
	 * @param int $centralID
	 * @return ICampaignsUser
	 * @throws UserNotFoundException
	 */
	public function newUser( int $centralID ): ICampaignsUser {
		$mwUser = $this->centralIDLookup->localUserFromCentralId( $centralID );
		if ( !$mwUser ) {
			throw new UserNotFoundException( $centralID );
		}

		return new MWUserProxy(
			$mwUser,
			$this->userFactory->newFromUserIdentity( $mwUser )
		);
	}
}
