<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Organizers;

use InvalidArgumentException;
use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;

class OrganizersStore {
	public const SERVICE_NAME = 'CampaignEventsOrganizersStore';

	private const ROLES_MAP = [
		Roles::ROLE_CREATOR => 1 << 0,
		Roles::ROLE_ORGANIZER => 1 << 1,
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
	 * @param int|null $lastOrganizerId
	 * @return Organizer[]
	 */
	public function getEventOrganizers( int $eventID, int $limit = null, int $lastOrganizerId = null ): array {
		$dbr = $this->dbHelper->getDBConnection( DB_REPLICA );
		$where = [
			'ceo_event_id' => $eventID,
			'ceo_deleted_at' => null,
		];
		if ( $lastOrganizerId !== null ) {
			$where[] = 'ceo_id > ' . $dbr->addQuotes( $lastOrganizerId );
		}
		$opts = [ 'ORDER BY' => 'ceo_id' ];
		if ( $limit !== null ) {
			$opts['LIMIT'] = $limit;
		}
		$res = $dbr->select(
			'ce_organizers',
			'*',
			$where,
			$opts
		);

		$organizers = [];
		foreach ( $res as $row ) {
			$dbRoles = (int)$row->ceo_roles;
			$roles = [];
			foreach ( self::ROLES_MAP as $role => $dbVal ) {
				if ( $dbRoles & $dbVal ) {
					$roles[] = $role;
				}
			}
			$organizers[] = new Organizer( new CentralUser( (int)$row->ceo_user_id ), $roles, (int)$row->ceo_id );
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
				'ceo_user_id' => $user->getCentralID(),
				'ceo_deleted_at' => null,
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
			[
				'ceo_event_id' => $eventID,
				'ceo_deleted_at' => null,
			]
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
		$dbRoles = 0;
		foreach ( $roles as $role ) {
			if ( !isset( self::ROLES_MAP[$role] ) ) {
				throw new InvalidArgumentException( "Invalid role `$role`" );
			}
			$dbRoles |= self::ROLES_MAP[$role];
		}
		$dbw = $this->dbHelper->getDBConnection( DB_PRIMARY );
		$dbw->upsert(
			'ce_organizers',
			[
				'ceo_event_id' => $eventID,
				'ceo_user_id' => $user->getCentralID(),
				'ceo_roles' => $dbRoles,
				'ceo_created_at' => $dbw->timestamp(),
				'ceo_deleted_at' => null
			],
			[ [ 'ceo_event_id', 'ceo_user_id' ] ],
			[
				'ceo_roles' => $dbRoles,
				'ceo_deleted_at' => null,
			]
		);
	}
}
