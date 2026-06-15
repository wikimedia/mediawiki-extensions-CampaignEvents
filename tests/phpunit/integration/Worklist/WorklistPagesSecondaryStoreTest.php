<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Worklist;

use Generator;
use InvalidArgumentException;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWikiIntegrationTestCase;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @covers \MediaWiki\Extension\CampaignEvents\Worklist\WorklistPagesSecondaryStore
 * @group Database
 */
class WorklistPagesSecondaryStoreTest extends MediaWikiIntegrationTestCase {
	private const TEST_TIME = '20270707071717';

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
}
