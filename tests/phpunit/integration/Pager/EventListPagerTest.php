<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Pager;

use Generator;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;

/**
 * @group Test
 * @group Database
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Pager\EventsListPager
 * @covers ::__construct()
 */
class EventListPagerTest extends MediaWikiIntegrationTestCase {
	private const EVENT_START = 1600000000;
	private const EVENT_END = 1700000000;

	public function addDBDataOnce(): void {
		$dbw = $this->getDb();
		$curTS = $dbw->timestamp();
		$row = [
			'event_name' => __METHOD__,
			'event_page_namespace' => 1728,
			'event_page_title' => __METHOD__,
			'event_page_prefixedtext' => __METHOD__,
			'event_page_wiki' => WikiMap::getCurrentWikiId(),
			'event_chat_url' => '',
			'event_status' => 1,
			'event_timezone' => 'UTC',
			'event_start_local' => $dbw->timestamp( self::EVENT_START ),
			'event_start_utc' => $dbw->timestamp( self::EVENT_START ),
			'event_end_local' => $dbw->timestamp( self::EVENT_END ),
			'event_end_utc' => $dbw->timestamp( self::EVENT_END ),
			'event_type' => 'generic',
			'event_meeting_type' => 3,
			'event_meeting_url' => '',
			'event_created_at' => $curTS,
			'event_last_edit' => $curTS,
			'event_deleted_at' => null,
		];
		$dbw->newInsertQueryBuilder()
			->insertInto( 'campaign_events' )
			->row( $row )
			->caller( __METHOD__ )
			->execute();

		$organizerRow = [
			'ceo_event_id' => 1,
			'ceo_user_id' => 1,
			'ceo_roles' => 1,
			'ceo_created_at' => $curTS,
			'ceo_deleted_at' => null,
			'ceo_agreement_timestamp' => null,
		];
		$dbw->newInsertQueryBuilder()
			->insertInto( 'ce_organizers' )
			->row( $organizerRow )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * @dataProvider provideDateFilters
	 */
	public function testDateFilters(
		?int $searchStart,
		?int $searchTo,
		bool $expectsFound
	): void {
		$searchStartStr = $searchStart !== null ? wfTimestamp( TS_MW, $searchStart ) : '';
		$searchToStr = $searchTo !== null ? wfTimestamp( TS_MW, $searchTo ) : '';
		$pager = CampaignEventsServices::getEventsPagerFactory()->newListPager(
			'',
			null,
			$searchStartStr,
			$searchToStr
		);
		$this->assertSame( $expectsFound ? 1 : 0, $pager->getNumRows() );
	}

	public static function provideDateFilters(): Generator {
		$delta = 10000;

		yield 'No filters' => [ null, null, true ];

		yield 'Start only, before event' => [ self::EVENT_START - $delta, null, true ];
		yield 'Start only, during event' => [ self::EVENT_START + $delta, null, true ];
		yield 'Start only, after event' => [ self::EVENT_END + $delta, null, false ];

		yield 'End only, before event' => [ null, self::EVENT_START - $delta, false ];
		yield 'End only, during event' => [ null, self::EVENT_START + $delta, true ];
		yield 'End only, after event' => [ null, self::EVENT_END + $delta, true ];

		yield 'Start before, end before' => [ self::EVENT_START - $delta, self::EVENT_START - $delta / 2, false ];
		yield 'Start before, end during' => [ self::EVENT_START - $delta, self::EVENT_START + $delta, true ];
		yield 'Start before, end after' => [ self::EVENT_START - $delta, self::EVENT_END + $delta, true ];
		yield 'Start during, end during' => [ self::EVENT_START + $delta / 2, self::EVENT_START + $delta, true ];
		yield 'Start during, end after' => [ self::EVENT_START + $delta, self::EVENT_END + $delta, true ];
		yield 'Start after, end after' => [ self::EVENT_END + $delta / 2, self::EVENT_END + $delta, false ];
	}
}
