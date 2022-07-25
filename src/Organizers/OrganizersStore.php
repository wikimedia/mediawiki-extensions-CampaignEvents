<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Organizers;

use InvalidArgumentException;
use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;

class OrganizersStore {
	public const SERVICE_NAME = 'CampaignEventsOrganizersStore';

	private const ROLES_MAP = [
		Roles::ROLE_CREATOR => 1,
		Roles::ROLE_ORGANIZER => 2
	];

	/** @var CampaignsDatabaseHelper */
	private $dbHelper;

	/**
	 * @param CampaignsDatabaseHelper $dbHelper
	 */
	public function __construct( CampaignsDatabaseHelper $dbHelper ) {
		$this->dbHelper = $dbHelper;
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
			$organizers[] = new Organizer( new CentralUser( $organizerID ), $roles );
		}
		return $organizers;
	}

	/**
	 * @param int $eventID
	 * @param CentralUser $user
	 * @return bool
	 */
	public function isEventOrganizer( int $eventID, CentralUser $user ): bool {
		$dbr = $this->dbHelper->getDBConnection( DB_REPLICA );
		$row = $dbr->selectRow(
			'ce_organizers',
			'*',
			[
				'ceo_event_id' => $eventID,
				'ceo_user_id' => $user->getCentralID()
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
	 * @param CentralUser $user
	 * @param string[] $roles Roles::ROLE_* constants
	 */
	public function addOrganizerToEvent( int $eventID, CentralUser $user, array $roles ): void {
		$rows = [];
		foreach ( $roles as $role ) {
			if ( !isset( self::ROLES_MAP[$role] ) ) {
				throw new InvalidArgumentException( "Invalid role `$role`" );
			}
			$rows[] = [
				'ceo_event_id' => $eventID,
				'ceo_user_id' => $user->getCentralID(),
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
