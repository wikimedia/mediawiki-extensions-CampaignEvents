<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Maintenance;

use Maintenance;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\Questions\EventAggregatedAnswersStore;
use MediaWiki\Utils\MWTimestamp;
use RuntimeException;
use Wikimedia\Rdbms\IDatabase;

/**
 * Maintenance script that aggregates and deletes participant answers after a predefined amount of time.
 */
class AggregateParticipantAnswers extends Maintenance {
	private ?IDatabase $dbw;
	private ?IDatabase $dbr;

	private int $curTimeUnix;
	private int $cutoffTimeUnix;

	/**
	 * @var array<int,int> Map of [ event ID => end UNIX timestamp ]
	 */
	private array $eventEndTimes = [];

	/**
	 * Map of users to skip because their answers have already been aggregated in the past.
	 *
	 * @var array<int,array<int,true>> Map of [ event ID => [ user ID => true ] ]
	 */
	private array $usersToSkipPerEvent = [];

	/**
	 * Array of first answer time for each participant. Note that this doesn't include users who should be skipped.
	 *
	 * @var array<int,array<int,int>> Map of [ event ID => [ user ID => first answer UNIX timestamp ] ]
	 */
	private array $userFirstAnswerPerEventTimes = [];

	/**
	 * Map of cep_id values for participant rows that were scanned
	 *
	 * @var array<int,array<int,int>> Map of [ event ID => [ user ID => row ID ] ]
	 */
	private array $participantRowIDsMap = [];

	/**
	 * Map of cep_id values for rows where we need to update the aggregation timestamp.
	 *
	 * @var array<int,true> Map of [ row ID => true ]
	 */
	private array $participantRowsToUpdateMap = [];

	/** @var callable|null */
	private $rollbackTransactionFn;

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Aggregates and deletes participant answers after a predefined amount of time' );
		$this->setBatchSize( 500 );
		$this->requireExtension( 'CampaignEvents' );
	}

	/**
	 * @inheritDoc
	 */
	public function execute(): void {
		$this->aggregateAnswers();
		$this->purgeAggregates();
	}

	/**
	 * Main method that handles the aggregation of individual answers (updates the aggregates and the aggregation
	 * timestamps, and deletes the individual answers).
	 */
	private function aggregateAnswers(): void {
		$this->output( "Aggregating old participant answers...\n" );

		$this->curTimeUnix = (int)MWTimestamp::now( TS_UNIX );
		$this->cutoffTimeUnix = $this->curTimeUnix - EventAggregatedAnswersStore::ANSWERS_TTL_SEC;
		$dbHelper = CampaignEventsServices::getDatabaseHelper();
		$this->dbr = $dbHelper->getDBConnection( DB_REPLICA );
		$this->dbw = $dbHelper->getDBConnection( DB_PRIMARY );
		$batchSize = $this->getBatchSize();

		$maxRowID = (int)$this->dbr->newSelectQueryBuilder()
			->select( 'MAX(ceqa_id)' )
			->from( 'ce_question_answers' )
			->caller( __METHOD__ )
			->fetchField();
		if ( $maxRowID === 0 ) {
			$this->output( "Table is empty.\n" );
			return;
		}
		$minRowID = (int)$this->dbr->newSelectQueryBuilder()
			->select( 'MIN(ceqa_id)' )
			->from( 'ce_question_answers' )
			->caller( __METHOD__ )
			->fetchField();

		// Wrap all the changes into a transaction to make sure we don't leave incomplete updates behind. This is also
		// needed to avoid edge cases like:
		// - The script fails after aggregating a user's answers, but before updating their cep_aggregation_timestamp
		// - An event's end date is changed while we are aggregating answers
		// Because of the PII nature of participant answers, we want to try and avoid these edge cases as much as
		// possible, especially those that could inadvertently leak PII.
		$transactionName = __METHOD__;
		$this->beginTransaction( $this->dbw, $transactionName );
		$this->rollbackTransactionFn = function () use ( $transactionName ) {
			$this->rollbackTransaction( $this->dbw, $transactionName );
		};
		$prevID = $minRowID - 1;
		$curID = $prevID + $batchSize;
		do {
			$this->processBatch( $prevID, $curID );
			$prevID = $curID;
			$curID += $batchSize;
			$this->waitForReplication();
		} while ( $prevID < $maxRowID );

		$this->updateAggregationTimestamps();
		$this->commitTransaction( $this->dbw, $transactionName );

		$this->output( "Done.\n" );
	}

	private function processBatch( int $startID, int $endID ): void {
		// Lock the rows to prevent further changes.
		$res = $this->dbw->newSelectQueryBuilder()
			->select( '*' )
			->from( 'ce_question_answers' )
			->where( [
				$this->dbw->expr( 'ceqa_id', '>', $startID ),
				$this->dbw->expr( 'ceqa_id', '<=', $endID ),
			] )
			->forUpdate()
			->caller( __METHOD__ )
			->fetchResultSet();

		$this->loadDataForBatch( $res );

		$deleteRowIDs = [];
		$newAggregateTuples = [];
		foreach ( $res as $row ) {
			$eventID = (int)$row->ceqa_event_id;
			$userID = (int)$row->ceqa_user_id;

			if ( isset( $this->usersToSkipPerEvent[$eventID][$userID] ) ) {
				// Note, this check should be redundant as long as we delete all user answers,
				// including the non-PII ones.
				continue;
			}

			if (
				$this->eventEndTimes[$eventID] < $this->curTimeUnix ||
				$this->userFirstAnswerPerEventTimes[$eventID][$userID] < $this->cutoffTimeUnix
			) {
				$deleteRowIDs[] = (int)$row->ceqa_id;

				$question = (int)$row->ceqa_question_id;
				$option = (int)$row->ceqa_answer_option;
				$newAggregateTuples[$eventID] ??= [];
				$newAggregateTuples[$eventID][$question] ??= [];
				$newAggregateTuples[$eventID][$question][$option] ??= 0;
				$newAggregateTuples[$eventID][$question][$option]++;

				$participantID = $this->participantRowIDsMap[$eventID][$userID];
				$this->participantRowsToUpdateMap[$participantID] = true;
			}
		}

		if ( $newAggregateTuples ) {
			$newAggregateRows = [];
			foreach ( $newAggregateTuples as $event => $eventData ) {
				foreach ( $eventData as $question => $questionData ) {
					foreach ( $questionData as $option => $amount ) {
						$newAggregateRows[] = [
							'ceqag_event_id' => $event,
							'ceqag_question_id' => $question,
							'ceqag_answer_option' => $option,
							'ceqag_answers_amount' => $amount,
						];
					}
				}
			}

			$this->dbw->newInsertQueryBuilder()
				->insertInto( 'ce_question_aggregation' )
				->rows( $newAggregateRows )
				->onDuplicateKeyUpdate()
				->uniqueIndexFields( [ 'ceqag_event_id', 'ceqag_question_id', 'ceqag_answer_option' ] )
				->set( [
					'ceqag_answers_amount = ceqag_answers_amount + ' .
						$this->dbw->buildExcludedValue( 'ceqag_answers_amount' )
				] )
				->caller( __METHOD__ )
				->execute();
		}

		if ( $deleteRowIDs ) {
			$this->dbw->newDeleteQueryBuilder()
				->deleteFrom( 'ce_question_answers' )
				->where( [ 'ceqa_id' => $deleteRowIDs ] )
				->caller( __METHOD__ )
				->execute();
		}

		$this->output( "Batch $startID-$endID done.\n" );
	}

	/**
	 * Given a batch of answers, loads data that will be needed to process that batch:
	 *  - The end time of each event
	 *  - The time of first answer of each participant in the batch
	 *  - Participants whose answers have already been aggregated
	 *
	 * @param iterable $res
	 * @return void
	 */
	private function loadDataForBatch( iterable $res ): void {
		// Use maps to discard duplicates.
		$eventsMap = [];
		$usersByEventMap = [];
		foreach ( $res as $row ) {
			$eventID = (int)$row->ceqa_event_id;
			$eventsMap[$eventID] = true;
			$usersByEventMap[$eventID] ??= [];
			$userID = (int)$row->ceqa_user_id;
			$usersByEventMap[$eventID][$userID] = true;
		}

		$eventsWithNoInfo = array_keys( array_diff_key( $eventsMap, $this->eventEndTimes ) );
		if ( $eventsWithNoInfo ) {
			// Lock the event rows as well, to prevent changes while we aggregate the answers.
			$eventRows = $this->dbw->newSelectQueryBuilder()
				->select( [ 'event_id', 'event_end_utc' ] )
				->from( 'campaign_events' )
				->where( [ 'event_id' => $eventsWithNoInfo ] )
				->forUpdate()
				->caller( __METHOD__ )
				->fetchResultSet();
			foreach ( $eventRows as $row ) {
				$eventID = (int)$row->event_id;
				$this->eventEndTimes[$eventID] = (int)wfTimestamp( TS_UNIX, $row->event_end_utc );
			}
		}

		$missingUsersByEventMap = [];
		$allMissingUsersMap = [];
		foreach ( $usersByEventMap as $eventID => $eventUsersMap ) {
			$usersToProcess = array_diff_key( $eventUsersMap, $this->usersToSkipPerEvent[$eventID] ?? [] );
			$usersToProcess = array_diff_key(
				$usersToProcess,
				$this->userFirstAnswerPerEventTimes[$eventID] ?? []
			);
			if ( $usersToProcess ) {
				$missingUsersByEventMap[$eventID] = $usersToProcess;
				$allMissingUsersMap += $usersToProcess;
			}
		}

		if ( !$missingUsersByEventMap ) {
			return;
		}
		// Note that this query includes more rows than the ones we're looking for, because we're not filtering
		// by tuples in the where condition. Results are filtered later.
		// No need to obtain these from the primary DB, because concurrent changes to a record should not really
		// affect the script execution.
		$userRows = $this->dbr->newSelectQueryBuilder()
			->select( '*' )
			->from( 'ce_participants' )
			->where( [
				'cep_event_id' => array_keys( $missingUsersByEventMap ),
				'cep_user_id' => array_keys( $allMissingUsersMap ),
			] )
			->caller( __METHOD__ )
			->fetchResultSet();

		foreach ( $userRows as $row ) {
			$eventID = (int)$row->cep_event_id;
			$userID = (int)$row->cep_user_id;
			if ( !isset( $missingUsersByEventMap[$eventID][$userID] ) ) {
				// Unwanted row, ignore it.
				continue;
			}
			if ( $row->cep_aggregation_timestamp !== null ) {
				$this->usersToSkipPerEvent[$eventID][$userID] = true;
				continue;
			}
			$firstAnswerTS = wfTimestampOrNull( TS_UNIX, $row->cep_first_answer_timestamp );
			if ( !is_string( $firstAnswerTS ) ) {
				( $this->rollbackTransactionFn )();
				throw new RuntimeException(
					"User with answers but no first answer time (event=$eventID, user=$userID)"
				);
			}
			$this->userFirstAnswerPerEventTimes[$eventID][$userID] = (int)$firstAnswerTS;
			$this->participantRowIDsMap[$eventID][$userID] = (int)$row->cep_id;
		}
	}

	/**
	 * Updates the aggregation timestamp for all users processed thus far.
	 * Note, this needs to be done after all the rows have been processed, to make sure that a non-null
	 * cep_aggregation_timestamp doesn't cause the user to be skipped in subsequent batches.
	 */
	private function updateAggregationTimestamps(): void {
		$this->output( "Updating aggregation timestamps...\n" );
		$participantRowsToUpdate = array_keys( $this->participantRowsToUpdateMap );
		$dbTimestamp = $this->dbw->timestamp( $this->curTimeUnix );
		foreach ( array_chunk( $participantRowsToUpdate, $this->getBatchSize() ) as $idBatch ) {
			$this->dbw->newUpdateQueryBuilder()
				->update( 'ce_participants' )
				->set( [ 'cep_aggregation_timestamp' => $dbTimestamp ] )
				->where( [ 'cep_id' => $idBatch ] )
				->caller( __METHOD__ )
				->execute();
		}
	}

	/**
	 * Purge redundant aggregated data: for each event that has ended, delete any aggregated answers to questions that
	 * are no longer enabled for that event.
	 * Note that the aggregation itself ({@see self::processBatch()}) uses `ce_question_answers` as its data source,
	 * and does not make any distinction between old answers and finished events; therefore, it cannot handle the
	 * deletion of redundant aggregates as part of its execution: if a certain event has redundant aggregates but
	 * nothing to aggregate, it won't be processed at all.
	 * Also, note that the DB schema does not store whether the "final" aggregation (i.e., the first aggregation after
	 * the event end date) has occurred for a given event. Therefore, we need to check all the events here.
	 */
	private function purgeAggregates(): void {
		$this->output( "Purging old aggregated data...\n" );

		$maxEventID = (int)$this->dbr->newSelectQueryBuilder()
			->select( 'MAX(event_id)' )
			->from( 'campaign_events' )
			->caller( __METHOD__ )
			->fetchField();
		if ( $maxEventID === 0 ) {
			$this->output( "No events.\n" );
			return;
		}

		$batchSize = $this->getBatchSize();
		$startID = 0;
		$endID = $batchSize;
		$curDBTimestamp = $this->dbr->timestamp( $this->curTimeUnix );
		do {
			// Note, we may already have partial data in $this->eventEndTimes. However, since it's partial, we'll need
			// to query the DB again. Excluding events for which we already have data is probably useless, as it would
			// introduce complexity and give little to nothing in return.
			$eventsToCheck = $this->dbr->newSelectQueryBuilder()
				->select( 'event_id' )
				->from( 'campaign_events' )
				->where( [
					$this->dbr->expr( 'event_id', '>', $startID ),
					$this->dbr->expr( 'event_id', '<=', $endID ),
					$this->dbr->expr( 'event_end_utc', '<', $curDBTimestamp ),
				] )
				->caller( __METHOD__ )
				->fetchFieldValues();
			if ( $eventsToCheck ) {
				$eventsToCheck = array_map( 'intval', $eventsToCheck );
				$this->purgeAggregatesForEvents( $eventsToCheck );
			}

			$startID = $endID;
			$endID += $batchSize;
		} while ( $startID < $maxEventID );

		$this->output( "Done.\n" );
	}

	/**
	 * @param int[] $eventIDs
	 */
	private function purgeAggregatesForEvents( array $eventIDs ): void {
		$eventQuestionRows = $this->dbr->newSelectQueryBuilder()
			->select( [ 'ceeq_event_id', 'ceeq_question_id' ] )
			->from( 'ce_event_questions' )
			->where( [
				'ceeq_event_id' => $eventIDs
			] )
			->caller( __METHOD__ )
			->fetchResultSet();
		$questionsByEvent = array_fill_keys( $eventIDs, [] );
		foreach ( $eventQuestionRows as $eventQuestionRow ) {
			$eventID = (int)$eventQuestionRow->ceeq_event_id;
			$questionsByEvent[$eventID][] = (int)$eventQuestionRow->ceeq_question_id;
		}

		$aggregatedAnswersRows = $this->dbr->newSelectQueryBuilder()
			->select( [ 'ceqag_id', 'ceqag_event_id', 'ceqag_question_id' ] )
			->from( 'ce_question_aggregation' )
			->where( [
				'ceqag_event_id' => $eventIDs
			] )
			->caller( __METHOD__ )
			->fetchResultSet();
		$deleteRowIDs = [];
		foreach ( $aggregatedAnswersRows as $aggregatedAnswersRow ) {
			$eventID = (int)$aggregatedAnswersRow->ceqag_event_id;
			$questionID = (int)$aggregatedAnswersRow->ceqag_question_id;
			if ( !in_array( $questionID, $questionsByEvent[$eventID], true ) ) {
				$deleteRowIDs[] = (int)$aggregatedAnswersRow->ceqag_id;
			}
		}

		if ( $deleteRowIDs ) {
			$this->dbw->newDeleteQueryBuilder()
				->deleteFrom( 'ce_question_aggregation' )
				->where( [ 'ceqag_id' => $deleteRowIDs ] )
				->caller( __METHOD__ )
				->execute();
		}
	}
}

return AggregateParticipantAnswers::class;
