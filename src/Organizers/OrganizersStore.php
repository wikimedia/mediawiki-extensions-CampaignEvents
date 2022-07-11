<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Organizers;

use InvalidArgumentException;
use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUserNotFoundException;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\LocalUserNotFoundException;

class OrganizersStore {
	public const SERVICE_NAME = 'CampaignEventsOrganizersStore';

	private const ROLES_MAP = [
		Roles::ROLE_CREATOR => 1,
		Roles::ROLE_ORGANIZER => 2
	];

	/** @var CampaignsDatabaseHelper */
	private $dbHelper;
	/** @var CampaignsCentralUserLookup */
	private $centralUserLookup;

	/**
	 * @param CampaignsDatabaseHelper $dbHelper
	 * @param CampaignsCentralUserLookup $centralUserLookup
	 */
	public function __construct( CampaignsDatabaseHelper $dbHelper, CampaignsCentralUserLookup $centralUserLookup ) {
		$this->dbHelper = $dbHelper;
		$this->centralUserLookup = $centralUserLookup;
	}

	/**
	 * @param int $eventID
	 * @param int|null $limit
	 * @return Organizer[]
	 */
	public function getEventOrganizers( int $eventID, int $limit = null ): array {
		$dbr = $this->dbHelper->getDBConnection( DB_REPLICA );
		$res = $dbr->select(
			'ce_organizers',
			[ 'ceo_user_id', 'ceo_role_id' ],
			[ 'ceo_event_id' => $eventID ],
			$limit !== null ? [ 'LIMIT' => $limit ] : []
		);
		$rolesByOrganizer = [];
		foreach ( $res as $row ) {
			$userID = $row->ceo_user_id;
			$rolesByOrganizer[$userID] = $rolesByOrganizer[$userID] ?? [];
			$rolesByOrganizer[$userID][] = array_search( (int)$row->ceo_role_id, self::ROLES_MAP, true );
		}

		$organizers = [];
		foreach ( $rolesByOrganizer as $organizerID => $roles ) {
			try {
				$organizers[] = new Organizer( $this->centralUserLookup->getLocalUser( $organizerID ), $roles );
			} catch ( LocalUserNotFoundException $_ ) {
				// Most probably a deleted user, skip it.
			}
		}
		return $organizers;
	}

	/**
	 * @param int $eventID
	 * @param ICampaignsUser $user
	 * @return bool Returns false if the user is logged-out.
	 */
	public function isEventOrganizer( int $eventID, ICampaignsUser $user ): bool {
		if ( !$user->isRegistered() ) {
			return false;
		}
		$dbr = $this->dbHelper->getDBConnection( DB_REPLICA );
		$row = $dbr->selectRow(
			'ce_organizers',
			'*',
			[
				'ceo_event_id' => $eventID,
				'ceo_user_id' => $this->centralUserLookup->getCentralID( $user )
			]
		);
		return $row !== null;
	}

	/**
	 * Returns the number of organizers of an event
	 * @param int $eventID
	 * @return int
	 */
	public function getOrganizerCountForEvent( int $eventID ): int {
		$dbr = $this->dbHelper->getDBConnection( DB_REPLICA );
		$ret = $dbr->selectField(
			'ce_organizers',
			'COUNT(*)',
			[ 'ceo_event_id' => $eventID ]
		);
		// Intentionally casting false to int if no rows were found.
		return (int)$ret;
	}

	/**
	 * @param int $eventID
	 * @param ICampaignsUser $user
	 * @param string[] $roles Roles::ROLE_* constants
	 * @throws CentralUserNotFoundException If passed a logged-out user.
	 */
	public function addOrganizerToEvent( int $eventID, ICampaignsUser $user, array $roles ): void {
		$organizerCentralID = $this->centralUserLookup->getCentralID( $user );
		$rows = [];
		foreach ( $roles as $role ) {
			if ( !isset( self::ROLES_MAP[$role] ) ) {
				throw new InvalidArgumentException( "Invalid role `$role`" );
			}
			$rows[] = [
				'ceo_event_id' => $eventID,
				'ceo_user_id' => $organizerCentralID,
				'ceo_role_id' => self::ROLES_MAP[$role]
			];
		}
		$this->dbHelper->getDBConnection( DB_PRIMARY )->insert(
			'ce_organizers',
			$rows,
			[ 'IGNORE' ]
		);
	}
}
