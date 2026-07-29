<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Worklist;

use BadMethodCallException;
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
			[
				'cew_id' => 3,
				'cew_wiki' => 'awiki',
				'cew_page_id' => 2,
				'cew_page_prefixedtext' => 'Worklist 2',
				'cew_user_id' => 103,
				'cew_username' => null,
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
					'cew_id' => 4,
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

		$newRows = array_values( array_diff_key( self::getInitialRowsWithPlainTimestamps(), [ 1 => true ] ) );

		$this->assertExpectedRows( $this->transformTimestampsForDB( $newRows ) );
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

	/** @dataProvider provideGetWorklistIDFromPageText */
	public function testGetWorklistIDFromPageText( string $wiki, string $prefixedText, ?int $expected ) {
		$store = CampaignEventsServices::getWorklistSecondaryStore();
		$this->assertSame( $expected, $store->getWorklistIDFromPageText( $wiki, $prefixedText ) );
	}

	public static function provideGetWorklistIDFromPageText(): Generator {
		yield 'Exists' => [ 'awiki', 'Worklist 1', 1 ];
		yield 'Does not exist' => [ 'xyzwiki', 'Not a worklist', null ];
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

	/** @dataProvider provideHasWorklistsFromCreator */
	public function testHasWorklistsFromCreator( int $userID, bool $expected ) {
		$store = CampaignEventsServices::getWorklistSecondaryStore();
		$this->assertSame( $expected, $store->hasWorklistsFromCreator( new CentralUser( $userID ) ) );
	}

	public static function provideHasWorklistsFromCreator() {
		yield 'Yes' => [ 101, true ];
		yield 'No' => [ 719843587134, false ];
		yield 'Deleted username' => [ 103, true ];
	}

	public function testUpdateUserName() {
		$store = CampaignEventsServices::getWorklistSecondaryStore();
		$newUserName = 'A new username 1234';
		$store->updateUserName( new CentralUser( 101 ), $newUserName );

		$newData = $this->getDb()->newSelectQueryBuilder()
			->select( [ 'cew_id', 'cew_user_id', 'cew_username' ] )
			->from( 'ce_worklists' )
			->orderBy( 'cew_id' )
			->fetchResultSet();

		$this->assertEquals(
			[
				(object)[
					'cew_id' => 1,
					'cew_user_id' => 101,
					'cew_username' => $newUserName,
				],
				(object)[
					'cew_id' => 2,
					'cew_user_id' => 102,
					'cew_username' => 'User 102',
				],
				(object)[
					'cew_id' => 3,
					'cew_user_id' => 103,
					'cew_username' => null,
				],
			],
			iterator_to_array( $newData )
		);
	}

	public function testUpdateUserVisibility__throwsWhenVisibleAndNoName() {
		$store = CampaignEventsServices::getWorklistSecondaryStore();
		$this->expectException( BadMethodCallException::class );
		$this->expectExceptionMessage( 'Missing required $userName' );
		$store->updateUserVisibility( new CentralUser( 101 ), false );
	}

	/** @dataProvider provideUpdateUserVisibility */
	public function testUpdateUserVisibility(
		int $userID,
		bool $isHidden,
		?string $userName,
		array $expectedRows
	) {
		$store = CampaignEventsServices::getWorklistSecondaryStore();
		$store->updateUserVisibility( new CentralUser( $userID ), $isHidden, $userName );

		$newData = $this->getDb()->newSelectQueryBuilder()
			->select( [ 'cew_id', 'cew_user_id', 'cew_username' ] )
			->from( 'ce_worklists' )
			->fetchResultSet();

		$this->assertEquals( $expectedRows, iterator_to_array( $newData ) );
	}

	public static function provideUpdateUserVisibility(): Generator {
		$getStartingData = static fn () => [
			(object)[
				'cew_id' => 1,
				'cew_user_id' => 101,
				'cew_username' => 'User 101',
			],
			(object)[
				'cew_id' => 2,
				'cew_user_id' => 102,
				'cew_username' => 'User 102',
			],
			(object)[
				'cew_id' => 3,
				'cew_user_id' => 103,
				'cew_username' => null,
			],
		];

		$user101HiddenData = $getStartingData();
		$user101HiddenData[0]->cew_username = null;
		yield 'Hide' => [
			101,
			true,
			null,
			$user101HiddenData,
		];
		yield 'Hide, already hidden' => [
			103,
			true,
			null,
			$getStartingData(),
		];

		$user103UnhiddenData = $getStartingData();
		$user103UnhiddenData[2]->cew_username = 'User 103';
		yield 'Unhide' => [
			103,
			false,
			'User 103',
			$user103UnhiddenData,
		];
		yield 'Unhide, already visible' => [
			101,
			false,
			'User 101',
			$getStartingData(),
		];
	}
}
