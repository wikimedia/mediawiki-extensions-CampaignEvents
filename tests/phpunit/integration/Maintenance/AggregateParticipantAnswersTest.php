<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Maintenance;

use MediaWiki\Extension\CampaignEvents\Maintenance\AggregateParticipantAnswers;
use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @group Test
 * @group Database
 * @covers \MediaWiki\Extension\CampaignEvents\Maintenance\AggregateParticipantAnswers
 */
class AggregateParticipantAnswersTest extends MaintenanceBaseTestCase {
	private const TIME_NOW = 1680000000;
	private const PREVIOUS_AGGREGATION_TIME = self::TIME_NOW - 24 * 60 * 60;

	protected function setUp(): void {
		parent::setUp();
		ConvertibleTimestamp::setFakeTime( self::TIME_NOW );
	}

	/**
	 * @inheritDoc
	 */
	protected function getMaintenanceClass(): string {
		return AggregateParticipantAnswers::class;
	}

	/**
	 * @inheritDoc
	 */
	public function addDBData() {
		$dbw = $this->getDb();
		$cutoffTS = self::TIME_NOW - 90 * 24 * 60 * 60;
		$tsBeforeCutoff = $dbw->timestamp( $cutoffTS - 1 );
		$tsAfterCutoff = $dbw->timestamp( $cutoffTS + 1 );
		$prevAggregationTS = $dbw->timestamp( self::PREVIOUS_AGGREGATION_TIME );
		$endedEventTS = $prevAggregationTS;
		$futureEventTS = $dbw->timestamp( self::TIME_NOW + 24 * 60 * 60 );

		$answerRows = [
			// Event 1, user 1
			[
				'ceqa_event_id' => 1,
				'ceqa_user_id' => 1,
				'ceqa_question_id' => 1,
				'ceqa_answer_option' => 1,
				'ceqa_answer_text' => null,
			],
			[
				'ceqa_event_id' => 1,
				'ceqa_user_id' => 1,
				'ceqa_question_id' => 2,
				'ceqa_answer_option' => 1,
				'ceqa_answer_text' => 'e1u1q2',
			],
			[
				'ceqa_event_id' => 1,
				'ceqa_user_id' => 1,
				'ceqa_question_id' => 3,
				'ceqa_answer_option' => 2,
				'ceqa_answer_text' => 'e1u1q3',
			],
			// Event 1, user 2
			[
				'ceqa_event_id' => 1,
				'ceqa_user_id' => 2,
				'ceqa_question_id' => 1,
				'ceqa_answer_option' => 1,
				'ceqa_answer_text' => null,
			],
			[
				'ceqa_event_id' => 1,
				'ceqa_user_id' => 2,
				'ceqa_question_id' => 3,
				'ceqa_answer_option' => 2,
				'ceqa_answer_text' => null,
			],
			// Event 1, user 3
			[
				'ceqa_event_id' => 1,
				'ceqa_user_id' => 3,
				'ceqa_question_id' => 1,
				'ceqa_answer_option' => 2,
				'ceqa_answer_text' => null,
			],
			[
				'ceqa_event_id' => 1,
				'ceqa_user_id' => 3,
				'ceqa_question_id' => 3,
				'ceqa_answer_option' => 1,
				'ceqa_answer_text' => 'e1u3q3',
			],
			// Event 1, user 4
			[
				'ceqa_event_id' => 1,
				'ceqa_user_id' => 4,
				'ceqa_question_id' => 5,
				'ceqa_answer_option' => 3,
				'ceqa_answer_text' => null,
			],
			// Event 2, user 1
			[
				'ceqa_event_id' => 2,
				'ceqa_user_id' => 1,
				'ceqa_question_id' => 1,
				'ceqa_answer_option' => 1,
				'ceqa_answer_text' => null,
			],
			[
				'ceqa_event_id' => 2,
				'ceqa_user_id' => 1,
				'ceqa_question_id' => 2,
				'ceqa_answer_option' => 3,
				'ceqa_answer_text' => 'e2u1q2',
			],
			[
				'ceqa_event_id' => 2,
				'ceqa_user_id' => 1,
				'ceqa_question_id' => 3,
				'ceqa_answer_option' => 1,
				'ceqa_answer_text' => null,
			],
			// Event 2, user 4
			[
				'ceqa_event_id' => 2,
				'ceqa_user_id' => 4,
				'ceqa_question_id' => 1,
				'ceqa_answer_option' => 1,
				'ceqa_answer_text' => null,
			],
			[
				'ceqa_event_id' => 2,
				'ceqa_user_id' => 4,
				'ceqa_question_id' => 3,
				'ceqa_answer_option' => 1,
				'ceqa_answer_text' => 'e2u4q3',
			],
			// Event 3, user 2
			[
				'ceqa_event_id' => 3,
				'ceqa_user_id' => 2,
				'ceqa_question_id' => 1,
				'ceqa_answer_option' => 1,
				'ceqa_answer_text' => null,
			],
			[
				'ceqa_event_id' => 3,
				'ceqa_user_id' => 2,
				'ceqa_question_id' => 2,
				'ceqa_answer_option' => 4,
				'ceqa_answer_text' => 'e3u2q2',
			],
			// Event 3, user 3
			[
				'ceqa_event_id' => 3,
				'ceqa_user_id' => 3,
				'ceqa_question_id' => 1,
				'ceqa_answer_option' => 2,
				'ceqa_answer_text' => null,
			],
			[
				'ceqa_event_id' => 3,
				'ceqa_user_id' => 3,
				'ceqa_question_id' => 2,
				'ceqa_answer_option' => 4,
				'ceqa_answer_text' => 'e3u3q2',
			],
			// Event 4, user 1
			[
				'ceqa_event_id' => 4,
				'ceqa_user_id' => 1,
				'ceqa_question_id' => 1,
				'ceqa_answer_option' => 1,
				'ceqa_answer_text' => null,
			],
			[
				'ceqa_event_id' => 4,
				'ceqa_user_id' => 1,
				'ceqa_question_id' => 2,
				'ceqa_answer_option' => 2,
				'ceqa_answer_text' => null,
			],
			// Event 4, user 3
			[
				'ceqa_event_id' => 4,
				'ceqa_user_id' => 3,
				'ceqa_question_id' => 2,
				'ceqa_answer_option' => 3,
				'ceqa_answer_text' => 'e4u3q2',
			],
			// Event 5, user 1 (removed questions)
			[
				'ceqa_event_id' => 5,
				'ceqa_user_id' => 1,
				'ceqa_question_id' => 1,
				'ceqa_answer_option' => 1,
				'ceqa_answer_text' => null,
			],
			[
				'ceqa_event_id' => 5,
				'ceqa_user_id' => 1,
				'ceqa_question_id' => 2,
				'ceqa_answer_option' => 2,
				'ceqa_answer_text' => null,
			],
		];
		$dbw->insert( 'ce_question_answers', $answerRows );

		$baseParticipantRow = [
			'cep_private' => false,
			'cep_registered_at' => $dbw->timestamp( '20220315120000' ),
			'cep_unregistered_at' => null,
			'cep_aggregation_timestamp' => null,
		];
		$participantRows = [
			// Event 1
			[
				'cep_event_id' => 1,
				'cep_user_id' => 1,
				'cep_first_answer_timestamp' => $tsBeforeCutoff,
			] + $baseParticipantRow,
			[
				'cep_event_id' => 1,
				'cep_user_id' => 2,
				'cep_first_answer_timestamp' => $tsAfterCutoff,
			] + $baseParticipantRow,
			[
				'cep_event_id' => 1,
				'cep_user_id' => 3,
				'cep_first_answer_timestamp' => $tsBeforeCutoff,
			] + $baseParticipantRow,
			[
				'cep_event_id' => 1,
				'cep_user_id' => 4,
				'cep_first_answer_timestamp' => $tsBeforeCutoff,
			] + $baseParticipantRow,
			[
				'cep_event_id' => 1,
				'cep_user_id' => 10,
				'cep_first_answer_timestamp' => $tsBeforeCutoff,
				// User whose answers have already been aggregated
			] + array_replace( $baseParticipantRow, [ 'cep_aggregation_timestamp' => $prevAggregationTS ] ),
			// Event 2
			[
				'cep_event_id' => 2,
				'cep_user_id' => 1,
				'cep_first_answer_timestamp' => $tsAfterCutoff,
			] + $baseParticipantRow,
			[
				'cep_event_id' => 2,
				'cep_user_id' => 4,
				'cep_first_answer_timestamp' => $tsAfterCutoff,
			] + $baseParticipantRow,
			// Event 3
			[
				'cep_event_id' => 3,
				'cep_user_id' => 2,
				'cep_first_answer_timestamp' => $tsBeforeCutoff,
			] + $baseParticipantRow,
			[
				'cep_event_id' => 3,
				'cep_user_id' => 3,
				'cep_first_answer_timestamp' => $tsBeforeCutoff,
			] + $baseParticipantRow,
			// Event 4
			[
				'cep_event_id' => 4,
				'cep_user_id' => 1,
				'cep_first_answer_timestamp' => $tsBeforeCutoff,
			] + $baseParticipantRow,
			[
				'cep_event_id' => 4,
				'cep_user_id' => 2,
				// Note, user 2 has no answers for event 4
				'cep_first_answer_timestamp' => null,
			] + $baseParticipantRow,
			[
				'cep_event_id' => 4,
				'cep_user_id' => 3,
				'cep_first_answer_timestamp' => $tsAfterCutoff,
			] + $baseParticipantRow,
			// Event 5
			[
				'cep_event_id' => 5,
				'cep_user_id' => 1,
				'cep_first_answer_timestamp' => $tsAfterCutoff,
			] + $baseParticipantRow,
		];
		$dbw->insert( 'ce_participants', $participantRows );

		$makeEventRow = static function ( array $data ) use ( $dbw ): array {
			$eventName = wfRandomString();
			$randomTS = $dbw->timestamp( '20230101000000' );
			$baseData = [
				'event_name' => $eventName,
				'event_page_namespace' => 1728,
				'event_page_title' => $eventName,
				'event_page_prefixedtext' => $eventName,
				'event_page_wiki' => 'local_wiki',
				'event_chat_url' => '',
				'event_status' => 1,
				'event_timezone' => 'UTC',
				'event_start_local' => $randomTS,
				'event_start_utc' => $randomTS,
				'event_end_local' => $randomTS,
				'event_type' => 'generic',
				'event_meeting_type' => 3,
				'event_meeting_url' => '',
				'event_created_at' => $randomTS,
				'event_last_edit' => $randomTS,
				'event_deleted_at' => null,
			];
			return $data + $baseData;
		};
		$eventRows = [
			$makeEventRow( [
				'event_id' => 1,
				'event_end_utc' => $endedEventTS,
			] ),
			$makeEventRow( [
				'event_id' => 2,
				'event_end_utc' => $futureEventTS,
			] ),
			$makeEventRow( [
				'event_id' => 3,
				'event_end_utc' => $endedEventTS,
			] ),
			$makeEventRow( [
				'event_id' => 4,
				'event_end_utc' => $futureEventTS,
			] ),
			$makeEventRow( [
				'event_id' => 5,
				'event_end_utc' => $endedEventTS,
			] ),
		];
		$dbw->insert( 'campaign_events', $eventRows );

		$previousAggregateRows = [
			// Partial aggregates for event 1
			[
				'ceqag_event_id' => 1,
				'ceqag_question_id' => 1,
				'ceqag_answer_option' => 1,
				'ceqag_answers_amount' => 2,
			],
			// Old aggregates for event 5
			[
				'ceqag_event_id' => 5,
				'ceqag_question_id' => 1,
				'ceqag_answer_option' => 1,
				'ceqag_answers_amount' => 2,
			],
		];
		$dbw->insert( 'ce_question_aggregation', $previousAggregateRows );

		$eventQuestionRows = [];
		// Event 5 intentionally has no questions enabled.
		foreach ( [ 1, 2, 3, 4 ] as $event ) {
			foreach ( range( 1, 5 ) as $question ) {
				$eventQuestionRows[] = [
					'ceeq_event_id' => $event,
					'ceeq_question_id' => $question
				];
			}
		}
		$dbw->insert( 'ce_event_questions', $eventQuestionRows );
	}

	public function testExecute() {
		$this->maintenance->execute();
		$dbr = $this->getDb();

		// Map of [ event => [ user => [ questions ] ] ]
		$expectedRemainingQuestionTuples = [
			2 => [
				1 => [ 1, 2, 3 ],
				4 => [ 1, 3 ],
			],
			4 => [
				3 => [ 2 ],
			],
		];
		$actualQuestionRows = $dbr->select( 'ce_question_answers', '*' );
		$actualQuestionTuples = [];
		foreach ( $actualQuestionRows as $row ) {
			$eventID = $row->ceqa_event_id;
			$userID = $row->ceqa_user_id;
			$actualQuestionTuples[$eventID] ??= [];
			$actualQuestionTuples[$eventID][$userID] ??= [];
			$actualQuestionTuples[$eventID][$userID][] = (int)$row->ceqa_question_id;
		}
		$this->assertSame(
			$expectedRemainingQuestionTuples,
			$actualQuestionTuples,
			'Remaining question tuples'
		);

		// Map of [ event => [ question => [ option => amount ] ] ]
		$expectedAggregates = [
			1 => [
				1 => [
					1 => 4,
					2 => 1,
				],
				2 => [
					1 => 1,
				],
				3 => [
					2 => 2,
					1 => 1,
				],
				5 => [
					3 => 1,
				],
			],
			3 => [
				1 => [
					1 => 1,
					2 => 1,
				],
				2 => [
					4 => 2,
				],
			],
			4 => [
				1 => [
					1 => 1,
				],
				2 => [
					2 => 1,
				],
			],
			// Event 5 has no questions, and therefore should have no aggregates
		];

		$aggregateRows = $dbr->select( 'ce_question_aggregation', '*' );
		$actualAggregates = [];
		foreach ( $aggregateRows as $row ) {
			$event = $row->ceqag_event_id;
			$question = $row->ceqag_question_id;
			$option = $row->ceqag_answer_option;
			$actualAggregates[$event] ??= [];
			$actualAggregates[$event][$question] ??= [];
			$actualAggregates[$event][$question][$option] = (int)$row->ceqag_answers_amount;
		}
		$this->assertSame(
			$expectedAggregates,
			$actualAggregates,
			'Aggregated answers'
		);

		$prevAggregationTS = $dbr->timestamp( self::PREVIOUS_AGGREGATION_TIME );
		$justAggregatedTS = $dbr->timestamp( self::TIME_NOW );
		// Map of [ event => [ user => time ] ]
		$expectedParticipantAggregationTimes = [
			1 => [
				1 => $justAggregatedTS,
				2 => $justAggregatedTS,
				3 => $justAggregatedTS,
				4 => $justAggregatedTS,
				10 => $prevAggregationTS,
			],
			2 => [
				1 => null,
				4 => null,
			],
			3 => [
				2 => $justAggregatedTS,
				3 => $justAggregatedTS,
			],
			4 => [
				1 => $justAggregatedTS,
				2 => null,
				3 => null,
			],
			5 => [
				1 => $justAggregatedTS,
			],
		];
		$participantRows = $dbr->select( 'ce_participants', '*' );
		$actualParticipantAggregationTimes = [];
		foreach ( $participantRows as $row ) {
			$event = $row->cep_event_id;
			$user = $row->cep_user_id;
			$actualParticipantAggregationTimes[$event] ??= [];
			$actualParticipantAggregationTimes[$event][$user] = $row->cep_aggregation_timestamp;
		}
		$this->assertSame(
			$expectedParticipantAggregationTimes,
			$actualParticipantAggregationTimes,
			'Aggregation timestamps'
		);
	}
}
