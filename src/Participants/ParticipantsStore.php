<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Participants;

use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;

class ParticipantsStore {
	public const SERVICE_NAME = 'CampaignEventsParticipantsStore';

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
	 * @param CentralUser $participant
	 * @return bool True if the participant was just added, false if they were already listed.
	 */
	public function addParticipantToEvent( int $eventID, CentralUser $participant ): bool {
		$dbw = $this->dbHelper->getDBConnection( DB_PRIMARY );
		// TODO: Would be great if we could do this without opening an atomic section (T304680)
		$dbw->startAtomic();
		$previousRow = $dbw->selectRow(
			'ce_participants',
			'*',
			[
				'cep_event_id' => $eventID,
				'cep_user_id' => $participant->getCentralID(),
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
					'cep_user_id' => $participant->getCentralID(),
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
	 * @param CentralUser $participant
	 * @return bool True if the participant was removed, false if they never registered or
	 * they registered but then unregistered.
	 */
	public function removeParticipantFromEvent( int $eventID, CentralUser $participant ): bool {
		$affectedRows = $this->removeParticipantsFromEvent( $eventID, [ $participant ] );
		return $affectedRows > 0;
	}

	/**
	 * @param int $eventID
	 * @param CentralUser[]|null $users Array of users, if null remove all participants,
	 * if is an empty array do nothing and return 0.
	 * @return int number of participant(s) removed
	 */
	public function removeParticipantsFromEvent( int $eventID, array $users = null ): int {
		if ( is_array( $users ) && !$users ) {
			return 0;
		}
		$dbw = $this->dbHelper->getDBConnection( DB_PRIMARY );

		$where = [
			'cep_event_id' => $eventID,
			'cep_unregistered_at' => null
		];
		if ( $users ) {
			$where['cep_user_id'] = array_map( static function ( CentralUser $user ): int {
				return $user->getCentralID();
			}, $users );
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
	 * @param int|null $lastParticipantID
	 * @return Participant[]
	 */
	public function getEventParticipants( int $eventID, int $limit = null, ?int $lastParticipantID = null ): array {
		$dbr = $this->dbHelper->getDBConnection( DB_REPLICA );

		$where = [ 'cep_event_id' => $eventID, 'cep_unregistered_at' => null ];
		if ( $lastParticipantID !== null ) {
			$where[] = 'cep_id > ' . $dbr->addQuotes( $lastParticipantID );
		}
		$opts = [ 'ORDER BY' => 'cep_id' ];
		if ( $limit !== null ) {
			$opts[ 'LIMIT' ] = $limit;
		}

		$rows = $dbr->select(
			'ce_participants',
			[ 'cep_id', 'cep_user_id', 'cep_registered_at' ],
			$where,
			$opts
		);

		$participants = [];
		foreach ( $rows as $participant ) {
			$participants[] = new Participant(
				new CentralUser( (int)$participant->cep_user_id ),
				wfTimestamp( TS_UNIX, $participant->cep_registered_at ),
				(int)$participant->cep_id
			);
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
	 * participating but then unregistered.
	 * @param int $eventID
	 * @param CentralUser $user
	 * @return bool
	 */
	public function userParticipatesToEvent( int $eventID, CentralUser $user ): bool {
		$dbr = $this->dbHelper->getDBConnection( DB_REPLICA );
		$row = $dbr->selectRow(
			'ce_participants',
			'*',
			[
				'cep_event_id' => $eventID,
				'cep_user_id' => $user->getCentralID(),
				'cep_unregistered_at' => null,
			]
		);
		return $row !== null;
	}
}
