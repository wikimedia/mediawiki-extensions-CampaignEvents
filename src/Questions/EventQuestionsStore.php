<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Questions;

use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;

class EventQuestionsStore {
	public const SERVICE_NAME = 'CampaignEventsEventQuestionsStore';

	private CampaignsDatabaseHelper $dbHelper;

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
		$currentQuestions = $dbw->newSelectQueryBuilder()
			->select( '*' )
			->from( 'ce_event_questions' )
			->where( [ 'ceeq_event_id' => $eventID ] )
			->caller( __METHOD__ )
			->fetchResultSet();
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
			$dbw->newDeleteQueryBuilder()
				->deleteFrom( 'ce_event_questions' )
				->where( [ 'ceeq_id' => $rowIDsToRemove ] )
				->caller( __METHOD__ )
				->execute();
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
			$dbw->newInsertQueryBuilder()
				->insertInto( 'ce_event_questions' )
				->rows( $newRows )
				->caller( __METHOD__ )
				->execute();
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
		$res = $dbr->newSelectQueryBuilder()
			->select( '*' )
			->from( 'ce_event_questions' )
			->where( [ 'ceeq_event_id' => $eventIDs ] )
			->caller( __METHOD__ )
			->fetchResultSet();
		$questionsByEvent = array_fill_keys( $eventIDs, [] );
		foreach ( $res as $row ) {
			$eventID = $row->ceeq_event_id;
			$questionsByEvent[$eventID][] = (int)$row->ceeq_question_id;
		}
		return $questionsByEvent;
	}

	/**
	 * @return int[]
	 */
	public function getEventQuestions( int $eventID ): array {
		$questionsByEvent = $this->getEventQuestionsMulti( [ $eventID ] );
		return $questionsByEvent[$eventID];
	}
}
