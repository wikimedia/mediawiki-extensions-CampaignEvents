<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Organizers;

use InvalidArgumentException;
use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsUser;

class OrganizersStore {
	public const SERVICE_NAME = 'CampaignEventsOrganizersStore';

	private const ROLES_MAP = [
		Organizer::ROLE_CREATOR => 1,
		Organizer::ROLE_ORGANIZER => 2
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
	 * @return Organizer[]
	 */
	public function getEventOrganizers( int $eventID ): array {
		$dbr = $this->dbHelper->getDBConnection( DB_REPLICA );
		$res = $dbr->select(
			'ce_organizers',
			[ 'ceo_user_id', 'ceo_role_id' ],
			[ 'ceo_event_id' => $eventID ]
		);
		$rolesByOrganizer = [];
		foreach ( $res as $row ) {
			$userID = $row->ceo_user_id;
			$rolesByOrganizer[$userID] = $rolesByOrganizer[$userID] ?? [];
			$rolesByOrganizer[$userID][] = array_search( (int)$row->ceo_role_id, self::ROLES_MAP, true );
		}

		$organizers = [];
		foreach ( $rolesByOrganizer as $organizerID => $roles ) {
			$organizers[] = new Organizer( $this->centralUserLookup->getLocalUser( $organizerID ), $roles );
		}
		return $organizers;
	}

	/**
	 * @param int $eventID
	 * @param ICampaignsUser $user
	 * @return bool
	 */
	public function isEventOrganizer( int $eventID, ICampaignsUser $user ): bool {
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
	 * @param int $eventID
	 * @param ICampaignsUser $user
	 * @param string[] $roles Organizer::ROLE_* constants
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
