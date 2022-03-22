<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Participants;

use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsUser;

class ParticipantsStore {
	public const SERVICE_NAME = 'CampaignEventsParticipantsStore';

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
	 * @param ICampaignsUser $participant
	 * @return bool True if the participant was just added, false if they were already listed.
	 */
	public function addParticipantToEvent( int $eventID, ICampaignsUser $participant ): bool {
		$dbw = $this->dbHelper->getDBConnection( DB_PRIMARY );
		$centralID = $this->centralUserLookup->getCentralID( $participant );
		// TODO: Would be great if we could do this without opening an atomic section (T304680)
		$dbw->startAtomic();
		$previousRow = $dbw->selectRow(
			'ce_participants',
			'*',
			[
				'cep_event_id' => $eventID,
				'cep_user_id' => $centralID,
				'cep_unregistered_at' => null
			],
			[ 'FOR UPDATE' ]
		);
		$dbw->upsert(
			'ce_participants',
			[
				'cep_event_id' => $eventID,
				'cep_user_id' => $centralID,
				'cep_unregistered_at' => null
			],
			[ [ 'cep_event_id', 'cep_user_id' ] ],
			[ 'cep_unregistered_at' => null ]
		);
		$dbw->endAtomic();
		return $previousRow === null;
	}

	/**
	 * @param int $eventID
	 * @param ICampaignsUser $participant
	 * @return bool True if the participant was removed, false if they never registered or
	 * they registered but then unregistered.
	 */
	public function removeParticipantFromEvent( int $eventID, ICampaignsUser $participant ): bool {
		$dbw = $this->dbHelper->getDBConnection( DB_PRIMARY );
		$dbw->update(
			'ce_participants',
			[ 'cep_unregistered_at' => $dbw->timestamp() ],
			[
				'cep_event_id' => $eventID,
				'cep_user_id' => $this->centralUserLookup->getCentralID( $participant ),
				'cep_unregistered_at' => null
			]
		);
		return $dbw->affectedRows() > 0;
	}
}
