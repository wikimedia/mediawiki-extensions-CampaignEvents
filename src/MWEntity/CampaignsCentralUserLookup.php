<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

use InvalidArgumentException;
use MediaWiki\User\CentralId\CentralIdLookup;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserNameUtils;

/**
 * This class is used to retrieve data about global user accounts, like MW's CentralIdLookup.
 */
class CampaignsCentralUserLookup {
	public const SERVICE_NAME = 'CampaignEventsCentralUserLookup';

	public const USER_NOT_FOUND = '[not found]';
	public const USER_HIDDEN = '[hidden]';

	private CentralIdLookup $centralIDLookup;
	private UserFactory $userFactory;
	private UserNameUtils $userNameUtils;

	/**
	 * @var array<int,string> Cache of usernames by central user ID. Values can be either usernames, or the special
	 * values self::USER_NOT_FOUND and self::USER_HIDDEN.
	 */
	private array $nameByIDCache = [];

	/**
	 * @param CentralIdLookup $centralIdLookup
	 * @param UserFactory $userFactory
	 * @param UserNameUtils $userNameUtils
	 */
	public function __construct(
		CentralIdLookup $centralIdLookup,
		UserFactory $userFactory,
		UserNameUtils $userNameUtils
	) {
		$this->centralIDLookup = $centralIdLookup;
		$this->userFactory = $userFactory;
		$this->userNameUtils = $userNameUtils;
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
		$mwUser = $this->userFactory->newFromId( $authority->getLocalUserID() );
		return $this->newFromUserIdentity( $mwUser );
	}

	/**
	 * Returns the central user corresponding to the given username, if it exists. NOTE: Make sure to handle
	 * the exception, if the user is not guaranteed to have a global account.
	 * Callers must ensure that the username is valid
	 * @param string $userName
	 * @return CentralUser
	 * @throws UserNotGlobalException
	 */
	public function newFromLocalUsername( string $userName ): CentralUser {
		$mwUser = $this->userFactory->newFromName( $userName );
		if ( !$mwUser instanceof User ) {
			throw new InvalidArgumentException(
				"Invalid username: $userName"
			);
		}
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
		$ret = $this->getNamesIncludingDeletedAndSuppressed( [ $centralID => null ] )[$centralID];
		if ( $ret === self::USER_NOT_FOUND ) {
			throw new CentralUserNotFoundException( $centralID );
		}
		if ( $ret === self::USER_HIDDEN ) {
			throw new HiddenCentralUserException( $centralID );
		}
		return $ret;
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
	 * Returns the usernames of the users with the given central user IDs. Suppressed and non-existing users are
	 * excluded from the return value.
	 *
	 * @param array<int,null> $centralIDsMap The central IDs are used as keys, the values must be null
	 * @return array<int,string> Same keys as given to the method, but the values are the names.
	 */
	public function getNames( array $centralIDsMap ): array {
		$allNames = $this->getNamesIncludingDeletedAndSuppressed( $centralIDsMap );
		return array_filter(
			$allNames,
			static fn ( $name ) => $name !== self::USER_HIDDEN && $name !== self::USER_NOT_FOUND
		);
	}

	/**
	 * Given a map whose keys are normalized local usernames, returns a copy of that map where every user with a
	 * global account has the corresponding value replaced by their central user ID. Users without a global account
	 * have their values unchanged.
	 *
	 * @param array<string,mixed> $localNamesMap
	 * @return array<string,mixed>
	 */
	public function getIDs( array $localNamesMap ): array {
		return $this->centralIDLookup->lookupUserNames( $localNamesMap );
	}

	/**
	 * Returns the usernames of the users with the given central user IDs. Suppressed and non-existing users are
	 * included in the return value, with self::USER_NOT_FOUND or self::USER_HIDDEN as the value.
	 *
	 * @param array<int,null> $centralIDsMap The central IDs are used as keys, the values must be null
	 * @return array<int,string> Same keys as given to the method, but the values are the names.
	 */
	public function getNamesIncludingDeletedAndSuppressed( array $centralIDsMap ): array {
		$ret = array_intersect_key( $this->nameByIDCache, $centralIDsMap );
		$remainingIDsMap = array_diff_key( $centralIDsMap, $this->nameByIDCache );
		if ( !$remainingIDsMap ) {
			return $ret;
		}
		$remainingNames = $this->centralIDLookup->lookupCentralIds( $remainingIDsMap );
		foreach ( $remainingNames as $id => $name ) {
			if ( $name === null ) {
				$ret[$id] = self::USER_NOT_FOUND;
			} elseif ( $name === '' ) {
				$ret[$id] = self::USER_HIDDEN;
			} else {
				$ret[$id] = $name;
			}
			$this->nameByIDCache[$id] = $ret[$id];
		}
		return $ret;
	}

	/**
	 * @param string $userName
	 * @return bool
	 * @todo This method should possibly be moved to a separate service in the future.
	 */
	public function isValidLocalUsername( string $userName ): bool {
		return $this->userNameUtils->getCanonical( $userName ) !== false;
	}
}
