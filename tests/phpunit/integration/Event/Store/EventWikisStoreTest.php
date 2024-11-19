<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Event\Store;

use Generator;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventWikisStore;
use MediaWikiIntegrationTestCase;

/**
 * @group Test
 * @group Database
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Event\Store\EventWikisStore
 * @covers ::__construct()
 */
class EventWikisStoreTest extends MediaWikiIntegrationTestCase {
	/**
	 * @inheritDoc
	 */
	public function addDBData(): void {
		$rows = [
			[
				'ceew_event_id' => 1,
				'ceew_wiki' => 'enwiki',
			],
			[
				'ceew_event_id' => 1,
				'ceew_wiki' => 'itwiki',
			],
			[
				'ceew_event_id' => 2,
				'ceew_wiki' => 'eswiki',
			],
			[
				'ceew_event_id' => 3,
				'ceew_wiki' => EventWikisStore::ALL_WIKIS_DB_VALUE,
			],
		];
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'ce_event_wikis' )
			->rows( $rows )
			->caller( __METHOD__ )
			->execute();
	}

	protected function setUp(): void {
		parent::setUp();
		$this->overrideConfigValue( 'CampaignEventsEnableEventWikis', true );
	}

	/**
	 * Tests retrieving wikis for a specific event.
	 * @param int $eventID
	 * @param string[]|true $expected
	 * @covers ::getEventWikis
	 * @dataProvider provideGetEventWikis
	 */
	public function testGetEventWikis( int $eventID, $expected ) {
		$store = CampaignEventsServices::getEventWikisStore();
		$result = $store->getEventWikis( $eventID );
		$this->assertEqualsCanonicalizing( $expected, $result );
	}

	public static function provideGetEventWikis(): Generator {
		yield 'Existing event with multiple wikis' => [ 1, [ 'enwiki', 'itwiki' ] ];
		yield 'Existing event with one wiki' => [ 2, [ 'eswiki' ] ];
		yield 'Existing event with all wikis' => [ 3, EventRegistration::ALL_WIKIS ];
		yield 'Existing event with no wiki' => [ 100, [] ];
	}

	/**
	 * @param int[] $eventIDs
	 * @param array $expected
	 * @covers ::getEventWikisMulti
	 * @dataProvider provideGetEventWikisMulti
	 */
	public function testGetEventWikisMulti( array $eventIDs, array $expected ) {
		$store = CampaignEventsServices::getEventWikisStore();
		$result = $store->getEventWikisMulti( $eventIDs );
		$this->assertEqualsCanonicalizing( $expected, $result );
	}

	public static function provideGetEventWikisMulti(): Generator {
		yield 'Single event with multiple wikis' => [ [ 1 ], [ 1 => [ 'enwiki', 'itwiki' ] ] ];
		yield 'Single event with all wikis' => [ [ 3 ], [ 3 => EventRegistration::ALL_WIKIS ] ];
		yield 'Single event with no wikis' => [ [ 100 ], [ 100 => [] ] ];
		yield 'Multiple events with multiple, all, and no wikis' => [
			[ 1, 3, 100 ],
			[ 1 => [ 'enwiki', 'itwiki' ], 3 => EventRegistration::ALL_WIKIS, 100 => [] ]
		];
	}

	/**
	 * Tests adding or updating wikis for a specific event.
	 * @covers ::addOrUpdateEventWikis
	 * @dataProvider provideAddOrUpdateEventWikis
	 */
	public function testAddOrUpdateEventWikis( int $eventID, $wikis ) {
		$store = CampaignEventsServices::getEventWikisStore();
		$store->addOrUpdateEventWikis( $eventID, $wikis );

		$result = $store->getEventWikis( $eventID );
		$this->assertEqualsCanonicalizing( $wikis, $result );
	}

	public static function provideAddOrUpdateEventWikis(): Generator {
		yield 'Event has no wikis add 2 wikis' => [ 100, [ 'enwiki', 'itwiki' ] ];
		yield 'Event has no wikis leave empty' => [ 100, [] ];
		yield 'Event has no wikis add all wikis' => [ 100, EventRegistration::ALL_WIKIS ];

		yield 'Event has 2 wikis add more 2' => [ 1, [ 'enwiki', 'itwiki', 'eswiki', 'ptwiki' ] ];
		yield 'Event has 2 wikis remove all wikis' => [ 1, [] ];
		yield 'Event has 2 wikis change to all wikis' => [ 1, EventRegistration::ALL_WIKIS ];
		yield 'Event has 2 wikis, leave unchanged' => [ 1, [ 'enwiki', 'itwiki' ] ];

		yield 'Event has all wikis change to only 2 wikis' => [ 3, [ 'enwiki', 'itwiki' ] ];
		yield 'Event has all wikis change to no wikis' => [ 3, [] ];
		yield 'Event has all wikis leave unchanged' => [ 3, EventRegistration::ALL_WIKIS ];
	}
}
