<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Event\Store;

use Generator;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
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
				'ceew_wiki' => EventWikisStore::ALL_WIKIS,
			],
		];
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'ce_event_wikis' )
			->rows( $rows )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * Tests retrieving wikis for a specific event.
	 * @param int $eventID
	 * @param array $expected
	 * @covers ::getEventWikis
	 * @dataProvider provideGetEventWikis
	 */
	public function testGetEventWikis( int $eventID, array $expected ) {
		$store = CampaignEventsServices::getEventWikisStore();
		$result = $store->getEventWikis( $eventID );
		$this->assertEqualsCanonicalizing( $expected, $result );
	}

	public static function provideGetEventWikis(): Generator {
		yield 'Existing event with multiple wikis' => [ 1, [ 'enwiki', 'itwiki' ] ];
		yield 'Existing event with one wiki' => [ 2, [ 'eswiki' ] ];
		yield 'Existing event with no wiki' => [ 4, [] ];
	}

	/**
	 * Tests adding or updating wikis for a specific event.
	 * @covers ::addOrUpdateEventWikis
	 * @dataProvider provideAddOrUpdateEventWikis
	 */
	public function testAddOrUpdateEventWikis() {
		$store = CampaignEventsServices::getEventWikisStore();
		$newWikis = [ 'dewiki', 'frwiki' ];
		$eventID = 3;
		$store->addOrUpdateEventWikis( $newWikis, $eventID );

		$result = $store->getEventWikis( $eventID );
		$this->assertEqualsCanonicalizing( $newWikis, $result );
	}

	public static function provideAddOrUpdateEventWikis(): Generator {
		yield 'Event has no wikis add 2 wikis' => [ 100, [ 'enwiki', 'itwiki' ] ];
		yield 'Event has no wikis leave empty' => [ 100, [] ];
		yield 'Event has no wikis add all wikis' => [ 100, [ EventWikisStore::ALL_WIKIS ] ];

		yield 'Event has 2 wikis add more 2' => [ 1, [ 'enwiki', 'itwiki', 'eswiki', 'ptwiki' ] ];
		yield 'Event has 2 wikis remove all wikis' => [ 1, [] ];
		yield 'Event has 2 wikis change to all wikis' => [ 1, [ EventWikisStore::ALL_WIKIS ] ];

		yield 'Event has all wikis change to only 2 wikis' => [ 3, [ 'enwiki', 'itwiki' ] ];
		yield 'Event has all wikis change to no wikis' => [ 3, [] ];
		yield 'Event has all wikis leave unchanged' => [ 3, [ EventWikisStore::ALL_WIKIS ] ];

		yield 'No change' => [ 2, [ 'eswiki' ] ];
	}
}
