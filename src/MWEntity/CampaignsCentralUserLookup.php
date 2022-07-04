<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

use CentralIdLookup;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use UnexpectedValueException;

/**
 * This class is used to retrieve data about global user accounts, like MW's CentralIdLookup.
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
	 * Returns the central user corresponding to the given local user, if it exists. This method should be
	 * avoided if possible, because we should only work with (the current) Authority and CentralUser.
	 * @param UserIdentity $userIdentity
	 * @return CentralUser
	 * @throws UserNotGlobalException
	 */
	public function newFromUserIdentity( UserIdentity $userIdentity ): CentralUser {
		// @note This does not check if the user is deleted. This seems easier, and
		// the CentralAuth provider ignored $audience anyway.
		// TODO This should be improved somehow (T312821)
		$centralID = $this->centralIDLookup->centralIdFromLocalUser( $userIdentity, CentralIdLookup::AUDIENCE_RAW );
		if ( $centralID === 0 ) {
			throw new UserNotGlobalException( $userIdentity->getId() );
		}
		return new CentralUser( $centralID );
	}

	/**
	 * Returns the central user corresponding to the given authority, if it exists. NOTE: Make sure to handle
	 * the exception, if the user is not guaranteed to have a global account.
	 * @param ICampaignsAuthority $authority
	 * @return CentralUser
	 * @throws UserNotGlobalException
	 */
	public function newFromAuthority( ICampaignsAuthority $authority ): CentralUser {
		if ( !$authority instanceof MWAuthorityProxy ) {
			throw new UnexpectedValueException(
				'Unknown campaigns authority implementation: ' . get_class( $authority )
			);
		}
		$mwUser = $this->userFactory->newFromId( $authority->getUserIdentity()->getId() );
		return $this->newFromUserIdentity( $mwUser );
	}

	/**
	 * @param CentralUser $user
	 * @return string
	 * @throws CentralUserNotFoundException
	 * @throws HiddenCentralUserException
	 */
	public function getUserName( CentralUser $user ): string {
		$centralID = $user->getCentralID();
		$val = $this->centralIDLookup->nameFromCentralId( $centralID );
		if ( $val === null ) {
			throw new CentralUserNotFoundException( $centralID );
		} elseif ( $val === '' ) {
			throw new HiddenCentralUserException( $centralID );
		}

		return $val;
	}

	/**
	 * Checks whether the given CentralUser actually exists and is visible.
	 * @param CentralUser $user
	 * @return bool
	 */
	public function existsAndIsVisible( CentralUser $user ): bool {
		try {
			$this->getUserName( $user );
			return true;
		} catch ( CentralUserNotFoundException | HiddenCentralUserException $_ ) {
			return false;
		}
	}

	/**
	 * Checks whether the given central user is attached, i.e. it exists on the current wiki.
	 * @param CentralUser $user
	 * @return bool
	 */
	public function existsLocally( CentralUser $user ): bool {
		// NOTE: we can't really use isAttached here, because that takes a (local) UserIdentity, and the purpose
		// of this method is to tell us if a local user exists at all.
		return $this->centralIDLookup->localUserFromCentralId( $user->getCentralID() ) !== null;
	}

	/**
	 * @param array<int,null> $centralIDsMap The central IDs are used as keys, the values must be null
	 * @return array<int,string> Same keys as given to the method, but the values are the names. Suppressed and
	 * non-existing users are excluded from the return value.
	 */
	public function getNames( array $centralIDsMap ): array {
		$names = $this->centralIDLookup->lookupCentralIds( $centralIDsMap );
		$ret = [];
		foreach ( $names as $id => $name ) {
			if ( $name !== null && $name !== '' ) {
				$ret[$id] = $name;
			}
		}
		return $ret;
	}
}
