<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Organizers;

use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsUser;

class OrganizersStore {
	public const SERVICE_NAME = 'CampaignEventsOrganizersStore';

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
	 * @return ICampaignsUser[]
	 */
	public function getEventOrganizers( int $eventID ): array {
		$dbr = $this->dbHelper->getDBConnection( DB_REPLICA );
		$ids = $dbr->selectFieldValues(
			'ce_organizers',
			'ceo_user_id',
			[ 'ceo_event_id' => $eventID ]
		);
		return array_map( [ $this->centralUserLookup, 'getLocalUser' ], $ids );
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
}
