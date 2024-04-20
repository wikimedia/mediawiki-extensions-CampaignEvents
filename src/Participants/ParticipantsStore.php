<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Participants;

use IDBAccessObject;
use InvalidArgumentException;
use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\Questions\Answer;
use MediaWiki\Extension\CampaignEvents\Questions\ParticipantAnswersStore;
use Wikimedia\Assert\Assert;
use Wikimedia\Rdbms\IDatabase;

class ParticipantsStore {
	public const SERVICE_NAME = 'CampaignEventsParticipantsStore';

	/**
	 * Bit flags used to describe whether a registration attempt changed anything:
	 *  - NOTHING: no change to the existing information
	 *  - VISIBILITY: the visibility was changed
	 *  - ANSWERS: answers to the participant questions were changed
	 *  - REGISTRATION: the participant was not registred, and they now are. When this flag is set, the
	 *    other ones are irrelevant and may or may not be set; callers should not expect them to (not) be set.
	 */
	public const MODIFIED_NOTHING = 0;
	public const MODIFIED_VISIBILITY = 2 << 0;
	public const MODIFIED_ANSWERS = 2 << 1;
	public const MODIFIED_REGISTRATION = 2 << 10;

	private CampaignsDatabaseHelper $dbHelper;
	private CampaignsCentralUserLookup $centralUserLookup;
	private ParticipantAnswersStore $answersStore;

	/**
	 * @param CampaignsDatabaseHelper $dbHelper
	 * @param CampaignsCentralUserLookup $centralUserLookup
	 * @param ParticipantAnswersStore $answersStore
	 */
	public function __construct(
		CampaignsDatabaseHelper $dbHelper,
		CampaignsCentralUserLookup $centralUserLookup,
		ParticipantAnswersStore $answersStore
	) {
		$this->dbHelper = $dbHelper;
		$this->centralUserLookup = $centralUserLookup;
		$this->answersStore = $answersStore;
	}

	/**
	 * @param int $eventID
	 * @param CentralUser $participant
	 * @param bool $private
	 * @param Answer[] $answers
	 * @return int A combination of the self::MODIFIED_* constants.
	 */
	public function addParticipantToEvent(
		int $eventID,
		CentralUser $participant,
		bool $private,
		array $answers
	): int {
		$dbw = $this->dbHelper->getDBConnection( DB_PRIMARY );

		$userID = $participant->getCentralID();
		$dbw->startAtomic( __METHOD__ );
		$previousRow = $dbw->selectRow(
			'ce_participants',
			'*',
			[
				'cep_event_id' => $eventID,
				'cep_user_id' => $userID
			],
			__METHOD__,
			[ 'FOR UPDATE' ]
		);

		$curTimestamp = $dbw->timestamp();
		$updatedFirstAnsTs = $answers ? $curTimestamp : null;
		if ( !$previousRow ) {
			// User never registered for this event, so we're just adding a new record.
			$dbw->newInsertQueryBuilder()
				->insertInto( 'ce_participants' )
				->row( [
					'cep_event_id' => $eventID,
					'cep_user_id' => $userID,
					'cep_private' => $private,
					'cep_registered_at' => $curTimestamp,
					'cep_unregistered_at' => null,
					'cep_first_answer_timestamp' => $updatedFirstAnsTs,
					'cep_aggregation_timestamp' => null,
				] )
				->caller( __METHOD__ )
				->execute();
			$modified = self::MODIFIED_REGISTRATION;
		} elseif ( $previousRow->cep_unregistered_at !== null ) {
			// User was registered, but then cancelled their registration. Update the visibility, reinstate the
			// registration, and reset the registration time.
			$dbw->newUpdateQueryBuilder()
				->update( 'ce_participants' )
				->set( [
					'cep_private' => $private,
					'cep_unregistered_at' => null,
					'cep_registered_at' => $curTimestamp,
					'cep_first_answer_timestamp' => $updatedFirstAnsTs,
				] )
				->where( [ 'cep_id' => $previousRow->cep_id ] )
				->caller( __METHOD__ )
				->execute();
			$modified = self::MODIFIED_REGISTRATION;
		} elseif ( (bool)$previousRow->cep_private !== $private ) {
			// User is already an active participant, but is changing their visibility. Update that, but not the
			// registration time.
			$dbw->newUpdateQueryBuilder()
				->update( 'ce_participants' )
				->set( [
					'cep_private' => $private,
					'cep_first_answer_timestamp' => $previousRow->cep_first_answer_timestamp ?? $updatedFirstAnsTs,
				] )
				->where( [ 'cep_id' => $previousRow->cep_id ] )
				->caller( __METHOD__ )
				->execute();
			$modified = self::MODIFIED_VISIBILITY;
		} elseif ( $previousRow->cep_first_answer_timestamp === null && $updatedFirstAnsTs !== null ) {
			// Adding answers for the first time
			$dbw->newUpdateQueryBuilder()
				->update( 'ce_participants' )
				->set( [
					'cep_first_answer_timestamp' => $updatedFirstAnsTs,
				] )
				->where( [ 'cep_id' => $previousRow->cep_id ] )
				->caller( __METHOD__ )
				->execute();
			$modified = self::MODIFIED_ANSWERS;
		} else {
			// User is already an active participant with the desired visibility and is not answering for the first
			// time.
			$modified = self::MODIFIED_NOTHING;
		}

		$answersModified = $this->answersStore->replaceParticipantAnswers( $eventID, $participant, $answers );
		if ( $modified !== self::MODIFIED_REGISTRATION && $answersModified ) {
			$modified |= self::MODIFIED_ANSWERS;
		}
		$dbw->endAtomic( __METHOD__ );
		return $modified;
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
				$where[] = $dbw->expr( 'cep_user_id', '!=', $userIDs );
			}
		}

		$dbw->newUpdateQueryBuilder()
			->update( 'ce_participants' )
			->set( [
				'cep_unregistered_at' => $dbw->timestamp(),
				'cep_first_answer_timestamp' => null,
			] )
			->where( $where )
			->caller( __METHOD__ )
			->execute();
		$updatedParticipants = $dbw->affectedRows();
		$this->answersStore->deleteAllAnswers( $eventID, $users, $invertUsers );

		return $updatedParticipants;
	}

	/**
	 * @param int $eventID
	 * @param int|null $limit
	 * @param int|null $lastParticipantID
	 * @param string|null $usernameFilter If not null, only include participants whose username contains the
	 * given string (case-insensitive). Cannot be an empty string.
	 * @param int[]|null $userIdFilter If not null, only include participants whose userids are provided
	 * @param bool $showPrivate
	 * @param int[]|null $excludeUsers IDs of users to exclude from the result (useful when the request user is handled
	 * separately).
	 * @param int $readFlags One of the IDBAccessObject::READ_* constants
	 * @return Participant[]
	 */
	public function getEventParticipants(
		int $eventID,
		int $limit = null,
		int $lastParticipantID = null,
		string $usernameFilter = null,
		array $userIdFilter = null,
		bool $showPrivate = false,
		array $excludeUsers = null,
		int $readFlags = IDBAccessObject::READ_NORMAL
	): array {
		if ( $userIdFilter ) {
			Assert::parameterElementType( 'integer', $userIdFilter, '$userIdFilter' );
		}
		if ( $usernameFilter === '' ) {
			throw new InvalidArgumentException( "The username filter cannot be an empty string" );
		}

		if ( ( $readFlags & IDBAccessObject::READ_LATEST ) === IDBAccessObject::READ_LATEST ) {
			$db = $this->dbHelper->getDBConnection( DB_PRIMARY );
		} else {
			$db = $this->dbHelper->getDBConnection( DB_REPLICA );
		}

		$queryBuilder = $db->newSelectQueryBuilder()
			->select( '*' )
			->from( 'ce_participants' )
			->where( [ 'cep_event_id' => $eventID, 'cep_unregistered_at' => null ] );
		if ( $lastParticipantID !== null ) {
			$queryBuilder->andWhere( 'cep_id > ' . $db->addQuotes( $lastParticipantID ) );
		}
		if ( !$showPrivate ) {
			$queryBuilder->andWhere( [ 'cep_private' => false ] );
		}
		if ( is_array( $userIdFilter ) && $userIdFilter ) {
			$queryBuilder->andWhere( [ 'cep_user_id' => $userIdFilter ] );
		}
		if ( is_array( $excludeUsers ) && $excludeUsers ) {
			$queryBuilder->andWhere(
				'cep_user_id NOT IN (' . $db->makeList( $excludeUsers, IDatabase::LIST_COMMA ) . ')'
			);
		}
		$queryBuilder->orderBy( 'cep_id' )
			->recency( $readFlags );
		// XXX If a username filter is specified, we run an unfiltered query without limit and then filter
		// and limit the results later. This is a bit hacky but there seems to be no super-clean alternative, since
		// we can't join whatever table is used for central users and storing the username is non-trivial due to
		// users being renamed. See T308574 and T312645.
		if ( $limit !== null && $usernameFilter === null ) {
			$queryBuilder->limit( $limit );
		}

		$rows = $queryBuilder->caller( __METHOD__ )->fetchResultSet();

		$centralIDsMap = [];
		$centralUsersByID = [];
		foreach ( $rows as $row ) {
			$userID = (int)$row->cep_user_id;
			$centralIDsMap[$userID] = null;
			$centralUsersByID[$userID] = new CentralUser( $userID );
		}
		$globalNames = $this->centralUserLookup->getNames( $centralIDsMap );
		$answersByUser = $this->answersStore->getParticipantAnswersMulti( $eventID, $centralUsersByID );

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
				$centralUsersByID[$centralID],
				wfTimestamp( TS_UNIX, $row->cep_registered_at ),
				(int)$row->cep_id,
				(bool)$row->cep_private,
				$answersByUser[$centralID],
				wfTimestampOrNull( TS_UNIX, $row->cep_first_answer_timestamp ),
				wfTimestampOrNull( TS_UNIX, $row->cep_aggregation_timestamp )
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
			$conditions,
			__METHOD__
		);

		if ( $row === false ) {
			return null;
		}

		$user = new CentralUser( (int)$row->cep_user_id );
		return new Participant(
			$user,
			wfTimestamp( TS_UNIX, $row->cep_registered_at ),
			(int)$row->cep_id,
			(bool)$row->cep_private,
			$this->answersStore->getParticipantAnswers( $eventID, $user ),
			wfTimestampOrNull( TS_UNIX, $row->cep_first_answer_timestamp ),
			wfTimestampOrNull( TS_UNIX, $row->cep_aggregation_timestamp )
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
			$where,
			__METHOD__
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
