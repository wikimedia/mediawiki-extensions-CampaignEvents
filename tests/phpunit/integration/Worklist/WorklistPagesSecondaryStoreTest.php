<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Worklist;

use Generator;
use InvalidArgumentException;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\Worklist\IWorklistArticlesLookup;
use MediaWiki\Utils\MWTimestamp;
use MediaWikiIntegrationTestCase;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use Wikimedia\Timestamp\TimestampFormat as TS;

/**
 * @covers \MediaWiki\Extension\CampaignEvents\Worklist\WorklistPagesSecondaryStore
 * @group Database
 */
class WorklistPagesSecondaryStoreTest extends MediaWikiIntegrationTestCase {
	private const TEST_TIME = '20270707071717';
	private const EVENT_WITH_PAGES = 1;
	private const OTHER_EVENT = 2;

	protected function setUp(): void {
		parent::setUp();
		ConvertibleTimestamp::setFakeTime( self::TEST_TIME );
	}

	public function addDBData(): void {
		$this->getDB()->newInsertQueryBuilder()
			->insertInto( 'ce_worklist_pages' )
			->rows( $this->transformTimestampsForDB( self::getInitialRowsWithPlainTimestamps() ) )
			->caller( __METHOD__ )
			->execute();

		// getPagesMetadataForEvent() reaches the pages through this association, so worklist 1001
		// belongs to event 1 and worklist 1002 to event 2.
		$this->getDB()->newInsertQueryBuilder()
			->insertInto( 'ce_worklist_events' )
			->rows( [
				[ 'cewe_cew_id' => 1001, 'cewe_event_id' => self::EVENT_WITH_PAGES ],
				[ 'cewe_cew_id' => 1002, 'cewe_event_id' => self::OTHER_EVENT ],
			] )
			->caller( __METHOD__ )
			->execute();
	}

	private static function getInitialRowsWithPlainTimestamps(): array {
		$startTS = '20260101120000';

		return [
			[
				'cewp_id' => 1,
				'cewp_wiki' => 'awiki',
				'cewp_page_prefixedtext' => 'Page 1',
				'cewp_user_id' => 101,
				'cewp_cew_id' => 1001,
				'cewp_timestamp' => $startTS,
			],
			[
				'cewp_id' => 2,
				'cewp_wiki' => 'awiki',
				'cewp_page_prefixedtext' => 'Page 2',
				'cewp_user_id' => 102,
				'cewp_cew_id' => 1001,
				'cewp_timestamp' => $startTS,
			],
			[
				'cewp_id' => 3,
				'cewp_wiki' => 'awiki',
				'cewp_page_prefixedtext' => 'Page 1',
				'cewp_user_id' => 101,
				'cewp_cew_id' => 1002,
				'cewp_timestamp' => $startTS,
			],
			[
				'cewp_id' => 4,
				'cewp_wiki' => 'bwiki',
				'cewp_page_prefixedtext' => 'Page 1',
				'cewp_user_id' => 103,
				'cewp_cew_id' => 1001,
				'cewp_timestamp' => $startTS,
			],
			[
				'cewp_id' => 5,
				'cewp_wiki' => 'bwiki',
				'cewp_page_prefixedtext' => 'Page 1',
				'cewp_user_id' => 103,
				'cewp_cew_id' => 1002,
				'cewp_timestamp' => $startTS,
			],
			[
				'cewp_id' => 6,
				'cewp_wiki' => 'cwiki',
				'cewp_page_prefixedtext' => 'Page 11',
				'cewp_user_id' => 101,
				'cewp_cew_id' => 1001,
				'cewp_timestamp' => $startTS,
			],
		];
	}

	private function transformTimestampsForDB( array $rows ): array {
		$db = $this->getDB();
		array_walk( $rows, static function ( &$row ) use ( $db ) {
			$row['cewp_timestamp'] = $db->timestamp( $row['cewp_timestamp'] );
		} );
		return $rows;
	}

	/**
	 * @dataProvider provideUpdateWorklistPages
	 */
	public function testUpdateWorklistPages(
		int $worklistID,
		CentralUser $performer,
		array $removed,
		array $added,
		array $expectedRowsWithPlainTS
	): void {
		$store = CampaignEventsServices::getWorklistPagesSecondaryStore();
		$store->updateWorklistPages( $worklistID, $performer, $removed, $added );

		$res = $this->getDb()->newSelectQueryBuilder()
			->select( '*' )
			->from( 'ce_worklist_pages' )
			->caller( __METHOD__ )
			->fetchResultSet();
		$actualRows = [];
		foreach ( $res as $row ) {
			$actualRows[] = get_object_vars( $row );
		}

		$expectedRows = $this->transformTimestampsForDB( $expectedRowsWithPlainTS );

		$this->assertEquals( $expectedRows, $actualRows );
	}

	public static function provideUpdateWorklistPages(): Generator {
		$getInitialRowsWithoutIDs = static function ( int ...$ids ): array {
			$initialRows = self::getInitialRowsWithPlainTimestamps();
			return array_values( array_filter( $initialRows, static function ( $row ) use ( $ids ) {
				return !in_array( $row['cewp_id'], $ids, true );
			} ) );
		};

		$userID = 110;
		$user = new CentralUser( $userID );

		yield 'All empty' => [
			1001,
			$user,
			[],
			[],
			self::getInitialRowsWithPlainTimestamps(),
		];
		yield 'Add only' => [
			1001,
			$user,
			[],
			[
				'awiki' => [
					'Page 99',
				],
			],
			[
				...self::getInitialRowsWithPlainTimestamps(),
				[
					'cewp_id' => 7,
					'cewp_wiki' => 'awiki',
					'cewp_page_prefixedtext' => 'Page 99',
					'cewp_user_id' => $userID,
					'cewp_cew_id' => 1001,
					'cewp_timestamp' => self::TEST_TIME,
				],
			],
		];
		yield 'Remove only' => [
			1001,
			$user,
			[
				'bwiki' => [
					'Page 1',
				]
			],
			[],
			$getInitialRowsWithoutIDs( 4 ),
		];
		yield 'Add and remove' => [
			1001,
			$user,
			[
				'bwiki' => [
					'Page 1',
				],
				'ywiki' => [],
			],
			[
				'awiki' => [
					'Page 98',
					'Page 99',
				],
				'xwiki' => [],
			],
			[
				...$getInitialRowsWithoutIDs( 4 ),
				[
					'cewp_id' => 7,
					'cewp_wiki' => 'awiki',
					'cewp_page_prefixedtext' => 'Page 98',
					'cewp_user_id' => $userID,
					'cewp_cew_id' => 1001,
					'cewp_timestamp' => self::TEST_TIME,
				],
				[
					'cewp_id' => 8,
					'cewp_wiki' => 'awiki',
					'cewp_page_prefixedtext' => 'Page 99',
					'cewp_user_id' => $userID,
					'cewp_cew_id' => 1001,
					'cewp_timestamp' => self::TEST_TIME,
				],
			],
		];
		yield 'Remove article that is not there' => [
			1001,
			$user,
			[
				'zwiki' => [ 'Page 99' ],
			],
			[],
			self::getInitialRowsWithPlainTimestamps(),
		];
		yield 'Add article that is already there' => [
			1001,
			$user,
			[],
			[
				'awiki' => [ 'Page 1' ],
			],
			self::getInitialRowsWithPlainTimestamps(),
		];
	}

	public function testUpdateWorklistPages__samePageInBoth() {
		$store = CampaignEventsServices::getWorklistPagesSecondaryStore();

		$delta = [
			'awiki' => [ 'Page 1' ],
		];
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Cannot remove and add the same article' );
		$store->updateWorklistPages( 1001, new CentralUser( 1234 ), $delta, $delta );
	}

	public function testDeleteAllWorklistPages() {
		$store = CampaignEventsServices::getWorklistPagesSecondaryStore();
		$store->deleteAllWorklistPages( 1001 );

		$pagesByWorklistRes = $this->getDb()->newSelectQueryBuilder()
			->select( [ 'cewp_cew_id', 'num' => 'COUNT(*)' ] )
			->from( 'ce_worklist_pages' )
			->groupBy( 'cewp_cew_id' )
			->caller( __METHOD__ )
			->fetchResultSet();

		$storedPagesByWorklist = [];
		foreach ( $pagesByWorklistRes as $row ) {
			$storedPagesByWorklist[$row->cewp_cew_id] = $row->num;
		}
		$this->assertEquals( [ 1002 => 2 ], $storedPagesByWorklist );
	}

	public function testDeleteAllWorklistPages__noop() {
		$nonexistentWorklistID = 99999999;
		$store = CampaignEventsServices::getWorklistPagesSecondaryStore();
		$store->deleteAllWorklistPages( $nonexistentWorklistID );

		$remainingRowNum = (int)$this->getDb()->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( 'ce_worklist_pages' )
			->where( [ 'cewp_cew_id' => $nonexistentWorklistID ] )
			->caller( __METHOD__ )
			->fetchField();

		$this->assertSame( 0, $remainingRowNum );
	}

	/**
	 * @dataProvider provideGetPagesForWorklist
	 */
	public function testGetPagesForWorklist(
		int $worklistID,
		int $limit,
		int $offset,
		string $direction,
		string $sort,
		array $expected
	): void {
		$this->assertSame(
			$expected,
			CampaignEventsServices::getWorklistPagesSecondaryStore()
				->getPagesForWorklist( $worklistID, $limit, $offset, $direction, $sort )
		);
	}

	public static function provideGetPagesForWorklist(): Generator {
		$awikiPage1 = [ 'wiki' => 'awiki', 'prefixedtext' => 'Page 1' ];
		$awikiPage2 = [ 'wiki' => 'awiki', 'prefixedtext' => 'Page 2' ];
		$bwikiPage1 = [ 'wiki' => 'bwiki', 'prefixedtext' => 'Page 1' ];
		$cwikiPage11 = [ 'wiki' => 'cwiki', 'prefixedtext' => 'Page 11' ];

		// Every fixture row shares a timestamp, so cewp_id breaks the tie.
		yield 'Newest first' => [
			1001,
			0,
			0,
			IWorklistArticlesLookup::DESCENDING,
			IWorklistArticlesLookup::TIMESTAMP_SORT,
			[ $cwikiPage11, $bwikiPage1, $awikiPage2, $awikiPage1 ],
		];
		yield 'Oldest first' => [
			1001,
			0,
			0,
			IWorklistArticlesLookup::ASCENDING,
			IWorklistArticlesLookup::TIMESTAMP_SORT,
			[ $awikiPage1, $awikiPage2, $bwikiPage1, $cwikiPage11 ],
		];
		yield 'By title' => [
			1001,
			0,
			0,
			IWorklistArticlesLookup::ASCENDING,
			IWorklistArticlesLookup::PAGE_SORT,
			[ $awikiPage1, $bwikiPage1, $cwikiPage11, $awikiPage2 ],
		];
		yield 'By wiki' => [
			1001,
			0,
			0,
			IWorklistArticlesLookup::ASCENDING,
			IWorklistArticlesLookup::WIKI_SORT,
			[ $awikiPage1, $awikiPage2, $bwikiPage1, $cwikiPage11 ],
		];
		yield 'Limit and offset' => [
			1001,
			2,
			1,
			IWorklistArticlesLookup::DESCENDING,
			IWorklistArticlesLookup::TIMESTAMP_SORT,
			[ $bwikiPage1, $awikiPage2 ],
		];
		yield 'Another worklist' => [
			1002,
			0,
			0,
			IWorklistArticlesLookup::ASCENDING,
			IWorklistArticlesLookup::TIMESTAMP_SORT,
			[ $awikiPage1, $bwikiPage1 ],
		];
		yield 'Worklist with no pages' => [
			9999,
			0,
			0,
			IWorklistArticlesLookup::ASCENDING,
			IWorklistArticlesLookup::TIMESTAMP_SORT,
			[],
		];
	}

	/**
	 * @dataProvider provideGetPagesForWorklist__invalidArgs
	 */
	public function testGetPagesForWorklist__invalidArgs( string $direction, string $sort ): void {
		$this->expectException( InvalidArgumentException::class );
		CampaignEventsServices::getWorklistPagesSecondaryStore()
			->getPagesForWorklist( 1001, 0, 0, $direction, $sort );
	}

	public static function provideGetPagesForWorklist__invalidArgs(): Generator {
		yield 'Unknown sort' => [ IWorklistArticlesLookup::ASCENDING, 'nonexistent' ];
		yield 'Unknown direction' => [ 'sideways', IWorklistArticlesLookup::TIMESTAMP_SORT ];
	}

	public function testGetPagesMetadataForEvent(): void {
		$store = CampaignEventsServices::getWorklistPagesSecondaryStore();

		$metadata = $store->getPagesMetadataForEvent( self::EVENT_WITH_PAGES );

		$this->assertSame(
			[
				[ 'wiki' => 'cwiki', 'prefixedtext' => 'Page 11', 'timestamp' => '20260101120000' ],
				[ 'wiki' => 'bwiki', 'prefixedtext' => 'Page 1', 'timestamp' => '20260101120000' ],
				[ 'wiki' => 'awiki', 'prefixedtext' => 'Page 2', 'timestamp' => '20260101120000' ],
				[ 'wiki' => 'awiki', 'prefixedtext' => 'Page 1', 'timestamp' => '20260101120000' ],
			],
			array_map(
				static fn ( array $row ): array => [
					'wiki' => $row['wiki'],
					'prefixedtext' => $row['prefixedtext'],
					'timestamp' => MWTimestamp::convert( TS::MW, $row['timestamp'] ),
				],
				$metadata
			),
			'Only the pages of the worklist associated with the event, newest first, '
				. 'each with the timestamp the page was added'
		);
	}

	public function testGetPagesMetadataForEvent__noWorklist(): void {
		$nonexistentEventID = 99999999;
		$this->assertSame(
			[],
			CampaignEventsServices::getWorklistPagesSecondaryStore()
				->getPagesMetadataForEvent( $nonexistentEventID )
		);
	}

	/**
	 * @dataProvider provideFilterPagesInWorklist
	 */
	public function testFilterPagesInWorklist(
		int $worklistID,
		string $wiki,
		array $prefixedTexts,
		array $expected
	): void {
		$actual = CampaignEventsServices::getWorklistPagesSecondaryStore()
			->filterPagesInWorklist( $worklistID, $wiki, $prefixedTexts );
		// The order is not guaranteed.
		sort( $actual );
		$this->assertSame( $expected, $actual );
	}

	public static function provideFilterPagesInWorklist(): Generator {
		yield 'Only the pages in the worklist' => [
			1001,
			'awiki',
			[ 'Page 1', 'Page 2', 'Page 3' ],
			[ 'Page 1', 'Page 2' ]
		];
		yield 'Not the same title on another wiki' => [ 1001, 'cwiki', [ 'Page 1' ], [] ];
		yield 'Not the same title in another worklist' => [ 1002, 'awiki', [ 'Page 2' ], [] ];
		yield 'Nothing asked' => [ 1001, 'awiki', [], [] ];
		yield 'Nonexistent worklist' => [ 99999999, 'awiki', [ 'Page 1' ], [] ];
	}

}
