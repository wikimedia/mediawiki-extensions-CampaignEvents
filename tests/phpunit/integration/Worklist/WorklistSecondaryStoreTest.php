<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Worklist;

use Generator;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWikiIntegrationTestCase;
use RuntimeException;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @covers \MediaWiki\Extension\CampaignEvents\Worklist\WorklistSecondaryStore
 * @group Database
 */
class WorklistSecondaryStoreTest extends MediaWikiIntegrationTestCase {
	public function addDBData() {
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'ce_worklists' )
			->rows( $this->transformTimestampsForDB( self::getInitialRowsWithPlainTimestamps() ) )
			->caller( __METHOD__ )
			->execute();
	}

	private static function getInitialRowsWithPlainTimestamps(): array {
		$timestamp = '20250814120000';

		return [
			[
				'cew_id' => 1,
				'cew_wiki' => 'awiki',
				'cew_page_id' => 1,
				'cew_page_prefixedtext' => 'Worklist 1',
				'cew_user_id' => 101,
				'cew_username' => 'User 101',
				'cew_timestamp' => $timestamp,
				'cew_content_rev' => null,
			],
			[
				'cew_id' => 2,
				'cew_wiki' => 'bwiki',
				'cew_page_id' => 1,
				'cew_page_prefixedtext' => 'Worklist 1',
				'cew_user_id' => 102,
				'cew_username' => 'User 102',
				'cew_timestamp' => $timestamp,
				'cew_content_rev' => null,
			],
		];
	}

	private function transformTimestampsForDB( array $rows ): array {
		$db = $this->getDB();
		array_walk( $rows, static function ( &$row ) use ( $db ) {
			$row['cew_timestamp'] = $db->timestamp( $row['cew_timestamp'] );
		} );
		return $rows;
	}

	private function assertExpectedRows( array $expectedRows ): void {
		$res = $this->getDb()->newSelectQueryBuilder()
			->select( '*' )
			->from( 'ce_worklists' )
			->caller( __METHOD__ )
			->fetchResultSet();
		$actualRows = [];
		foreach ( $res as $row ) {
			$actualRows[] = get_object_vars( $row );
		}
		$this->assertEquals( $expectedRows, $actualRows );
	}

	public function testCreateWorklist() {
		$store = CampaignEventsServices::getWorklistSecondaryStore();
		$wiki = 'awiki';
		$pageID = 123;
		$prefixedText = 'User:Some worklist title';
		$creatorID = 456;
		$creatorName = 'John Doe';
		$timestamp = new ConvertibleTimestamp( '20260101120000' );
		$newID = $store->createWorklist(
			$wiki, $pageID, $prefixedText, new CentralUser( $creatorID ), $creatorName, $timestamp
		);

		$nextWorklistID = count( self::getInitialRowsWithPlainTimestamps() ) + 1;
		$this->assertSame( $nextWorklistID, $newID );
		$this->assertExpectedRows(
			[
				...$this->transformTimestampsForDB( self::getInitialRowsWithPlainTimestamps() ),
				[
					'cew_id' => 3,
					'cew_wiki' => $wiki,
					'cew_page_id' => $pageID,
					'cew_page_prefixedtext' => $prefixedText,
					'cew_user_id' => $creatorID,
					'cew_username' => $creatorName,
					'cew_timestamp' => $this->getDb()->timestamp( $timestamp ),
					'cew_content_rev' => null,
				],
			]
		);
	}

	public function testDeleteWorklist() {
		$store = CampaignEventsServices::getWorklistSecondaryStore();

		$store->deleteWorklist( 'bwiki', 1 );

		$this->assertExpectedRows(
			$this->transformTimestampsForDB( [ self::getInitialRowsWithPlainTimestamps()[0] ] )
		);
	}

	public function testMoveWorklist() {
		$store = CampaignEventsServices::getWorklistSecondaryStore();

		$newPrefixedText = 'New worklist name!';
		$store->moveWorklist( 'bwiki', 1, $newPrefixedText );

		$expectedRows = $this->transformTimestampsForDB( self::getInitialRowsWithPlainTimestamps() );
		$expectedRows[1]['cew_page_prefixedtext'] = $newPrefixedText;

		$this->assertExpectedRows( $expectedRows );
	}

	/** @dataProvider provideGetWorklistIDFromPage */
	public function testGetWorklistIDFromPage( string $wiki, int $pageID, ?int $expected ) {
		$store = CampaignEventsServices::getWorklistSecondaryStore();
		$this->assertSame( $expected, $store->getWorklistIDFromPage( $wiki, $pageID ) );
	}

	public static function provideGetWorklistIDFromPage(): Generator {
		yield 'Exists' => [ 'bwiki', 1, 2 ];
		yield 'Does not exist' => [ 'xyzwiki', 1, null ];
	}

	/** @dataProvider provideUpdateWorklistCreatorName */
	public function testUpdateWorklistCreatorName( ?string $newName ) {
		$store = CampaignEventsServices::getWorklistSecondaryStore();

		$store->updateWorklistCreatorName( 'bwiki', 1, $newName );

		$expectedRows = $this->transformTimestampsForDB( self::getInitialRowsWithPlainTimestamps() );
		$expectedRows[1]['cew_username'] = $newName;

		$this->assertExpectedRows( $expectedRows );
	}

	public static function provideUpdateWorklistCreatorName() {
		yield 'Deletion' => [ null ];
		yield 'Change' => [ 'Some new username 54321!' ];
	}

	public function testGetAndUpdateWorklistContentSyncedRev() {
		$store = CampaignEventsServices::getWorklistSecondaryStore();
		$worklistID = 1;

		$this->assertNull( $store->getWorklistContentSyncedRev( $worklistID ), 'Initial value' );

		$newRevID = 444;
		$store->updateWorklistContentSyncedRev( $worklistID, $newRevID );
		$this->assertSame( $newRevID, $store->getWorklistContentSyncedRev( $worklistID ), 'Updated value' );
	}

	public function testGetWorklistContentSyncedRev__doesNotExist() {
		$store = CampaignEventsServices::getWorklistSecondaryStore();
		$nonexistentWorklistID = 99999999999;
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( "Worklist $nonexistentWorklistID doesn't exist" );
		$store->getWorklistContentSyncedRev( $nonexistentWorklistID );
	}
}
