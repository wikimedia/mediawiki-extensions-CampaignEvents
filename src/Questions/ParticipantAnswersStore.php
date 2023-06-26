<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Questions;

use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;

class ParticipantAnswersStore {
	public const SERVICE_NAME = 'CampaignEventsParticipantAnswersStore';

	/** @var CampaignsDatabaseHelper */
	private CampaignsDatabaseHelper $dbHelper;

	/**
	 * @param CampaignsDatabaseHelper $dbHelper
	 */
	public function __construct( CampaignsDatabaseHelper $dbHelper ) {
		$this->dbHelper = $dbHelper;
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
		$currentAnswers = $dbw->select(
			'ce_question_answers',
			'*',
			[
				'ceqa_event_id' => $eventID,
				'ceqa_user_id' => $userID,
			],
			[ 'FOR UPDATE' ]
		);
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
			$dbw->delete( 'ce_question_answers', [ 'ceqa_id' => $rowIDsToRemove ] );
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

		$dbw->upsert(
			'ce_question_answers',
			$newRows,
			[ [ 'ceqa_event_id', 'ceqa_user_id', 'ceqa_question_id' ] ],
			[
				'ceqa_answer_option = ' . $dbw->buildExcludedValue( 'ceqa_answer_option' ),
				'ceqa_answer_text = ' . $dbw->buildExcludedValue( 'ceqa_answer_text' ),
			]
		);
		return true;
	}

	/**
	 * @param int $eventID
	 * @param CentralUser $participant
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
		$participantIDs = array_map( static fn ( CentralUser $u ) => $u->getCentralID(), $participants );
		$dbr = $this->dbHelper->getDBConnection( DB_REPLICA );
		$res = $dbr->select(
			'ce_question_answers',
			'*',
			[
				'ceqa_event_id' => $eventID,
				'ceqa_user_id' => $participantIDs
			]
		);
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
}
