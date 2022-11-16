<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Participants;

use InvalidArgumentException;
use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;

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
	 * @param CentralUser $participant
	 * @param bool $private
	 * @return bool True if the participant was just added, false if they were already listed.
	 */
	public function addParticipantToEvent( int $eventID, CentralUser $participant, bool $private ): bool {
		$dbw = $this->dbHelper->getDBConnection( DB_PRIMARY );
		// TODO: Would be great if we could do this without opening an atomic section (T304680)
		$dbw->startAtomic();
		$previousRow = $dbw->selectRow(
			'ce_participants',
			'*',
			[
				'cep_event_id' => $eventID,
				'cep_user_id' => $participant->getCentralID(),
				'cep_private' => $private,
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
					'cep_private' => $private,
					'cep_registered_at' => $dbw->timestamp(),
					'cep_unregistered_at' => null
				],
				[ [ 'cep_event_id', 'cep_user_id' ] ],
				[
					'cep_private' => $private,
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
	 * @param bool $invertUsers
	 * @return int number of participants removed
	 */
	public function removeParticipantsFromEvent(
		int $eventID,
		array $users = null,
		bool $invertUsers = false
	): int {
		if ( $users === null && $invertUsers ) {
			throw new InvalidArgumentException( "The users must be an array of user ids if invertUsers is true" );
		}

		if ( is_array( $users ) && !$users ) {
			throw new InvalidArgumentException(
				"The users must be an array of user ids, or null (to remove all users)"
			);
		}

		$dbw = $this->dbHelper->getDBConnection( DB_PRIMARY );

		$where = [
			'cep_event_id' => $eventID,
			'cep_unregistered_at' => null
		];
		if ( $users ) {
			$userIDs = array_map( static function ( CentralUser $user ): int {
				return $user->getCentralID();
			}, $users );
			if ( !$invertUsers ) {
				$where['cep_user_id'] = $userIDs;
			} else {
				$where[] = 'cep_user_id NOT IN (' . $dbw->makeCommaList( $userIDs ) . ')';
			}
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
	 * @param string|null $usernameFilter If not null, only include participants whose username contains the
	 * given string (case-insensitive). Cannot be the empty string.
	 * @param bool $showPrivate
	 * @param int|null $excludeUser ID of a user to exclude from the result (useful when the request user is handled
	 * separately).
	 * @return Participant[]
	 */
	public function getEventParticipants(
		int $eventID,
		int $limit = null,
		int $lastParticipantID = null,
		string $usernameFilter = null,
		bool $showPrivate = false,
		int $excludeUser = null
	): array {
		if ( $usernameFilter === '' ) {
			throw new InvalidArgumentException( "The username filter cannot be the empty string" );
		}
		$dbr = $this->dbHelper->getDBConnection( DB_REPLICA );

		$where = [ 'cep_event_id' => $eventID, 'cep_unregistered_at' => null ];
		if ( $lastParticipantID !== null ) {
			$where[] = 'cep_id > ' . $dbr->addQuotes( $lastParticipantID );
		}
		if ( !$showPrivate ) {
			$where['cep_private'] = false;
		}
		if ( $excludeUser !== null ) {
			$where[] = "cep_user_id != $excludeUser";
		}
		$opts = [ 'ORDER BY' => 'cep_id' ];
		// XXX If a username filter is specified, we run an unfiltered query without limit and then filter
		// and limit the results later. This is a bit hacky but there seems to be no super-clean alternative, since
		// we can't join whatever table is used for central users and storing the username is non-trivial due to
		// users being renamed. See T308574 and T312645.
		if ( $limit !== null && $usernameFilter === null ) {
			$opts[ 'LIMIT' ] = $limit;
		}

		$rows = $dbr->select(
			'ce_participants',
			[ 'cep_id', 'cep_user_id', 'cep_registered_at', 'cep_private' ],
			$where,
			$opts
		);

		$centralIDsMap = [];
		foreach ( $rows as $row ) {
			$centralIDsMap[(int)$row->cep_user_id] = null;
		}
		$globalNames = $this->centralUserLookup->getNames( $centralIDsMap );

		$participants = [];
		$num = 0;
		foreach ( $rows as $row ) {
			if ( $limit !== null && $num >= $limit ) {
				break;
			}
			$centralID = (int)$row->cep_user_id;
			if (
				$usernameFilter !== null &&
				( !isset( $globalNames[$centralID] ) || stripos( $globalNames[$centralID], $usernameFilter ) === false )
			) {
				continue;
			}
			$participants[] = new Participant(
				new CentralUser( $centralID ),
				wfTimestamp( TS_UNIX, $row->cep_registered_at ),
				(int)$row->cep_id,
				(bool)$row->cep_private
			);
			$num++;
		}

		return $participants;
	}

	/**
	 * Returns a Participant object for the given user and event, if the user is a participant (and has not
	 * unregistered), or null otherwise.
	 * @param int $eventID
	 * @param CentralUser $user
	 * @param bool $showPrivate
	 * @return Participant|null
	 */
	public function getEventParticipant(
		int $eventID,
		CentralUser $user,
		bool $showPrivate = false
	): ?Participant {
		$dbr = $this->dbHelper->getDBConnection( DB_REPLICA );
		$conditions = [
			'cep_event_id' => $eventID,
			'cep_user_id' => $user->getCentralID(),
			'cep_unregistered_at' => null,
		];
		if ( !$showPrivate ) {
			$conditions['cep_private'] = false;
		}
		$row = $dbr->selectRow(
			'ce_participants',
			'*',
			$conditions
		);

		if ( $row === null ) {
			return null;
		}

		return new Participant(
			new CentralUser( (int)$row->cep_user_id ),
			wfTimestamp( TS_UNIX, $row->cep_registered_at ),
			(int)$row->cep_id,
			(bool)$row->cep_private
		);
	}

	/**
	 * Returns the count of participants to an event.
	 * @param int $eventID
	 * @param bool $public
	 * @return int
	 */
	private function getParticipantCountForEvent( int $eventID, bool $public ): int {
		$dbr = $this->dbHelper->getDBConnection( DB_REPLICA );
		$where = [
			'cep_event_id' => $eventID,
			'cep_unregistered_at' => null,
		];
		if ( !$public ) {
			$where['cep_private'] = true;
		}
		$ret = $dbr->selectField(
			'ce_participants',
			'COUNT(*)',
			$where
		);
		// Intentionally casting false to int if no rows were found.
		return (int)$ret;
	}

	/**
	 * @param int $eventId
	 * @return int
	 */
	public function getFullParticipantCountForEvent( int $eventId ): int {
		return $this->getParticipantCountForEvent( $eventId, true );
	}

	/**
	 * @param int $eventId
	 * @return int
	 */
	public function getPrivateParticipantCountForEvent( int $eventId ): int {
		return $this->getParticipantCountForEvent( $eventId, false );
	}

	/**
	 * Returns whether the given user participates in the event. Note that this returns false if the user was
	 * participating but then unregistered.
	 * @param int $eventID
	 * @param CentralUser $user
	 * @param bool $showPrivate
	 * @return bool
	 */
	public function userParticipatesInEvent( int $eventID, CentralUser $user, bool $showPrivate ): bool {
		return $this->getEventParticipant( $eventID, $user, $showPrivate ) !== null;
	}
}
