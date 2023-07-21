<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Maintenance;

use LogicException;
use Maintenance;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsDatabase;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWDatabaseProxy;
use MWTimestamp;
use RuntimeException;

/**
 * Maintenance script that aggregates and deletes participant answers after a predefined amount of time.
 */
class AggregateParticipantAnswers extends Maintenance {
	private const TTL_SEC = 90 * 24 * 60 * 60;

	private ?ICampaignsDatabase $dbw;
	private ?ICampaignsDatabase $dbr;

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
		$this->output( "Aggregating old participant answers...\n" );

		$this->curTimeUnix = (int)MWTimestamp::now( TS_UNIX );
		$this->cutoffTimeUnix = $this->curTimeUnix - self::TTL_SEC;
		$dbHelper = CampaignEventsServices::getDatabaseHelper();
		$this->dbr = $dbHelper->getDBConnection( DB_REPLICA );
		$dbw = $dbHelper->getDBConnection( DB_PRIMARY );
		// Enforce the MW-specific class so we can use beginTransaction/commitTransaction.
		if ( !$dbw instanceof MWDatabaseProxy ) {
			throw new LogicException( 'Unexpected ICampaignsDatabase implementation.' );
		}
		$this->dbw = $dbw;
		$batchSize = $this->getBatchSize();

		$maxRowID = (int)$this->dbr->selectField( 'ce_question_answers', 'MAX(ceqa_id)' );
		if ( $maxRowID === 0 ) {
			$this->output( "Table is empty.\n" );
			return;
		}
		$minRowID = (int)$this->dbr->selectField( 'ce_question_answers', 'MIN(ceqa_id)' );

		// Wrap all the changes into a transaction to make sure we don't leave incomplete updates behind. This is also
		// needed to avoid edge cases like:
		// - The script fails after aggregating a user's answers, but before updating their cep_aggregation_timestamp
		// - An event's end date is changed while we are aggregating answers
		// Because of the PII nature of participant answers, we want to try and avoid these edge cases as much as
		// possible, especially those that could inadvertently leak PII.
		$transactionName = __METHOD__;
		$this->beginTransaction( $dbw->getMWDatabase(), $transactionName );
		$this->rollbackTransactionFn = function () use ( $dbw, $transactionName ) {
			$this->rollbackTransaction( $dbw->getMWDatabase(), $transactionName );
		};
		$prevID = $minRowID - 1;
		$curID = $prevID + $batchSize;
		do {
			$this->processBatch( $prevID, $curID );
			$prevID = $curID;
			$curID += $batchSize;
			$dbHelper->waitForReplication();
		} while ( $prevID < $maxRowID );

		$this->updateAggregationTimestamps();
		$this->commitTransaction( $dbw->getMWDatabase(), $transactionName );

		$this->output( "Done.\n" );
	}

	private function processBatch( int $startID, int $endID ): void {
		// Lock the rows to prevent further changes.
		$res = $this->dbw->select(
			'ce_question_answers',
			'*',
			[
				'ceqa_id > ' . $startID,
				'ceqa_id <= ' . $endID,
			],
			[ 'FOR UPDATE' ]
		);

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
				$newAggregateTuples[$eventID][$question][$option] ++;

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

			$this->dbw->upsert(
				'ce_question_aggregation',
				$newAggregateRows,
				[ [ 'ceqag_event_id', 'ceqag_question_id', 'ceqag_answer_option' ] ],
				[
					'ceqag_answers_amount = ceqag_answers_amount + ' .
						$this->dbw->buildExcludedValue( 'ceqag_answers_amount' )
				]
			);
		}

		if ( $deleteRowIDs ) {
			$this->dbw->delete( 'ce_question_answers', [ 'ceqa_id' => $deleteRowIDs ] );
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
			$eventRows = $this->dbw->select(
				'campaign_events',
				[ 'event_id', 'event_end_utc' ],
				[ 'event_id' => $eventsWithNoInfo ],
				[ 'FOR UPDATE' ]
			);
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
		$userRows = $this->dbr->select(
			'ce_participants',
			'*',
			[
				'cep_event_id' => array_keys( $missingUsersByEventMap ),
				'cep_user_id' => array_keys( $allMissingUsersMap ),
			]
		);

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
			$this->dbw->update(
				'ce_participants',
				[ 'cep_aggregation_timestamp' => $dbTimestamp ],
				[ 'cep_id' => $idBatch ]
			);
		}
	}
}

return AggregateParticipantAnswers::class;
