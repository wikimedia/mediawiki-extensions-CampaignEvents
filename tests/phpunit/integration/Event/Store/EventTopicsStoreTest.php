<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Event\Store;

use Generator;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWikiIntegrationTestCase;

/**
 * @group Test
 * @group Database
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Event\Store\EventTopicsStore
 * @covers ::__construct()
 */
class EventTopicsStoreTest extends MediaWikiIntegrationTestCase {
	/**
	 * @inheritDoc
	 */
	public function addDBData(): void {
		$rows = [
			[
				'ceet_event_id' => 1,
				'ceet_topic' => 'topic_a',
			],
			[
				'ceet_event_id' => 1,
				'ceet_topic' => 'topic_b',
			],
			[
				'ceet_event_id' => 2,
				'ceet_topic' => 'topic_c',
			],
		];
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'ce_event_topics' )
			->rows( $rows )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * Tests retrieving topics for a specific event.
	 * @param int $eventID
	 * @param string[] $expected
	 * @covers ::getEventTopics
	 * @dataProvider provideGetEventTopics
	 */
	public function testGetEventTopics( int $eventID, $expected ) {
		$store = CampaignEventsServices::getEventTopicsStore();
		$result = $store->getEventTopics( $eventID );
		$this->assertEquals( $expected, $result );
	}

	public static function provideGetEventTopics(): Generator {
		yield 'Existing event with multiple topics' => [ 1, [ 'topic_a', 'topic_b' ] ];
		yield 'Existing event with one topic' => [ 2, [ 'topic_c' ] ];
		yield 'Existing event with no topic' => [ 100, [] ];
	}

	/**
	 * @param int[] $eventIDs
	 * @param array $expected
	 * @covers ::getEventTopicsMulti
	 * @dataProvider provideGetEventTopicsMulti
	 */
	public function testGetEventTopicsMulti( array $eventIDs, array $expected ) {
		$store = CampaignEventsServices::getEventTopicsStore();
		$result = $store->getEventTopicsMulti( $eventIDs );
		$this->assertEquals( $expected, $result );
	}

	public static function provideGetEventTopicsMulti(): Generator {
		yield 'Single event with multiple topics' => [ [ 1 ], [ 1 => [ 'topic_a', 'topic_b' ] ] ];
		yield 'Single event with no topics' => [ [ 100 ], [ 100 => [] ] ];
		yield 'Multiple events with multiple and no topics' => [
			[ 1, 100 ],
			[ 1 => [ 'topic_a', 'topic_b' ], 100 => [] ]
		];
	}

	/**
	 * Tests adding or updating topics for a specific event.
	 * @covers ::addOrUpdateEventTopics
	 * @dataProvider provideAddOrUpdateEventTopics
	 */
	public function testAddOrUpdateEventTopics( int $eventID, $topics ) {
		$store = CampaignEventsServices::getEventTopicsStore();
		$store->addOrUpdateEventTopics( $eventID, $topics );

		$result = $store->getEventTopics( $eventID );
		$this->assertEquals( $topics, $result );
	}

	public static function provideAddOrUpdateEventTopics(): Generator {
		yield 'Event has no topics add 1 topic' => [ 100, [ 'topic_a' ] ];
		yield 'Event has no topics leave empty' => [ 100, [] ];

		yield 'Event has 2 topics add more 1' => [ 1, [ 'topic_a', 'topic_b', 'topic_c' ] ];
		yield 'Event has 2 topics remove all topics' => [ 1, [] ];
		yield 'Event has 2 topics, leave unchanged' => [ 1, [ 'topic_a', 'topic_b' ] ];
	}
}
