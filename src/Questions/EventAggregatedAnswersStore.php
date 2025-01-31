<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Questions;

use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;

class EventAggregatedAnswersStore {
	public const SERVICE_NAME = 'CampaignEventsEventAggregatedAnswersStore';

	/**
	 * The TTL of raw answers before they are aggregated, in seconds.
	 */
	public const ANSWERS_TTL_SEC = 90 * 24 * 60 * 60;

	private CampaignsDatabaseHelper $dbHelper;

	public function __construct( CampaignsDatabaseHelper $dbHelper ) {
		$this->dbHelper = $dbHelper;
	}

	public function getEventAggregatedAnswers( int $eventID ): EventAggregatedAnswers {
		$dbr = $this->dbHelper->getDBConnection( DB_REPLICA );
		$res = $dbr->newSelectQueryBuilder()
			->select( [ 'ceqag_question_id', 'ceqag_answer_option', 'ceqag_answers_amount' ] )
			->from( 'ce_question_aggregation' )
			->where( [
				'ceqag_event_id' => $eventID
			] )
			->caller( __METHOD__ )
			->fetchResultSet();

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

	public function eventHasAggregates( int $eventID ): bool {
		$dbr = $this->dbHelper->getDBConnection( DB_REPLICA );
		$res = $dbr->newSelectQueryBuilder()
			->select( 'ceqag_id' )
			->from( 'ce_question_aggregation' )
			->where( [ 'ceqag_event_id' => $eventID ] )
			->caller( __METHOD__ )
			->fetchRow();
		return $res !== false;
	}
}
