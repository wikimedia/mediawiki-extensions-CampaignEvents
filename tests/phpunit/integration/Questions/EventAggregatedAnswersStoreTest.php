<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Questions;

use Generator;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\Questions\EventAggregatedAnswers;
use MediaWikiIntegrationTestCase;

/**
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Questions\EventAggregatedAnswersStore
 * @covers ::__construct
 * @group Database
 */
class EventAggregatedAnswersStoreTest extends MediaWikiIntegrationTestCase {
	public function addDBData() {
		$rows = [
			[
				'ceqag_event_id' => 1,
				'ceqag_question_id' => 1,
				'ceqag_answer_option' => 1,
				'ceqag_answers_amount' => 1,
			],
			[
				'ceqag_event_id' => 1,
				'ceqag_question_id' => 1,
				'ceqag_answer_option' => 2,
				'ceqag_answers_amount' => 5,
			],
			[
				'ceqag_event_id' => 1,
				'ceqag_question_id' => 2,
				'ceqag_answer_option' => 1,
				'ceqag_answers_amount' => 1,
			],
			[
				'ceqag_event_id' => 2,
				'ceqag_question_id' => 1,
				'ceqag_answer_option' => 1,
				'ceqag_answers_amount' => 1,
			],
		];
		$this->getDb()->insert( 'ce_question_aggregation', $rows, __METHOD__ );
	}

	/**
	 * @covers ::getEventAggregatedAnswers
	 * @dataProvider provideGetEventAggregatedAnswers
	 */
	public function testGetEventAggregatedAnswers( int $eventID, array $expected ) {
		$store = CampaignEventsServices::getEventAggregatedAnswersStore();
		$eventAggregatedAnswers = $store->getEventAggregatedAnswers( $eventID );
		$this->assertSame( $expected, $eventAggregatedAnswers->getData() );
	}

	public static function provideGetEventAggregatedAnswers(): Generator {
		yield 'No aggregated data' => [ 3, [] ];

		$aggregatedDataOneEntry = new EventAggregatedAnswers();
		$aggregatedDataOneEntry->addEntry( 1, 1, 1 );
		yield 'Single aggregated question and option' => [ 2, $aggregatedDataOneEntry->getData() ];

		$aggregatedDataMultiEntry = new EventAggregatedAnswers();
		$aggregatedDataMultiEntry->addEntry( 1, 1, 1 );
		$aggregatedDataMultiEntry->addEntry( 1, 2, 5 );
		$aggregatedDataMultiEntry->addEntry( 2, 1, 1 );
		yield 'Multi aggregated questions and options' => [ 1, $aggregatedDataMultiEntry->getData() ];
	}

	/**
	 * @covers ::eventHasAggregates
	 */
	public function testEventHasAggregates() {
		$store = CampaignEventsServices::getEventAggregatedAnswersStore();
		$this->assertSame( true, $store->eventHasAggregates( 1 ) );
		$this->assertSame( true, $store->eventHasAggregates( 2 ) );
		$this->assertSame( false, $store->eventHasAggregates( 3 ) );
	}
}
