<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Organizers;

use InvalidArgumentException;
use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use stdClass;
use Wikimedia\Rdbms\IDatabase;

class OrganizersStore {
	public const SERVICE_NAME = 'CampaignEventsOrganizersStore';

	public const GET_CREATOR_INCLUDE_DELETED = 'include';
	public const GET_CREATOR_EXCLUDE_DELETED = 'exclude';

	private const ROLES_MAP = [
		Roles::ROLE_CREATOR => 1 << 0,
		Roles::ROLE_ORGANIZER => 1 << 1,
		Roles::ROLE_TEST => 1 << 2,
	];

	private CampaignsDatabaseHelper $dbHelper;

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
			__METHOD__,
			$opts
		);

		$organizers = [];
		foreach ( $res as $row ) {
			$organizers[] = $this->rowToOrganizerObject( $row );
		}
		return $organizers;
	}

	/**
	 * @param int $eventID
	 * @param string $includeDeleted One of the GET_CREATOR_* constants.
	 * @return Organizer|null This may return null if deleted organizers are not included, and also if the event
	 * has never had a creator (e.g., if the event doesn't exist at all).
	 */
	public function getEventCreator( int $eventID, string $includeDeleted ): ?Organizer {
		$dbr = $this->dbHelper->getDBConnection( DB_REPLICA );
		$creatorRole = self::ROLES_MAP[Roles::ROLE_CREATOR];
		$where = [
			'ceo_event_id' => $eventID,
			$dbr->bitAnd( 'ceo_roles', $creatorRole ) . " = " . $creatorRole
		];

		if ( $includeDeleted === self::GET_CREATOR_EXCLUDE_DELETED ) {
			$where['ceo_deleted_at'] = null;
		}

		$row = $dbr->selectRow(
			'ce_organizers',
			'*',
			$where,
			__METHOD__
		);

		if ( $row ) {
			return $this->rowToOrganizerObject( $row );
		}

		return null;
	}

	/**
	 * @param stdClass $row
	 * @return Organizer
	 */
	private function rowToOrganizerObject( stdClass $row ): Organizer {
		$dbRoles = (int)$row->ceo_roles;
		$roles = [];
		foreach ( self::ROLES_MAP as $role => $dbVal ) {
			if ( $dbRoles & $dbVal ) {
				$roles[] = $role;
			}
		}

		return new Organizer(
			new CentralUser( (int)$row->ceo_user_id ),
			$roles,
			(int)$row->ceo_id,
			$row->ceo_agreement_timestamp !== null );
	}

	/**
	 * @param int $eventID
	 * @param CentralUser $user
	 * @return bool
	 */
	public function isEventOrganizer( int $eventID, CentralUser $user ): bool {
		return $this->getEventOrganizer( $eventID, $user ) !== null;
	}

	public function getEventOrganizer( int $eventID, CentralUser $user ): ?Organizer {
		$dbr = $this->dbHelper->getDBConnection( DB_REPLICA );
		$row = $dbr->selectRow(
			'ce_organizers',
			'*',
			[
				'ceo_event_id' => $eventID,
				'ceo_user_id' => $user->getCentralID(),
				'ceo_deleted_at' => null,
			],
			__METHOD__
		);

		if ( $row ) {
			return $this->rowToOrganizerObject( $row );
		}
		return null;
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
			],
			__METHOD__
		);
		// Intentionally casting false to int if no rows were found.
		return (int)$ret;
	}

	/**
	 * @param int $eventID
	 * @param CentralUser $organizer
	 * @return void
	 */
	public function updateClickwrapAcceptance( int $eventID, CentralUser $organizer ) {
		$dbw = $this->dbHelper->getDBConnection( DB_PRIMARY );
		$dbw->update( 'ce_organizers',
			[ 'ceo_agreement_timestamp' => $dbw->timestamp() ],
			[
				'ceo_event_id' => $eventID,
				'ceo_user_id' => $organizer->getCentralID()
			],
			__METHOD__
		);
	}

	/**
	 * @param int $eventID
	 * @param array<int,string[]> $organizers Map of [ user ID => roles[] ]
	 */
	public function addOrganizersToEvent( int $eventID, array $organizers ): void {
		$dbw = $this->dbHelper->getDBConnection( DB_PRIMARY );
		$newRows = [];
		$createdAt = $dbw->timestamp();
		$eventCreator = $this->getEventCreator( $eventID, self::GET_CREATOR_INCLUDE_DELETED );
		$eventCreatorID = $eventCreator ? $eventCreator->getUser()->getCentralID() : null;
		foreach ( $organizers as $userID => $roles ) {
			$dbRoles = 0;
			foreach ( $roles as $role ) {
				if ( !isset( self::ROLES_MAP[$role] ) ) {
					throw new InvalidArgumentException( "Invalid role `$role`" );
				}
				if ( $role === Roles::ROLE_CREATOR && $eventCreatorID && $eventCreatorID !== $userID ) {
					throw new InvalidArgumentException( "User $userID is not the event creator" );
				}

				$dbRoles |= self::ROLES_MAP[$role];
			}

			$newRows[] = [
				'ceo_event_id' => $eventID,
				'ceo_user_id' => $userID,
				'ceo_roles' => $dbRoles,
				'ceo_created_at' => $createdAt,
				'ceo_agreement_timestamp' => null,
			];
		}

		$dbw->upsert(
			'ce_organizers',
			$newRows,
			[ [ 'ceo_event_id', 'ceo_user_id' ] ],
			[
				'ceo_deleted_at' => null,
				'ceo_roles = ' . $dbw->buildExcludedValue( 'ceo_roles' ),
			],
			__METHOD__
		);
	}

	/**
	 * @param int $eventID
	 * @param int $userID
	 * @param string[] $roles Roles::ROLE_* constants
	 */
	public function addOrganizerToEvent( int $eventID, int $userID, array $roles ): void {
		$this->addOrganizersToEvent( $eventID, [ $userID => $roles ] );
	}

	/**
	 * @param int $eventID
	 * @param int[] $userIDsToNotRemove
	 */
	public function removeOrganizersFromEventExcept( int $eventID, array $userIDsToNotRemove ): void {
		$dbw = $this->dbHelper->getDBConnection( DB_PRIMARY );

		$dbw->update(
			'ce_organizers',
			[
				'ceo_deleted_at' => $dbw->timestamp()
			],
			[
				'ceo_event_id' => $eventID,
				'ceo_user_id NOT IN (' . $dbw->makeList( $userIDsToNotRemove, IDatabase::LIST_COMMA ) . ')',
				'ceo_deleted_at' => null,
			],
			__METHOD__
		);
	}
}
