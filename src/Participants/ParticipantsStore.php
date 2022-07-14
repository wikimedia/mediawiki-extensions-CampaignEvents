<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Participants;

use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUserNotFoundException;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\LocalUserNotFoundException;

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
	 * @throws CentralUserNotFoundException If passed a logged-out user.
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
		if ( $previousRow === null ) {
			// Do this only if the user is not already an active participants, to avoid resetting
			// the registration timestamp.
			$dbw->upsert(
				'ce_participants',
				[
					'cep_event_id' => $eventID,
					'cep_user_id' => $centralID,
					'cep_registered_at' => $dbw->timestamp(),
					'cep_unregistered_at' => null
				],
				[ [ 'cep_event_id', 'cep_user_id' ] ],
				[
					'cep_unregistered_at' => null,
					'cep_registered_at' => $dbw->timestamp()
				]
			);
		}
		$dbw->endAtomic();
		return $previousRow === null;
	}

	/**
	 * @param int $eventID
	 * @param ICampaignsUser $participant
	 * @return bool True if the participant was removed, false if they never registered or
	 * they registered but then unregistered.
	 * @throws CentralUserNotFoundException If passed a logged-out user.
	 */
	public function removeParticipantFromEvent( int $eventID, ICampaignsUser $participant ): bool {
		$userID = [ $this->centralUserLookup->getCentralID( $participant ) ];
		$affectedRows = $this->removeParticipantsFromEvent( $eventID, $userID );
		return $affectedRows > 0;
	}

	/**
	 * @param int $eventID
	 * @param array|null $userIDs array of int userIDs, if null remove all participants,
	 * if is an empty array do nothing and return 0
	 * @return int number of participant(s) removed
	 */
	public function removeParticipantsFromEvent( int $eventID, array $userIDs = null ): int {
		if ( is_array( $userIDs ) && !$userIDs ) {
			return 0;
		}
		$dbw = $this->dbHelper->getDBConnection( DB_PRIMARY );

		$where = [
			'cep_event_id' => $eventID,
			'cep_unregistered_at' => null
		];
		if ( $userIDs ) {
			$where[ 'cep_user_id' ] = $userIDs;
		}

		$dbw->update(
			'ce_participants',
			[ 'cep_unregistered_at' => $dbw->timestamp() ],
			$where
		);
		return $dbw->affectedRows();
	}

	/**
	 * @param int $eventID
	 * @param int|null $limit
	 * @return Participant[]
	 */
	public function getEventParticipants( int $eventID, int $limit = null ): array {
		$dbr = $this->dbHelper->getDBConnection( DB_REPLICA );
		$rows = $dbr->select(
			'ce_participants',
			'cep_user_id, cep_registered_at',
			[ 'cep_event_id' => $eventID, 'cep_unregistered_at' => null ],
			$limit !== null ? [ 'LIMIT' => $limit ] : []
		);

		$participants = [];
		foreach ( $rows as $participant ) {
			try {
				$participants[] = new Participant(
					$this->centralUserLookup->getLocalUser( (int)$participant->cep_user_id ),
					wfTimestamp( TS_UNIX, $participant->cep_registered_at )
				);
			} catch ( LocalUserNotFoundException $_ ) {
				// Most probably a deleted user, skip it.
			}
		}
		return $participants;
	}

	/**
	 * Returns the count of participants to an event. This does NOT include participants who unregistered.
	 * @param int $eventID
	 * @return int
	 */
	public function getParticipantCountForEvent( int $eventID ): int {
		$dbr = $this->dbHelper->getDBConnection( DB_REPLICA );
		$ret = $dbr->selectField(
			'ce_participants',
			'COUNT(*)',
			[
				'cep_event_id' => $eventID,
				'cep_unregistered_at' => null,
			]
		);
		// Intentionally casting false to int if no rows were found.
		return (int)$ret;
	}

	/**
	 * Returns whether the given user participates to the event. Note that this returns false if the user was
	 * participating but then unregistered. Returns false if the user is not registered.
	 * @param int $eventID
	 * @param ICampaignsUser $user
	 * @return bool
	 */
	public function userParticipatesToEvent( int $eventID, ICampaignsUser $user ): bool {
		if ( !$user->isRegistered() ) {
			return false;
		}

		$userCentralID = $this->centralUserLookup->getCentralID( $user );
		$dbr = $this->dbHelper->getDBConnection( DB_REPLICA );
		$row = $dbr->selectRow(
			'ce_participants',
			'*',
			[
				'cep_event_id' => $eventID,
				'cep_user_id' => $userCentralID,
				'cep_unregistered_at' => null,
			]
		);
		return $row !== null;
	}
}
