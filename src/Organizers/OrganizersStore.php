<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Organizers;

use InvalidArgumentException;
use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use stdClass;

class OrganizersStore {
	public const SERVICE_NAME = 'CampaignEventsOrganizersStore';

	public const GET_CREATOR_INCLUDE_DELETED = 'include';
	public const GET_CREATOR_EXCLUDE_DELETED = 'exclude';

	private const ROLES_MAP = [
		Roles::ROLE_CREATOR => 1 << 0,
		Roles::ROLE_ORGANIZER => 1 << 1,
		Roles::ROLE_TEST => 1 << 2,
	];

	public function __construct(
		private readonly CampaignsDatabaseHelper $dbHelper,
	) {
	}

	/**
	 * @return Organizer[]
	 */
	public function getEventOrganizers( int $eventID, ?int $limit = null, ?int $lastOrganizerId = null ): array {
		$dbr = $this->dbHelper->getDBConnection( DB_REPLICA );
		$where = [
			'ceo_event_id' => $eventID,
			'ceo_deleted_at' => null,
		];
		if ( $lastOrganizerId !== null ) {
			$where[] = $dbr->expr( 'ceo_id', '>', $lastOrganizerId );
		}
		$queryBuilder = $dbr->newSelectQueryBuilder()
			->select( '*' )
			->from( 'ce_organizers' )
			->where( $where )
			->orderBy( 'ceo_id' )
			->caller( __METHOD__ );
		if ( $limit !== null ) {
			$queryBuilder->limit( $limit );
		}
		$res = $queryBuilder->fetchResultSet();

		$organizers = [];
		foreach ( $res as $row ) {
			$organizers[] = $this->rowToOrganizerObject( $row );
		}
		return $organizers;
	}

	/**
	 * Returns an array of lists of organizers for the given events. The limit is for each individual event.
	 * @param int[] $eventIDs
	 * @param int $perEventLimit
	 * @return Organizer[][]
	 */
	public function getOrganizersForEvents( array $eventIDs, int $perEventLimit ): array {
		$dbr = $this->dbHelper->getDBConnection( DB_REPLICA );
		// This uses a self-join to let us count and limit the number of rows for each group (event).
		// Rownumber/partition-based approaches would likely be cleaner, but it seems that we can't use those.
		$res = $dbr->newSelectQueryBuilder()
			->select( 'org1.*' )
			->from( 'ce_organizers', 'org1' )
			->join(
				'ce_organizers',
				'org2',
				[
					'org1.ceo_event_id = org2.ceo_event_id',
					'org2.ceo_id <= org1.ceo_id',
					'org2.ceo_deleted_at' => null,
				]
			)
			->where( [
				'org1.ceo_event_id' => $eventIDs,
				'org1.ceo_deleted_at' => null,
			] )
			->groupBy( [
				// List all columns explcitly to please MariaDB and its lack of functional dependency detection
				// with ONLY_FULL_GROUP_BY.
				'org1.ceo_event_id',
				'org1.ceo_id',
				'org1.ceo_user_id',
				'org1.ceo_roles',
				'org1.ceo_created_at',
				'org1.ceo_deleted_at',
				'org1.ceo_agreement_timestamp',
			] )
			->having( "COUNT(*) <= $perEventLimit" )
			->orderBy( [ 'ceo_event_id', 'ceo_id' ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		$organizers = array_fill_keys( $eventIDs, [] );
		foreach ( $res as $row ) {
			$eventID = $row->ceo_event_id;
			$organizers[$eventID][] = $this->rowToOrganizerObject( $row );
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
		return $this->getEventCreators( [ $eventID ], $includeDeleted )[ $eventID ];
	}

	/**
	 * @param int[] $eventIDs
	 * @param string $includeDeleted One of the GET_CREATOR_* constants.
	 * @return array<int,Organizer|null> Maps event ID to the creator, or null if deleted organizers are not included,
	 * and also if the event has never had a creator (e.g., if the event doesn't exist at all).
	 */
	public function getEventCreators( array $eventIDs, string $includeDeleted ): array {
		$dbr = $this->dbHelper->getDBConnection( DB_REPLICA );
		$creatorRole = self::ROLES_MAP[Roles::ROLE_CREATOR];
		$where = [
			'ceo_event_id' => $eventIDs,
			$dbr->bitAnd( 'ceo_roles', $creatorRole ) . " = " . $creatorRole
		];

		if ( $includeDeleted === self::GET_CREATOR_EXCLUDE_DELETED ) {
			$where['ceo_deleted_at'] = null;
		}

		$res = $dbr->newSelectQueryBuilder()
			->select( '*' )
			->from( 'ce_organizers' )
			->where( $where )
			->caller( __METHOD__ )
			->fetchResultSet();

		$creators = array_fill_keys( $eventIDs, null );
		foreach ( $res as $row ) {
			$eventID = $row->ceo_event_id;
			$creators[$eventID] = $this->rowToOrganizerObject( $row );
		}

		return $creators;
	}

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

	public function isEventOrganizer( int $eventID, CentralUser $user ): bool {
		return $this->getEventOrganizer( $eventID, $user ) !== null;
	}

	public function getEventOrganizer( int $eventID, CentralUser $user ): ?Organizer {
		$dbr = $this->dbHelper->getDBConnection( DB_REPLICA );
		$row = $dbr->newSelectQueryBuilder()
			->select( '*' )
			->from( 'ce_organizers' )
			->where( [
				'ceo_event_id' => $eventID,
				'ceo_user_id' => $user->getCentralID(),
				'ceo_deleted_at' => null,
			] )
			->caller( __METHOD__ )
			->fetchRow();

		if ( $row ) {
			return $this->rowToOrganizerObject( $row );
		}
		return null;
	}

	/**
	 * Returns the number of organizers of an event
	 */
	public function getOrganizerCountForEvent( int $eventID ): int {
		return $this->getOrganizerCountForEvents( [ $eventID ] )[ $eventID ];
	}

	/**
	 * Returns the number of organizers of each event in a list.
	 * @param int[] $eventIDs
	 * @return array<int,int> Maps event ID to number of organizers
	 */
	public function getOrganizerCountForEvents( array $eventIDs ): array {
		$dbr = $this->dbHelper->getDBConnection( DB_REPLICA );
		$res = $dbr->newSelectQueryBuilder()
			->select( [ 'num' => 'COUNT(*)', 'ceo_event_id' ] )
			->from( 'ce_organizers' )
			->where( [
				'ceo_event_id' => $eventIDs,
				'ceo_deleted_at' => null,
			] )
			->groupBy( 'ceo_event_id' )
			->caller( __METHOD__ )
			->fetchResultSet();
		$counts = array_fill_keys( $eventIDs, 0 );
		foreach ( $res as $row ) {
			$counts[ $row->ceo_event_id ] = (int)$row->num;
		}
		return $counts;
	}

	public function updateClickwrapAcceptance( int $eventID, CentralUser $organizer ): void {
		$dbw = $this->dbHelper->getDBConnection( DB_PRIMARY );
		$dbw->newUpdateQueryBuilder()
			->update( 'ce_organizers' )
			->set( [ 'ceo_agreement_timestamp' => $dbw->timestamp() ] )
			->where( [
				'ceo_event_id' => $eventID,
				'ceo_user_id' => $organizer->getCentralID()
			] )
			->caller( __METHOD__ )
			->execute();
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
		$eventCreatorID = $eventCreator?->getUser()->getCentralID();
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

		$dbw->newInsertQueryBuilder()
			->insertInto( 'ce_organizers' )
			->rows( $newRows )
			->onDuplicateKeyUpdate()
			->uniqueIndexFields( [ 'ceo_event_id', 'ceo_user_id' ] )
			->set( [
				'ceo_deleted_at' => null,
				'ceo_roles = ' . $dbw->buildExcludedValue( 'ceo_roles' ),
			] )
			->caller( __METHOD__ )
			->execute();
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

		$dbw->newUpdateQueryBuilder()
			->update( 'ce_organizers' )
			->set( [
				'ceo_deleted_at' => $dbw->timestamp()
			] )
			->where( [
				'ceo_event_id' => $eventID,
				$dbw->expr( 'ceo_user_id', '!=', $userIDsToNotRemove ),
				'ceo_deleted_at' => null,
			] )
			->caller( __METHOD__ )
			->execute();
	}
}
