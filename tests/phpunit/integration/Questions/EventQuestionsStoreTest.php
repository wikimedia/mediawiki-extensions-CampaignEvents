<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Questions;

use Generator;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWikiIntegrationTestCase;

/**
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Questions\EventQuestionsStore
 * @covers ::__construct
 * @group Database
 */
class EventQuestionsStoreTest extends MediaWikiIntegrationTestCase {
	/** @inheritDoc */
	protected $tablesUsed = [ 'ce_event_questions' ];

	public function addDBData() {
		$rows = [
			[
				'ceeq_event_id' => 1,
				'ceeq_question_id' => 1,
			],
			[
				'ceeq_event_id' => 1,
				'ceeq_question_id' => 2,
			],
			[
				'ceeq_event_id' => 2,
				'ceeq_question_id' => 1,
			],
		];
		$this->getDb()->insert( 'ce_event_questions', $rows, __METHOD__ );
	}

	/**
	 * @covers ::replaceEventQuestions
	 * @dataProvider provideReplaceEventQuestions
	 */
	public function testReplaceEventQuestions( int $eventID, array $questionIDs ) {
		$store = CampaignEventsServices::getEventQuestionsStore();
		$store->replaceEventQuestions( $eventID, $questionIDs );
		$newIDs = $store->getEventQuestions( $eventID );
		$this->assertSame( $questionIDs, $newIDs );
	}

	public static function provideReplaceEventQuestions(): Generator {
		yield 'No change' => [ 1, [ 1, 2 ] ];
		yield 'Add one question' => [ 1, [ 1, 2, 3 ] ];
		yield 'Remove one question' => [ 1, [ 1 ] ];
		yield 'Remove all questions' => [ 1, [] ];
		yield 'Remove one, add one' => [ 1, [ 1, 3 ] ];
		yield 'Remove all, add one' => [ 1, [ 3 ] ];
	}

	/**
	 * @covers ::getEventQuestionsMulti
	 * @dataProvider provideGetEventQuestionsMulti
	 */
	public function testGetEventQuestionsMulti( array $eventIDs, array $expected ) {
		$store = CampaignEventsServices::getEventQuestionsStore();
		$this->assertSame( $expected, $store->getEventQuestionsMulti( $eventIDs ) );
	}

	public static function provideGetEventQuestionsMulti(): Generator {
		yield 'No events given' => [ [], [] ];
		yield 'Single event' => [ [ 1 ], [ 1 => [ 1, 2 ] ] ];
		yield 'Multiple events' => [ [ 1, 2 ], [ 1 => [ 1, 2 ], 2 => [ 1 ] ] ];
		yield 'Includes event with no questions' => [ [ 1, 1000 ], [ 1 => [ 1, 2 ], 1000 => [] ] ];
	}

	/**
	 * @covers ::getEventQuestions
	 */
	public function testGetEventQuestions() {
		$store = CampaignEventsServices::getEventQuestionsStore();
		$this->assertSame( [ 1, 2 ], $store->getEventQuestions( 1 ) );
	}
}
