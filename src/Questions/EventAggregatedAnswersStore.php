<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Questions;

use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;

class EventAggregatedAnswersStore {
	public const SERVICE_NAME = 'CampaignEventsEventAggregatedAnswersStore';

	/** @var CampaignsDatabaseHelper */
	private CampaignsDatabaseHelper $dbHelper;

	/**
	 * @param CampaignsDatabaseHelper $dbHelper
	 */
	public function __construct( CampaignsDatabaseHelper $dbHelper ) {
		$this->dbHelper = $dbHelper;
	}

	/**
	 * Returns the aggregated data.
	 *
	 * @param int $eventID
	 * @return EventAggregatedAnswers
	 */
	public function getEventAggregatedAnswers( int $eventID ): EventAggregatedAnswers {
		$dbr = $this->dbHelper->getDBConnection( DB_REPLICA );
		$res = $dbr->select(
			'ce_question_aggregation',
			[ 'ceqag_question_id', 'ceqag_answer_option', 'ceqag_answers_amount' ],
			[
				'ceqag_event_id' => $eventID
			],
		);

		$eventAggregatedAnswers = new EventAggregatedAnswers();
		foreach ( $res as $row ) {
			$eventAggregatedAnswers->addEntry(
				(int)$row->ceqag_question_id,
				(int)$row->ceqag_answer_option,
				(int)$row->ceqag_answers_amount
			);
		}

		return $eventAggregatedAnswers;
	}

	/**
	 * @param int $eventID
	 * @return bool
	 */
	public function eventHasAggregates( int $eventID ): bool {
		$dbr = $this->dbHelper->getDBConnection( DB_REPLICA );
		$res = $dbr->selectRow(
			'ce_question_aggregation',
			'ceqag_id',
			[ 'ceqag_event_id' => $eventID ],
			[ 'LIMIT' => 1 ]
		);
		return $res !== null;
	}
}
