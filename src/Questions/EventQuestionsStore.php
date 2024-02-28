<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Questions;

use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;

class EventQuestionsStore {
	public const SERVICE_NAME = 'CampaignEventsEventQuestionsStore';

	private CampaignsDatabaseHelper $dbHelper;

	/**
	 * @param CampaignsDatabaseHelper $dbHelper
	 */
	public function __construct( CampaignsDatabaseHelper $dbHelper ) {
		$this->dbHelper = $dbHelper;
	}

	/**
	 * Replaces the list of questions for the given event
	 *
	 * @param int $eventID
	 * @param int[] $questionIDs
	 */
	public function replaceEventQuestions( int $eventID, array $questionIDs ): void {
		$dbw = $this->dbHelper->getDBConnection( DB_PRIMARY );
		$currentQuestions = $dbw->select(
			'ce_event_questions',
			'*',
			[ 'ceeq_event_id' => $eventID ],
			[ 'FOR UPDATE' ]
		);
		$currentQuestionIDs = [];
		$rowIDsToRemove = [];
		foreach ( $currentQuestions as $row ) {
			$questID = (int)$row->ceeq_question_id;
			$currentQuestionIDs[] = $questID;
			if ( !in_array( $questID, $questionIDs, true ) ) {
				$rowIDsToRemove[] = $row->ceeq_id;
			}
		}
		if ( $rowIDsToRemove ) {
			$dbw->delete( 'ce_event_questions', [ 'ceeq_id' => $rowIDsToRemove ] );
		}

		$questionsToAdd = array_diff( $questionIDs, $currentQuestionIDs );
		if ( $questionsToAdd ) {
			$newRows = [];
			foreach ( $questionsToAdd as $questionID ) {
				$newRows[] = [
					'ceeq_event_id' => $eventID,
					'ceeq_question_id' => $questionID
				];
			}
			$dbw->insert( 'ce_event_questions', $newRows );
		}
	}

	/**
	 * Returns the questions enabled for each given event.
	 *
	 * @param int[] $eventIDs
	 * @return array<int,int[]> Map of [ event ID => QuestionsID[] ]. This is guaranteed to have all and only the
	 * elements of $eventIDs as keys.
	 */
	public function getEventQuestionsMulti( array $eventIDs ): array {
		if ( !$eventIDs ) {
			return [];
		}

		$dbr = $this->dbHelper->getDBConnection( DB_REPLICA );
		$res = $dbr->select(
			'ce_event_questions',
			'*',
			[ 'ceeq_event_id' => $eventIDs ]
		);
		$questionsByEvent = array_fill_keys( $eventIDs, [] );
		foreach ( $res as $row ) {
			$eventID = $row->ceeq_event_id;
			$questionsByEvent[$eventID][] = (int)$row->ceeq_question_id;
		}
		return $questionsByEvent;
	}

	/**
	 * @param int $eventID
	 * @return int[]
	 */
	public function getEventQuestions( int $eventID ): array {
		$questionsByEvent = $this->getEventQuestionsMulti( [ $eventID ] );
		return $questionsByEvent[$eventID];
	}
}
