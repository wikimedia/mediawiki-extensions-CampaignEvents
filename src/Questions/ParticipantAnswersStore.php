<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Questions;

use InvalidArgumentException;
use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;

class ParticipantAnswersStore {
	public const SERVICE_NAME = 'CampaignEventsParticipantAnswersStore';

	public function __construct(
		private readonly CampaignsDatabaseHelper $dbHelper,
	) {
	}

	/**
	 * @param int $eventID
	 * @param CentralUser $participant
	 * @param Answer[] $answers
	 * @return bool Whether any stored answers were modified
	 */
	public function replaceParticipantAnswers( int $eventID, CentralUser $participant, array $answers ): bool {
		$userID = $participant->getCentralID();
		$dbw = $this->dbHelper->getDBConnection( DB_PRIMARY );
		$currentAnswers = $dbw->newSelectQueryBuilder()
			->select( '*' )
			->from( 'ce_question_answers' )
			->where( [
				'ceqa_event_id' => $eventID,
				'ceqa_user_id' => $userID,
			] )
			->caller( __METHOD__ )
			->fetchResultSet();
		$newQuestionIDs = array_map( static fn ( Answer $a ): int => $a->getQuestionDBID(), $answers );
		$currentAnswersByID = [];
		$rowIDsToRemove = [];
		foreach ( $currentAnswers as $row ) {
			$questionID = (int)$row->ceqa_question_id;
			$currentAnswersByID[$questionID] = [
				$row->ceqa_answer_option !== null ? (int)$row->ceqa_answer_option : $row->ceqa_answer_option,
				$row->ceqa_answer_text
			];
			if ( !in_array( $questionID, $newQuestionIDs, true ) ) {
				$rowIDsToRemove[] = $row->ceqa_id;
			}
		}
		if ( $rowIDsToRemove ) {
			$dbw->newDeleteQueryBuilder()
				->deleteFrom( 'ce_question_answers' )
				->where( [ 'ceqa_id' => $rowIDsToRemove ] )
				->caller( __METHOD__ )
				->execute();
		}

		$newRows = [];
		foreach ( $answers as $answer ) {
			$questionID = $answer->getQuestionDBID();
			$option = $answer->getOption();
			$text = $answer->getText();

			$curAnswer = $currentAnswersByID[$questionID] ?? null;
			if ( $curAnswer && $curAnswer[0] === $option && $curAnswer[1] === $text ) {
				// No change.
				continue;
			}

			$newRows[] = [
				'ceqa_event_id' => $eventID,
				'ceqa_user_id' => $userID,
				'ceqa_question_id' => $questionID,
				'ceqa_answer_option' => $answer->getOption(),
				'ceqa_answer_text' => $answer->getText(),
			];
		}

		if ( !$newRows ) {
			return $rowIDsToRemove !== [];
		}

		$dbw->newInsertQueryBuilder()
			->insertInto( 'ce_question_answers' )
			->rows( $newRows )
			->onDuplicateKeyUpdate()
			->uniqueIndexFields( [ 'ceqa_event_id', 'ceqa_user_id', 'ceqa_question_id' ] )
			->set( [
				'ceqa_answer_option = ' . $dbw->buildExcludedValue( 'ceqa_answer_option' ),
				'ceqa_answer_text = ' . $dbw->buildExcludedValue( 'ceqa_answer_text' ),
			] )
			->caller( __METHOD__ )
			->execute();
		return true;
	}

	/**
	 * Deletes all the answers provided by the given users.
	 *
	 * @param int $eventID
	 * @param CentralUser[]|null $participants Must never be empty, pass null to remove answers for all participants.
	 * @param bool $invertSelection Whether the selection of $participants should be inverted, i.e., only answers
	 *  of users not in $participants will be removed. If true, $participants must not be null.
	 */
	public function deleteAllAnswers( int $eventID, ?array $participants, bool $invertSelection = false ): void {
		if ( $participants === null && $invertSelection ) {
			throw new InvalidArgumentException( 'Cannot use $invertSelection when removing all answers' );
		}
		if ( is_array( $participants ) && !$participants ) {
			throw new InvalidArgumentException( '$participants cannot be the empty array' );
		}

		$dbw = $this->dbHelper->getDBConnection( DB_PRIMARY );
		$where = [
			'ceqa_event_id' => $eventID,
		];
		if ( $participants !== null ) {
			$userIDs = array_map( static fn ( CentralUser $u ): int => $u->getCentralID(), $participants );
			if ( $invertSelection ) {
				$where[] = $dbw->expr( 'ceqa_user_id', '!=', $userIDs );
			} else {
				$where['ceqa_user_id'] = $userIDs;
			}
		}
		$dbw->newDeleteQueryBuilder()
			->deleteFrom( 'ce_question_answers' )
			->where( $where )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * @return Answer[]
	 */
	public function getParticipantAnswers( int $eventID, CentralUser $participant ): array {
		$userID = $participant->getCentralID();
		return $this->getParticipantAnswersMulti( $eventID, [ $participant ] )[$userID];
	}

	/**
	 * Returns the answers of multiple participants.
	 *
	 * @param int $eventID
	 * @param CentralUser[] $participants
	 * @return array<int,Answer[]> Keys are user IDs. This is guaranteed to contain an entry for each
	 * user ID in $participants.
	 */
	public function getParticipantAnswersMulti( int $eventID, array $participants ): array {
		if ( !$participants ) {
			return [];
		}
		$participantIDs = array_map( static fn ( CentralUser $u ): int => $u->getCentralID(), $participants );
		$dbr = $this->dbHelper->getDBConnection( DB_REPLICA );
		$res = $dbr->newSelectQueryBuilder()
			->select( '*' )
			->from( 'ce_question_answers' )
			->where( [
				'ceqa_event_id' => $eventID,
				'ceqa_user_id' => $participantIDs
			] )
			->caller( __METHOD__ )
			->fetchResultSet();
		$answersByUser = array_fill_keys( $participantIDs, [] );
		foreach ( $res as $row ) {
			$userID = (int)$row->ceqa_user_id;
			$answersByUser[$userID][] = new Answer(
				(int)$row->ceqa_question_id,
				$row->ceqa_answer_option !== null ? (int)$row->ceqa_answer_option : $row->ceqa_answer_option,
				$row->ceqa_answer_text
			);
		}
		return $answersByUser;
	}

	/**
	 * Returns whether the given event has any answers.
	 */
	public function eventHasAnswers( int $eventID ): bool {
		$dbr = $this->dbHelper->getDBConnection( DB_REPLICA );
		$res = $dbr->newSelectQueryBuilder()
			->select( '1' )
			->from( 'ce_question_answers' )
			->where( [ 'ceqa_event_id' => $eventID ] )
			->caller( __METHOD__ )
			->fetchRow();
		return $res !== false;
	}
}
