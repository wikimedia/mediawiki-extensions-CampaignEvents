<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\EventContribution;

use BadMethodCallException;
use Generator;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContribution;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionSummary;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Page\PageIdentityValue;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @group Test
 * @group Database
 * @covers \MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionStore
 */
class EventContributionStoreTest extends MediaWikiIntegrationTestCase {

	/**
	 * @inheritDoc
	 */
	public function addDBData(): void {
		$db = $this->getDb();
		$rows = [
			[
				'cec_id' => 1,
				'cec_event_id' => 1,
				'cec_user_id' => 101,
				'cec_user_name' => 'User 101',
				'cec_wiki' => 'enwiki',
				'cec_page_id' => 1,
				'cec_page_prefixedtext' => 'Test_Page_1',
				'cec_revision_id' => 123,
				'cec_edit_flags' => 0,
				'cec_bytes_delta' => 100,
				'cec_links_delta' => 1,
				'cec_timestamp' => $db->timestamp( '20240101000000' ),
				'cec_deleted' => 0,
			],
			[
				'cec_id' => 2,
				'cec_event_id' => 1,
				'cec_user_id' => 102,
				'cec_user_name' => 'User 102',
				'cec_wiki' => 'enwiki',
				'cec_page_id' => 2,
				'cec_page_prefixedtext' => 'Test_Page_2',
				'cec_revision_id' => 124,
				'cec_edit_flags' => EventContribution::EDIT_FLAG_PAGE_CREATION,
				'cec_bytes_delta' => 200,
				'cec_links_delta' => 10,
				'cec_timestamp' => $db->timestamp( '20240101000001' ),
				'cec_deleted' => 0,
			],
			[
				'cec_id' => 3,
				'cec_event_id' => 2,
				'cec_user_id' => 103,
				'cec_user_name' => 'User 103',
				'cec_wiki' => 'ptwiki',
				'cec_page_id' => 3,
				'cec_page_prefixedtext' => 'Página_Teste',
				'cec_revision_id' => 125,
				'cec_edit_flags' => 0,
				'cec_bytes_delta' => 50,
				'cec_links_delta' => 2,
				'cec_timestamp' => $db->timestamp( '20240101000002' ),
				'cec_deleted' => 0,
			],
			// Deleted contribution
			[
				'cec_id' => 4,
				'cec_event_id' => 1,
				'cec_user_id' => 999,
				'cec_user_name' => 'User 999',
				'cec_wiki' => 'enwiki',
				'cec_page_id' => 888,
				'cec_page_prefixedtext' => 'Deleted page',
				'cec_revision_id' => 777,
				'cec_edit_flags' => 0,
				'cec_bytes_delta' => 0,
				'cec_links_delta' => 0,
				'cec_timestamp' => $db->timestamp( '20240101112233' ),
				'cec_deleted' => 1,
			],
			// Everything identical to the first record, but on a different wiki
			[
				'cec_id' => 5,
				'cec_event_id' => 1,
				'cec_user_id' => 101,
				'cec_user_name' => 'User 101',
				'cec_wiki' => 'ptwiki',
				'cec_page_id' => 1,
				'cec_page_prefixedtext' => 'Test_Page_1',
				'cec_revision_id' => 123,
				'cec_edit_flags' => 0,
				'cec_bytes_delta' => 100,
				'cec_links_delta' => 5,
				'cec_timestamp' => $db->timestamp( '20240101000000' ),
				'cec_deleted' => 0,
			],
			// Same as the previous, but a different revision and also deleted
			[
				'cec_id' => 6,
				'cec_event_id' => 1,
				'cec_user_id' => 101,
				'cec_user_name' => 'User 101',
				'cec_wiki' => 'ptwiki',
				'cec_page_id' => 1,
				'cec_page_prefixedtext' => 'Test_Page_1',
				'cec_revision_id' => 987,
				'cec_edit_flags' => 0,
				'cec_bytes_delta' => 100,
				'cec_links_delta' => 5,
				'cec_timestamp' => $db->timestamp( '20240101000000' ),
				'cec_deleted' => 1,
			],
			// Negative deltas
			[
				'cec_id' => 7,
				'cec_event_id' => 1,
				'cec_user_id' => 101,
				'cec_user_name' => 'User 101',
				'cec_wiki' => 'enwiki',
				'cec_page_id' => 1,
				'cec_page_prefixedtext' => 'Test_Page_1',
				'cec_revision_id' => 127,
				'cec_edit_flags' => 0,
				'cec_bytes_delta' => -7,
				'cec_links_delta' => -3,
				'cec_timestamp' => $db->timestamp( '20240101000005' ),
				'cec_deleted' => 0,
			],
			// Private participant contribution on another wiki
			[
				'cec_id' => 8,
				'cec_event_id' => 1,
				'cec_user_id' => 109,
				'cec_user_name' => 'User 109',
				'cec_wiki' => 'zzwiki',
				'cec_page_id' => 1,
				'cec_page_prefixedtext' => 'Secret page',
				'cec_revision_id' => 120,
				'cec_edit_flags' => 0,
				'cec_bytes_delta' => -13,
				'cec_links_delta' => 0,
				'cec_timestamp' => $db->timestamp( '20240101000006' ),
				'cec_deleted' => 0,
			],
			// Hidden/deleted user
			[
				'cec_id' => 9,
				'cec_event_id' => 10,
				'cec_user_id' => 987654321,
				'cec_user_name' => null,
				'cec_wiki' => 'enwiki',
				'cec_page_id' => 99,
				'cec_page_prefixedtext' => 'Page 99',
				'cec_revision_id' => 1234,
				'cec_edit_flags' => 0,
				'cec_bytes_delta' => 88,
				'cec_links_delta' => -3,
				'cec_timestamp' => $db->timestamp( '20250101000006' ),
				'cec_deleted' => 0,
			],
		];

		$db->newInsertQueryBuilder()
			->insertInto( 'ce_event_contributions' )
			->rows( $rows )
			->caller( __METHOD__ )
			->execute();

		// Add some participants for visibility checks (don't need to add all of them)
		$participantStore = CampaignEventsServices::getParticipantsStore();
		$participantStore->addParticipantToEvent( 1, new CentralUser( 101 ), false, [] );
		$participantStore->addParticipantToEvent( 1, new CentralUser( 102 ), true, [] );
		$participantStore->addParticipantToEvent( 1, new CentralUser( 109 ), true, [] );
	}

	/**
	 * @dataProvider provideSaveEventContribution
	 */
	public function testSaveEventContribution(
		int $eventId,
		int $userId,
		string $userName,
		string $wiki,
		string $pagePrefixedtext,
		int $pageId,
		int $revisionId,
		int $editFlags,
		int $bytesDelta,
		int $linksDelta,
		string $timestamp
	): void {
		$store = CampaignEventsServices::getEventContributionStore();
		$contribution = new EventContribution(
			$eventId,
			$userId,
			$userName,
			$wiki,
			$pagePrefixedtext,
			$pageId,
			$revisionId,
			$editFlags,
			$bytesDelta,
			$linksDelta,
			$timestamp,
			false
		);

		$store->saveEventContribution( $contribution );

		// Verify the data was actually inserted
		$db = $this->getDb();
		$insertedRow = $db->newSelectQueryBuilder()
			->select( '*' )
			->from( 'ce_event_contributions' )
			->where( [
				'cec_event_id' => $eventId,
				'cec_user_id' => $userId,
				'cec_revision_id' => $revisionId
			] )
			->fetchRow();
		$this->assertNotNull( $insertedRow, 'Record should have been inserted' );
		$this->assertSame( $userName, $insertedRow->cec_user_name );
		$this->assertSame( $wiki, $insertedRow->cec_wiki );
		$this->assertSame( $pagePrefixedtext, $insertedRow->cec_page_prefixedtext );
		$this->assertSame( $pageId, (int)$insertedRow->cec_page_id );
		$this->assertSame( $editFlags, (int)$insertedRow->cec_edit_flags );
		$this->assertSame( $bytesDelta, (int)$insertedRow->cec_bytes_delta );
		$this->assertSame( $linksDelta, (int)$insertedRow->cec_links_delta );
	}

	public static function provideSaveEventContribution(): Generator {
		yield 'New contribution' => [
			3, 104, 'User 104', 'frwiki', 'Page_Française', 4, 126, 0, 75, 2, '20240101000003'
		];
		yield 'Page creation contribution' => [
			4, 105, 'User 105', 'dewiki', 'Deutsche_Seite', 5, 127, 1, 150, 8, '20240101000004'
		];
	}

	/** @dataProvider provideGetEventSummaryData */
	public function testGetEventSummaryData(
		int $eventID,
		int $userID,
		bool $canSeePrivate,
		EventContributionSummary $expected
	) {
		$store = CampaignEventsServices::getEventContributionStore();
		$actual = $store->getEventSummaryData( $eventID, $userID, $canSeePrivate );
		$this->assertEquals( $expected, $actual );
	}

	public static function provideGetEventSummaryData(): Generator {
		yield 'Event with no contributions' => [
			99999,
			1234,
			true,
			new EventContributionSummary( 0, 0, 0, 0, 0, 0, 0, 0, 0 )
		];
		yield 'Can see private, not a participant' => [
			1,
			888888,
			true,
			new EventContributionSummary( 3, 3, 1, 3, 400, -20, 16, -3, 5 )
		];
		yield 'Can see private, public participant' => [
			1,
			101,
			true,
			new EventContributionSummary( 3, 3, 1, 3, 400, -20, 16, -3, 5 )
		];
		yield 'Can see private, private participant' => [
			1,
			102,
			true,
			new EventContributionSummary( 3, 3, 1, 3, 400, -20, 16, -3, 5 )
		];
		yield 'Cannot see private, not a participant' => [
			1,
			888888,
			false,
			new EventContributionSummary( 1, 2, 0, 2, 200, -7, 6, -3, 3 )
		];
		yield 'Cannot see private, public participant' => [
			1,
			101,
			false,
			new EventContributionSummary( 1, 2, 0, 2, 200, -7, 6, -3, 3 )
		];
		yield 'Cannot see private, private participant' => [
			1,
			102,
			false,
			new EventContributionSummary( 2, 2, 1, 2, 400, -7, 16, -3, 4 )
		];
	}

	/** @dataProvider provideHasContributionsForPage */
	public function testHasContributionsForPage( ProperPageIdentity $page, bool $expected ) {
		$store = CampaignEventsServices::getEventContributionStore();
		$this->assertSame( $expected, $store->hasContributionsForPage( $page ) );
	}

	public static function provideHasContributionsForPage() {
		yield 'No contributions, wiki with contributions' => [
			new PageIdentityValue( 99999, NS_MAIN, 'Foo', 'enwiki' ),
			false
		];
		yield 'No contributions, homonym in another wiki' => [
			new PageIdentityValue( 1, NS_MAIN, 'Test_Page_1', 'someotherwiki' ),
			false
		];
		yield 'Has contributions' => [
			new PageIdentityValue( 1, NS_MAIN, 'Foo', 'enwiki' ),
			true
		];
	}

	public function testHasContributionsForPage__currentWiki() {
		$pageID = 9876;
		$localContribution = new EventContribution(
			123,
			456,
			'User 456',
			WikiMap::getCurrentWikiId(),
			__METHOD__,
			$pageID,
			123,
			0,
			0,
			0,
			ConvertibleTimestamp::now(),
			false
		);
		$store = CampaignEventsServices::getEventContributionStore();
		$store->saveEventContribution( $localContribution );
		$localPage = new PageIdentityValue( $pageID, NS_MAIN, __METHOD__, PageIdentityValue::LOCAL );
		$this->assertTrue( $store->hasContributionsForPage( $localPage ) );
	}

	public function testUpdateTitle() {
		$store = CampaignEventsServices::getEventContributionStore();
		$newPrefixedText = 'New page prefixedtext';
		$store->updateTitle( 'enwiki', 1, $newPrefixedText );

		$newData = $this->getDb()->newSelectQueryBuilder()
			->select( [ 'cec_id', 'cec_page_id', 'cec_page_prefixedtext' ] )
			->from( 'ce_event_contributions' )
			->fetchResultSet();

		$this->assertEquals(
			[
				(object)[
					'cec_id' => 1,
					'cec_page_id' => 1,
					'cec_page_prefixedtext' => $newPrefixedText,
				],
				(object)[
					'cec_id' => 2,
					'cec_page_id' => 2,
					'cec_page_prefixedtext' => 'Test_Page_2',
				],
				(object)[
					'cec_id' => 3,
					'cec_page_id' => 3,
					'cec_page_prefixedtext' => 'Página_Teste',
				],
				(object)[
					'cec_id' => 4,
					'cec_page_id' => 888,
					'cec_page_prefixedtext' => 'Deleted page',
				],
				(object)[
					'cec_id' => 5,
					'cec_page_id' => 1,
					'cec_page_prefixedtext' => 'Test_Page_1',
				],
				(object)[
					'cec_id' => 6,
					'cec_page_id' => 1,
					'cec_page_prefixedtext' => 'Test_Page_1',
				],
				(object)[
					'cec_id' => 7,
					'cec_page_id' => 1,
					'cec_page_prefixedtext' => $newPrefixedText,
				],
				(object)[
					'cec_id' => 8,
					'cec_page_id' => 1,
					'cec_page_prefixedtext' => 'Secret page',
				],
				(object)[
					'cec_id' => 9,
					'cec_page_id' => 99,
					'cec_page_prefixedtext' => 'Page 99',
				],
			],
			iterator_to_array( $newData )
		);
	}

	/** @dataProvider provideUpdateForPageDeleted */
	public function testUpdateForPageDeleted( string $wiki, int $pageID, array $expectedRows ) {
		$store = CampaignEventsServices::getEventContributionStore();
		$store->updateForPageDeleted( $wiki, $pageID );

		$newData = $this->getDb()->newSelectQueryBuilder()
			->select( [ 'cec_id', 'cec_page_id', 'cec_deleted' ] )
			->from( 'ce_event_contributions' )
			->fetchResultSet();

		$this->assertEquals( $expectedRows, iterator_to_array( $newData ) );
	}

	public static function provideUpdateForPageDeleted(): Generator {
		$startingData = [
			1 => (object)[
				'cec_id' => 1,
				'cec_page_id' => 1,
				'cec_deleted' => 0,
			],
			2 => (object)[
				'cec_id' => 2,
				'cec_page_id' => 2,
				'cec_deleted' => 0,
			],
			3 => (object)[
				'cec_id' => 3,
				'cec_page_id' => 3,
				'cec_deleted' => 0,
			],
			4 => (object)[
				'cec_id' => 4,
				'cec_page_id' => 888,
				'cec_deleted' => 1,
			],
			5 => (object)[
				'cec_id' => 5,
				'cec_page_id' => 1,
				'cec_deleted' => 0,
			],
			6 => (object)[
				'cec_id' => 6,
				'cec_page_id' => 1,
				'cec_deleted' => 1,
			],
			7 => (object)[
				'cec_id' => 7,
				'cec_page_id' => 1,
				'cec_deleted' => 0,
			],
			8 => (object)[
				'cec_id' => 8,
				'cec_page_id' => 1,
				'cec_deleted' => 0,
			],
			9 => (object)[
				'cec_id' => 9,
				'cec_page_id' => 99,
				'cec_deleted' => 0,
			],
		];

		$makeDataWithIDsDeleted = static function ( int ...$ids ) use ( $startingData ): array {
			$ret = unserialize( serialize( $startingData ) );
			foreach ( $ids as $id ) {
				$ret[$id]->cec_deleted = 1;
			}
			return array_values( $ret );
		};

		yield 'Normal deletion' => [
			'enwiki',
			1,
			$makeDataWithIDsDeleted( 1, 7 ),
		];
		yield 'Already deleted' => [
			'enwiki',
			888,
			array_values( $startingData ),
		];
		yield 'Homonym, one already deleted' => [
			'ptwiki',
			1,
			$makeDataWithIDsDeleted( 5 ),
		];
	}

	/** @dataProvider provideUpdateForPageRestored */
	public function testUpdateForPageRestored( string $wiki, int $pageID, array $expectedRows ) {
		$store = CampaignEventsServices::getEventContributionStore();
		$store->updateForPageRestored( $wiki, $pageID );

		$newData = $this->getDb()->newSelectQueryBuilder()
			->select( [ 'cec_id', 'cec_page_id', 'cec_deleted' ] )
			->from( 'ce_event_contributions' )
			->fetchResultSet();

		$this->assertEquals( $expectedRows, iterator_to_array( $newData ) );
	}

	public static function provideUpdateForPageRestored(): Generator {
		$startingData = [
			1 => (object)[
				'cec_id' => 1,
				'cec_page_id' => 1,
				'cec_deleted' => 0,
			],
			2 => (object)[
				'cec_id' => 2,
				'cec_page_id' => 2,
				'cec_deleted' => 0,
			],
			3 => (object)[
				'cec_id' => 3,
				'cec_page_id' => 3,
				'cec_deleted' => 0,
			],
			4 => (object)[
				'cec_id' => 4,
				'cec_page_id' => 888,
				'cec_deleted' => 1,
			],
			5 => (object)[
				'cec_id' => 5,
				'cec_page_id' => 1,
				'cec_deleted' => 0,
			],
			6 => (object)[
				'cec_id' => 6,
				'cec_page_id' => 1,
				'cec_deleted' => 1,
			],
			7 => (object)[
				'cec_id' => 7,
				'cec_page_id' => 1,
				'cec_deleted' => 0,
			],
			8 => (object)[
				'cec_id' => 8,
				'cec_page_id' => 1,
				'cec_deleted' => 0,
			],
			9 => (object)[
				'cec_id' => 9,
				'cec_page_id' => 99,
				'cec_deleted' => 0,
			],
		];

		$makeDataWithIDVisible = static function ( int $id ) use ( $startingData ): array {
			$ret = unserialize( serialize( $startingData ) );
			$ret[$id]->cec_deleted = 0;
			return array_values( $ret );
		};

		yield 'Normal restore' => [
			'enwiki',
			888,
			$makeDataWithIDVisible( 4 ),
		];
		yield 'Not deleted' => [
			'enwiki',
			1,
			array_values( $startingData ),
		];
		yield 'Homonym, one already deleted' => [
			'ptwiki',
			1,
			$makeDataWithIDVisible( 6 ),
		];
	}

	/** @dataProvider provideUpdateRevisionVisibility */
	public function testUpdateRevisionVisibility(
		string $wiki,
		int $pageID,
		array $deletedRevIDs,
		array $restoredRevIDs,
		array $expectedRows
	) {
		$store = CampaignEventsServices::getEventContributionStore();
		$store->updateRevisionVisibility( $wiki, $pageID, $deletedRevIDs, $restoredRevIDs );

		$newData = $this->getDb()->newSelectQueryBuilder()
			->select( [ 'cec_id', 'cec_revision_id', 'cec_deleted' ] )
			->from( 'ce_event_contributions' )
			->fetchResultSet();

		$this->assertEquals( $expectedRows, iterator_to_array( $newData ) );
	}

	public static function provideUpdateRevisionVisibility(): Generator {
		$startingData = [
			1 => (object)[
				'cec_id' => 1,
				'cec_revision_id' => 123,
				'cec_deleted' => 0,
			],
			2 => (object)[
				'cec_id' => 2,
				'cec_revision_id' => 124,
				'cec_deleted' => 0,
			],
			3 => (object)[
				'cec_id' => 3,
				'cec_revision_id' => 125,
				'cec_deleted' => 0,
			],
			4 => (object)[
				'cec_id' => 4,
				'cec_revision_id' => 777,
				'cec_deleted' => 1,
			],
			5 => (object)[
				'cec_id' => 5,
				'cec_revision_id' => 123,
				'cec_deleted' => 0,
			],
			6 => (object)[
				'cec_id' => 6,
				'cec_revision_id' => 987,
				'cec_deleted' => 1,
			],
			7 => (object)[
				'cec_id' => 7,
				'cec_revision_id' => 127,
				'cec_deleted' => 0,
			],
			8 => (object)[
				'cec_id' => 8,
				'cec_revision_id' => 120,
				'cec_deleted' => 0,
			],
			9 => (object)[
				'cec_id' => 9,
				'cec_revision_id' => 1234,
				'cec_deleted' => 0,
			],
		];

		$updateDataWithDeletedMap = static function ( array $map ) use ( $startingData ): array {
			$ret = unserialize( serialize( $startingData ) );
			foreach ( $map as $id => $del ) {
				$ret[$id]->cec_deleted = $del;
			}
			return array_values( $ret );
		};

		yield 'Delete only, single' => [
			'enwiki',
			1,
			[ 123 ],
			[],
			$updateDataWithDeletedMap( [ 1 => 1 ] ),
		];
		yield 'Delete only, single, already deleted' => [
			'enwiki',
			888,
			[ 777 ],
			[],
			array_values( $startingData ),
		];
		yield 'Delete only, multiple, some already deleted' => [
			'ptwiki',
			1,
			[ 123, 987 ],
			[],
			$updateDataWithDeletedMap( [ 5 => 1 ] ),
		];

		yield 'Restore only, single' => [
			'enwiki',
			888,
			[],
			[ 777 ],
			$updateDataWithDeletedMap( [ 4 => 0 ] ),
		];
		yield 'Restore only, single, already visible' => [
			'ptwiki',
			3,
			[],
			[ 125 ],
			array_values( $startingData ),
		];
		yield 'Restore only, multiple, some already visible' => [
			'ptwiki',
			1,
			[],
			[ 123, 987 ],
			$updateDataWithDeletedMap( [ 6 => 0 ] ),
		];

		yield 'Both deletion and undeletion' => [
			'ptwiki',
			1,
			[ 123 ],
			[ 987 ],
			$updateDataWithDeletedMap( [ 5 => 1, 6 => 0 ] ),
		];
	}

	public function testGetByID_returnsRowOrNull(): void {
		$store = CampaignEventsServices::getEventContributionStore();
		// Existing ID from addDBData
		$existing = $store->getByID( 1 );
		$this->assertInstanceOf( EventContribution::class, $existing );
		$this->assertSame( 1, $existing->getEventId() );
		// Non-existing ID
		$this->assertNull( $store->getByID( 999999 ) );
	}

	public function testDeleteByID_deletesOneRow(): void {
		$db = $this->getDb();
		$store = CampaignEventsServices::getEventContributionStore();
		// Sanity: row with cec_id=2 exists
		$before = $db->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( 'ce_event_contributions' )
			->where( [ 'cec_id' => 2 ] )
			->fetchField();
		$this->assertSame( 1, (int)$before );
		// Delete and verify removed
		$store->deleteByID( 2 );
		$after = $db->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( 'ce_event_contributions' )
			->where( [ 'cec_id' => 2 ] )
			->fetchField();
		$this->assertSame( 0, (int)$after );
		// Ensure another row remains unaffected
		$stillThere = $db->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( 'ce_event_contributions' )
			->where( [ 'cec_id' => 3 ] )
			->fetchField();
		$this->assertSame( 1, (int)$stillThere );
	}

	public function testDeleteByID_idempotentOnMissing(): void {
		$db = $this->getDb();
		$store = CampaignEventsServices::getEventContributionStore();
		// Count total rows before
		$totalBefore = $db->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( 'ce_event_contributions' )
			->fetchField();
		// Delete non-existent
		$store->deleteByID( 999999 );
		// Count should remain the same
		$totalAfter = $db->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( 'ce_event_contributions' )
			->fetchField();
		$this->assertSame( (int)$totalBefore, (int)$totalAfter );
	}

	/** @dataProvider provideGetEventIDForRevision */
	public function testGetEventIDForRevision( string $wikiID, int $revisionID, ?int $expected ): void {
		$store = CampaignEventsServices::getEventContributionStore();
		$this->assertSame( $expected, $store->getEventIDForRevision( $wikiID, $revisionID ) );
	}

	public static function provideGetEventIDForRevision(): Generator {
		yield 'Associated' => [ 'enwiki', 123, 1 ];
		yield 'Not associated' => [ 'enwiki', 192837, null ];
		yield 'Associated with revision of same ID but different wiki' => [ 'ptwiki', 777, null ];
	}

	/** @dataProvider provideHasContributionsFromUser */
	public function testHasContributionsFromUser( int $userID, bool $expected ) {
		$store = CampaignEventsServices::getEventContributionStore();
		$this->assertSame( $expected, $store->hasContributionsFromUser( new CentralUser( $userID ) ) );
	}

	public static function provideHasContributionsFromUser() {
		yield 'Yes' => [ 101, true ];
		yield 'No' => [ 7654567, false ];
		yield 'Deleted contributions only' => [ 999, true ];
		yield 'Deleted username' => [ 987654321, true ];
	}

	public function testUpdateUserName() {
		$store = CampaignEventsServices::getEventContributionStore();
		$newUserName = 'A new username 1234';
		$store->updateUserName( new CentralUser( 101 ), $newUserName );

		$newData = $this->getDb()->newSelectQueryBuilder()
			->select( [ 'cec_id', 'cec_user_id', 'cec_user_name' ] )
			->from( 'ce_event_contributions' )
			->orderBy( 'cec_id' )
			->fetchResultSet();

		$this->assertEquals(
			[
				(object)[
					'cec_id' => 1,
					'cec_user_id' => 101,
					'cec_user_name' => $newUserName,
				],
				(object)[
					'cec_id' => 2,
					'cec_user_id' => 102,
					'cec_user_name' => 'User 102',
				],
				(object)[
					'cec_id' => 3,
					'cec_user_id' => 103,
					'cec_user_name' => 'User 103',
				],
				(object)[
					'cec_id' => 4,
					'cec_user_id' => 999,
					'cec_user_name' => 'User 999',
				],
				(object)[
					'cec_id' => 5,
					'cec_user_id' => 101,
					'cec_user_name' => $newUserName,
				],
				(object)[
					'cec_id' => 6,
					'cec_user_id' => 101,
					'cec_user_name' => $newUserName,
				],
				(object)[
					'cec_id' => 7,
					'cec_user_id' => 101,
					'cec_user_name' => $newUserName,
				],
				(object)[
					'cec_id' => 8,
					'cec_user_id' => 109,
					'cec_user_name' => 'User 109',
				],
				(object)[
					'cec_id' => 9,
					'cec_user_id' => 987654321,
					'cec_user_name' => null,
				],
			],
			iterator_to_array( $newData )
		);
	}

	public function testUpdateUserVisibility__throwsWhenVisibleAndNoName() {
		$store = CampaignEventsServices::getEventContributionStore();
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
		$store = CampaignEventsServices::getEventContributionStore();
		$store->updateUserVisibility( new CentralUser( $userID ), $isHidden, $userName );

		$newData = $this->getDb()->newSelectQueryBuilder()
			// Includes cec_deleted to verify that it remains unchanged
			->select( [ 'cec_id', 'cec_user_id', 'cec_user_name', 'cec_deleted' ] )
			->from( 'ce_event_contributions' )
			->fetchResultSet();

		$this->assertEquals( $expectedRows, iterator_to_array( $newData ) );
	}

	public static function provideUpdateUserVisibility(): Generator {
		$startingData = [
			1 => (object)[
				'cec_id' => 1,
				'cec_user_id' => 101,
				'cec_user_name' => 'User 101',
				'cec_deleted' => 0,
			],
			2 => (object)[
				'cec_id' => 2,
				'cec_user_id' => 102,
				'cec_user_name' => 'User 102',
				'cec_deleted' => 0,
			],
			3 => (object)[
				'cec_id' => 3,
				'cec_user_id' => 103,
				'cec_user_name' => 'User 103',
				'cec_deleted' => 0,
			],
			4 => (object)[
				'cec_id' => 4,
				'cec_user_id' => 999,
				'cec_user_name' => 'User 999',
				'cec_deleted' => 1,
			],
			5 => (object)[
				'cec_id' => 5,
				'cec_user_id' => 101,
				'cec_user_name' => 'User 101',
				'cec_deleted' => 0,
			],
			6 => (object)[
				'cec_id' => 6,
				'cec_user_id' => 101,
				'cec_user_name' => 'User 101',
				'cec_deleted' => 1,
			],
			7 => (object)[
				'cec_id' => 7,
				'cec_user_id' => 101,
				'cec_user_name' => 'User 101',
				'cec_deleted' => 0,
			],
			8 => (object)[
				'cec_id' => 8,
				'cec_user_id' => 109,
				'cec_user_name' => 'User 109',
				'cec_deleted' => 0,
			],
			9 => (object)[
				'cec_id' => 9,
				'cec_user_id' => 987654321,
				'cec_user_name' => null,
				'cec_deleted' => 0,
			],
		];

		$user101HiddenData = [];
		foreach ( $startingData as $row ) {
			$addRow = clone $row;
			if ( $addRow->cec_user_id === 101 ) {
				$addRow->cec_user_name = null;
			}
			$user101HiddenData[] = $addRow;
		}
		yield 'Hide' => [
			101,
			true,
			null,
			$user101HiddenData,
		];
		yield 'Hide, already hidden' => [
			987654321,
			true,
			null,
			array_values( $startingData ),
		];

		$dataWithUnhiddenUser = array_replace(
			$startingData,
			[
				9 => (object)[
					'cec_id' => 9,
					'cec_user_id' => 987654321,
					'cec_user_name' => 'User 987654321',
					'cec_deleted' => 0,
				],
			]
		);
		yield 'Unhide' => [
			987654321,
			false,
			'User 987654321',
			array_values( $dataWithUnhiddenUser ),
		];
		yield 'Unhide, already visible' => [
			101,
			false,
			'User 101',
			array_values( $startingData ),
		];
	}
}
