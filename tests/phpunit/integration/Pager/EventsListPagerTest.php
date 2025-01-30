<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Pager;

use Generator;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventWikisStore;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use Wikimedia\Assert\ParameterAssertionException;

/**
 * @group Test
 * @group Database
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Pager\EventsListPager
 * @covers ::__construct()
 */
class EventsListPagerTest extends MediaWikiIntegrationTestCase {
	private const EVENT_NAME = 'Test event dQw4w9WgXcQ';
	private const EVENT_START = 1600000000;
	private const EVENT_END = 1700000000;
	private const EVENT_TOPIC = 'some-topic';

	public function addDBDataOnce(): void {
		$dbw = $this->getDb();
		$curTS = $dbw->timestamp();
		$row = [
			'event_name' => self::EVENT_NAME,
			'event_page_namespace' => 1728,
			'event_page_title' => self::EVENT_NAME,
			'event_page_prefixedtext' => 'Event:' . self::EVENT_NAME,
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

		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'ce_event_wikis' )
			->row( [
				'ceew_event_id' => 1,
				'ceew_wiki' => EventWikisStore::ALL_WIKIS_DB_VALUE,
			] )
			->caller( __METHOD__ )
			->execute();

		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'ce_event_topics' )
			->row( [
				'ceet_event_id' => 1,
				'ceet_topic' => self::EVENT_TOPIC,
			] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * @dataProvider provideDateFilters
	 */
	public function testDateFilters(
		?int $searchStart,
		?int $searchTo,
		bool $showOngoing,
		bool $expectsFound
	): void {
		$searchStartStr = $searchStart !== null ? wfTimestamp( TS_MW, $searchStart ) : null;
		$searchToStr = $searchTo !== null ? wfTimestamp( TS_MW, $searchTo ) : null;
		$pager = CampaignEventsServices::getEventsPagerFactory()->newListPager(
			'',
			null,
			$searchStartStr,
			$searchToStr,
			$showOngoing,
			[],
			[]
		);
		$this->assertSame( $expectsFound ? 1 : 0, $pager->getNumRows() );
	}

	public static function provideDateFilters(): Generator {
		$delta = 10000;

		yield 'Show ongoing, no filters' => [ null, null, true, true ];

		yield 'Show ongoing, start only, before event' => [ self::EVENT_START - $delta, null, true, true ];
		yield 'Show ongoing, start only, during event' => [ self::EVENT_START + $delta, null, true, true ];
		yield 'Show ongoing, start only, after event' => [ self::EVENT_END + $delta, null, true, false ];

		yield 'Show ongoing, end only, before event' => [ null, self::EVENT_START - $delta, true, false ];
		yield 'Show ongoing, end only, during event' => [ null, self::EVENT_START + $delta, true, true ];
		yield 'Show ongoing, end only, after event' => [ null, self::EVENT_END + $delta, true, true ];

		yield 'Show ongoing, start before, end before' => [
			self::EVENT_START - $delta,
			self::EVENT_START - $delta / 2,
			true,
			false
		];
		yield 'Show ongoing, start before, end during' => [
			self::EVENT_START - $delta,
			self::EVENT_START + $delta,
			true,
			true
		];
		yield 'Show ongoing, start before, end after' => [
			self::EVENT_START - $delta,
			self::EVENT_END + $delta,
			true,
			true
		];
		yield 'Show ongoing, start during, end during' => [
			self::EVENT_START + $delta / 2,
			self::EVENT_START + $delta,
			true,
			true
		];
		yield 'Show ongoing, start during, end after' => [
			self::EVENT_START + $delta,
			self::EVENT_END + $delta,
			true,
			true
		];
		yield 'Show ongoing, start after, end after' => [
			self::EVENT_END + $delta / 2,
			self::EVENT_END + $delta,
			true,
			false
		];

		yield 'Hide ongoing, no filters' => [ null, null, false, true ];

		yield 'Hide ongoing, start only, before event' => [ self::EVENT_START - $delta, null, false, true ];
		yield 'Hide ongoing, start only, during event' => [ self::EVENT_START + $delta, null, false, false ];
		yield 'Hide ongoing, start only, after event' => [ self::EVENT_END + $delta, null, false, false ];

		yield 'Hide ongoing, end only, before event' => [ null, self::EVENT_START - $delta, false, false ];
		yield 'Hide ongoing, end only, during event' => [ null, self::EVENT_START + $delta, false, true ];
		yield 'Hide ongoing, end only, after event' => [ null, self::EVENT_END + $delta, false, true ];

		yield 'Hide ongoing, start before, end before' => [
			self::EVENT_START - $delta,
			self::EVENT_START - $delta / 2,
			false,
			false
		];
		yield 'Hide ongoing, start before, end during' => [
			self::EVENT_START - $delta,
			self::EVENT_START + $delta,
			false,
			true
		];
		yield 'Hide ongoing, start before, end after' => [
			self::EVENT_START - $delta,
			self::EVENT_END + $delta,
			false,
			true
		];
		yield 'Hide ongoing, start during, end during' => [
			self::EVENT_START + $delta / 2,
			self::EVENT_START + $delta,
			false,
			false
		];
		yield 'Hide ongoing, start during, end after' => [
			self::EVENT_START + $delta,
			self::EVENT_END + $delta,
			false,
			false
		];
		yield 'Hide ongoing, start after, end after' => [
			self::EVENT_END + $delta / 2,
			self::EVENT_END + $delta,
			false,
			false
		];
	}

	/**
	 * @dataProvider provideInvalidTimestamps
	 */
	public function testConstruct__invalidStartDate( string $timestamp ) {
		$this->expectException( ParameterAssertionException::class );
		$this->expectExceptionMessage( '$startDate' );
		CampaignEventsServices::getEventsPagerFactory()->newListPager(
			'',
			null,
			$timestamp,
			null,
			true,
			[],
			[]
		);
	}

	/**
	 * @dataProvider provideInvalidTimestamps
	 */
	public function testConstruct__invalidEndDate( string $timestamp ) {
		$this->expectException( ParameterAssertionException::class );
		$this->expectExceptionMessage( '$endDate' );
		CampaignEventsServices::getEventsPagerFactory()->newListPager(
			'',
			null,
			null,
			$timestamp,
			true,
			[],
			[]
		);
	}

	public static function provideInvalidTimestamps(): array {
		return [
			'Random string' => [ 'not a valid timestamp 123456' ],
			'Empty string' => [ '' ],
		];
	}

	public function testCanUseFilters() {
		$pager = CampaignEventsServices::getEventsPagerFactory()->newListPager(
			self::EVENT_NAME,
			EventRegistration::MEETING_TYPE_ONLINE_AND_IN_PERSON,
			'19120623000000',
			'29120623000000',
			true,
			[ 'any_wiki_name' ],
			[ self::EVENT_TOPIC ]
		);
		$this->assertSame( 1, $pager->getNumRows() );
	}
}
