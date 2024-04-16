<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Questions;

use Generator;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\Questions\Answer;
use MediaWikiIntegrationTestCase;

/**
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Questions\ParticipantAnswersStore
 * @covers ::__construct
 * @group Database
 */
class ParticipantAnswersStoreTest extends MediaWikiIntegrationTestCase {
	public function addDBData() {
		$rows = [
			[
				'ceqa_event_id' => 1,
				'ceqa_user_id' => 101,
				'ceqa_question_id' => 1,
				'ceqa_answer_option' => 1,
				'ceqa_answer_text' => null,
			],
			[
				'ceqa_event_id' => 1,
				'ceqa_user_id' => 101,
				'ceqa_question_id' => 2,
				'ceqa_answer_option' => 1,
				'ceqa_answer_text' => null,
			],
			[
				'ceqa_event_id' => 1,
				'ceqa_user_id' => 101,
				'ceqa_question_id' => 3,
				'ceqa_answer_option' => 2,
				'ceqa_answer_text' => 'foo',
			],
			[
				'ceqa_event_id' => 1,
				'ceqa_user_id' => 101,
				'ceqa_question_id' => 4,
				'ceqa_answer_option' => null,
				'ceqa_answer_text' => 'bar',
			],
			[
				'ceqa_event_id' => 1,
				'ceqa_user_id' => 102,
				'ceqa_question_id' => 2,
				'ceqa_answer_option' => 1,
				'ceqa_answer_text' => null,
			],
			[
				'ceqa_event_id' => 1,
				'ceqa_user_id' => 102,
				'ceqa_question_id' => 3,
				'ceqa_answer_option' => 3,
				'ceqa_answer_text' => 'baz',
			],
			[
				'ceqa_event_id' => 2,
				'ceqa_user_id' => 101,
				'ceqa_question_id' => 1,
				'ceqa_answer_option' => 1,
				'ceqa_answer_text' => null,
			],
			[
				'ceqa_event_id' => 2,
				'ceqa_user_id' => 101,
				'ceqa_question_id' => 4,
				'ceqa_answer_option' => null,
				'ceqa_answer_text' => 'quux',
			],
			[
				'ceqa_event_id' => 2,
				'ceqa_user_id' => 103,
				'ceqa_question_id' => 1,
				'ceqa_answer_option' => 3,
				'ceqa_answer_text' => null,
			],
			[
				'ceqa_event_id' => 2,
				'ceqa_user_id' => 104,
				'ceqa_question_id' => 5,
				'ceqa_answer_option' => 1,
				'ceqa_answer_text' => null,
			],
		];
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'ce_question_answers' )
			->rows( $rows )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * @covers ::replaceParticipantAnswers
	 * @covers ::getParticipantAnswers
	 * @dataProvider provideReplaceParticipantAnswers
	 */
	public function testReplaceParticipantAnswers(
		int $eventID,
		int $userID,
		array $answers,
		bool $expectChange = true
	) {
		$user = new CentralUser( $userID );
		$store = CampaignEventsServices::getParticipantAnswersStore();
		$modified = $store->replaceParticipantAnswers( $eventID, $user, $answers );
		$this->assertSame( $expectChange, $modified );
		$newAnswers = $store->getParticipantAnswers( $eventID, $user );
		$this->assertEquals( $answers, $newAnswers );
	}

	public static function provideReplaceParticipantAnswers(): Generator {
		$ans = static fn ( int $quest, ?int $opt, string $text = null ) => new Answer( $quest, $opt, $text );
		yield 'No change' => [
			1,
			101,
			[
				$ans( 1, 1 ),
				$ans( 2, 1 ),
				$ans( 3, 2, 'foo' ),
				$ans( 4, null, 'bar' ),
			],
			false
		];
		yield 'Add one answer' => [
			1,
			101,
			[
				$ans( 1, 1 ),
				$ans( 2, 1 ),
				$ans( 3, 2, 'foo' ),
				$ans( 4, null, 'bar' ),
				$ans( 5, 42 ),
			]
		];
		yield 'Remove one answer' => [
			1,
			101,
			[
				$ans( 1, 1 ),
				$ans( 2, 1 ),
				$ans( 3, 2, 'foo' ),
			]
		];
		yield 'Remove all answers' => [
			1,
			101,
			[]
		];
		yield 'Remove one, add one' => [
			1,
			101,
			[
				$ans( 1, 1 ),
				$ans( 2, 1 ),
				$ans( 3, 2, 'foo' ),
				$ans( 5, 42 ),
			]
		];
		yield 'Remove all, add one' => [
			1,
			101,
			[
				$ans( 5, 42 ),
			]
		];
		yield 'Update one, option' => [
			1,
			101,
			[
				$ans( 1, 10 ),
				$ans( 2, 1 ),
				$ans( 3, 2, 'foo' ),
				$ans( 4, null, 'bar' ),
			]
		];
		yield 'Update one, text' => [
			1,
			101,
			[
				$ans( 1, 1 ),
				$ans( 2, 1 ),
				$ans( 3, 2, 'foo' ),
				$ans( 4, null, 'quux' ),
			]
		];
		yield 'Update two' => [
			1,
			101,
			[
				$ans( 1, 10 ),
				$ans( 2, 1 ),
				$ans( 3, 2, 'foo' ),
				$ans( 4, null, 'quux' ),
			]
		];
		yield 'Update one, add one' => [
			1,
			101,
			[
				$ans( 1, 10 ),
				$ans( 2, 1 ),
				$ans( 3, 2, 'foo' ),
				$ans( 4, null, 'bar' ),
				$ans( 5, 42 ),
			]
		];
		yield 'Update one, remove one' => [
			1,
			101,
			[
				$ans( 1, 10 ),
				$ans( 2, 1 ),
				$ans( 3, 2, 'foo' ),
			]
		];
		yield 'Update one, add one, remove one' => [
			1,
			101,
			[
				$ans( 1, 10 ),
				$ans( 2, 1 ),
				$ans( 4, null, 'bar' ),
				$ans( 5, 42 ),
			]
		];
		yield 'Add first answer' => [
			3,
			101,
			[
				$ans( 1, 1 ),
			]
		];
		yield 'No previous answers, removing none' => [
			10,
			110,
			[],
			false
		];
	}

	/**
	 * @covers ::deleteAllAnswers
	 * @dataProvider provideDeleteAllAnswers
	 */
	public function testDeleteAllAnswers(
		int $eventID,
		?array $userIDs,
		bool $invert,
		array $expectedRemainingCounts
	) {
		$users = is_array( $userIDs )
			? array_map( static fn ( int $id ): CentralUser => new CentralUser( $id ), $userIDs )
			: $userIDs;
		$store = CampaignEventsServices::getParticipantAnswersStore();
		$store->deleteAllAnswers( $eventID, $users, $invert );
		$remainingDataCounts = $this->getDb()->select(
			'ce_question_answers',
			[ 'ceqa_user_id', 'num' => 'COUNT(*)' ],
			[ 'ceqa_event_id' => $eventID ],
			__METHOD__,
			[ 'GROUP BY' => 'ceqa_user_id' ]
		);
		$actualRemainingCounts = [];
		foreach ( $remainingDataCounts as $row ) {
			$actualRemainingCounts[$row->ceqa_user_id] = (int)$row->num;
		}
		$this->assertSame( $expectedRemainingCounts, $actualRemainingCounts );
	}

	public function provideDeleteAllAnswers(): Generator {
		yield 'Nothing to delete' => [
			10,
			[ 101 ],
			false,
			[]
		];
		yield 'Single user' => [
			1,
			[ 101 ],
			false,
			[ 102 => 2 ]
		];
		yield 'Multiple users' => [
			1,
			[ 101, 102 ],
			false,
			[]
		];
		yield 'All users' => [
			1,
			null,
			false,
			[]
		];
		yield 'Inverted selection' => [
			1,
			[ 102 ],
			true,
			[ 102 => 2 ]
		];
	}

	/**
	 * @covers ::getParticipantAnswers
	 * @covers ::getParticipantAnswersMulti
	 * @dataProvider provideGetParticipantAnswers
	 */
	public function testGetParticipantAnswers( int $eventID, int $userID, array $expected ) {
		$user = new CentralUser( $userID );
		$store = CampaignEventsServices::getParticipantAnswersStore();
		$this->assertEquals( $expected, $store->getParticipantAnswers( $eventID, $user ) );
	}

	public function provideGetParticipantAnswers(): Generator {
		$ans = static fn ( int $quest, ?int $opt, string $text = null ) => new Answer( $quest, $opt, $text );

		yield 'Multiple answers' => [
			1,
			101,
			[
				$ans( 1, 1 ),
				$ans( 2, 1 ),
				$ans( 3, 2, 'foo' ),
				$ans( 4, null, 'bar' ),
			]
		];
		yield 'Single answer' => [
			2,
			103,
			[ $ans( 1, 3 ) ]
		];
		yield 'No answers' => [
			10,
			110,
			[]
		];
	}

	/**
	 * @covers ::getParticipantAnswersMulti
	 * @dataProvider provideGetParticipantAnswersMulti
	 */
	public function testGetParticipantAnswersMulti( int $eventID, array $userIDs, array $expected ) {
		$store = CampaignEventsServices::getParticipantAnswersStore();
		$users = array_map( static fn ( int $id ) => new CentralUser( $id ), $userIDs );
		$this->assertEquals( $expected, $store->getParticipantAnswersMulti( $eventID, $users ) );
	}

	public function provideGetParticipantAnswersMulti(): Generator {
		$ans = static fn ( int $quest, ?int $opt, string $text = null ) => new Answer( $quest, $opt, $text );

		yield 'No participants given' => [ 1, [], [] ];

		yield 'Single participant' => [
			2,
			[ 103 ],
			[ 103 => [ $ans( 1, 3 ) ] ]
		];

		yield 'Multiple participants' => [
			2,
			[ 101, 103, 104 ],
			[
				101 => [
					$ans( 1, 1 ),
					$ans( 4, null, 'quux' ),
				],
				103 => [ $ans( 1, 3 ) ],
				104 => [ $ans( 5, 1 ) ],
			]
		];

		yield 'Includes participant without answers' => [
			2,
			[ 103, 110 ],
			[
				103 => [ $ans( 1, 3 ) ],
				110 => [],
			]
		];
	}

	/**
	 * @covers ::eventHasAnswers
	 * @dataProvider provideEventHasAnswers
	 */
	public function testEventHasAnswers( int $eventID, bool $expected ) {
		$store = CampaignEventsServices::getParticipantAnswersStore();
		$this->assertEquals( $expected, $store->eventHasAnswers( $eventID ) );
	}

	public function provideEventHasAnswers(): Generator {
		yield 'Has answers' => [ 1, true ];
		yield 'Has no answers' => [ 5, false ];
	}
}
