<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\EventContribution;

use Generator;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContribution;
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
				'cec_wiki' => 'enwiki',
				'cec_page_id' => 1,
				'cec_page_prefixedtext' => 'Test_Page_1',
				'cec_revision_id' => 123,
				'cec_edit_flags' => 0,
				'cec_bytes_delta' => 100,
				'cec_links_delta' => 5,
				'cec_timestamp' => $db->timestamp( '20240101000000' ),
				'cec_deleted' => 0,
			],
			[
				'cec_id' => 2,
				'cec_event_id' => 1,
				'cec_user_id' => 102,
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
		];

		$db->newInsertQueryBuilder()
			->insertInto( 'ce_event_contributions' )
			->rows( $rows )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * @dataProvider provideSaveEventContribution
	 */
	public function testSaveEventContribution(
		int $eventId,
		int $userId,
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
			$wiki,
			$pagePrefixedtext,
			$pageId,
			$revisionId,
			$editFlags,
			$bytesDelta,
			$linksDelta,
			$timestamp
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
		$this->assertSame( $wiki, $insertedRow->cec_wiki );
		$this->assertSame( $pagePrefixedtext, $insertedRow->cec_page_prefixedtext );
		$this->assertSame( $pageId, (int)$insertedRow->cec_page_id );
		$this->assertSame( $editFlags, (int)$insertedRow->cec_edit_flags );
		$this->assertSame( $bytesDelta, (int)$insertedRow->cec_bytes_delta );
		$this->assertSame( $linksDelta, (int)$insertedRow->cec_links_delta );
	}

	public static function provideSaveEventContribution(): Generator {
		yield 'New contribution' => [ 3, 104, 'frwiki', 'Page_Française', 4, 126, 0, 75, 2, '20240101000003' ];
		yield 'Page creation contribution' => [
			4, 105, 'dewiki', 'Deutsche_Seite', 5, 127, 1, 150, 8, '20240101000004'
		];
	}

	/** @dataProvider provideHasContributionsForPage */
	public function testHasContributionsForPage( ProperPageIdentity $page, bool $exoected ) {
		$store = CampaignEventsServices::getEventContributionStore();
		$this->assertSame( $exoected, $store->hasContributionsForPage( $page ) );
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
			WikiMap::getCurrentWikiId(),
			__METHOD__,
			$pageID,
			123,
			0,
			0,
			0,
			ConvertibleTimestamp::now()
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
		];

		$makeDataWithIDDeleted = static function ( int $id ) use ( $startingData ): array {
			$ret = unserialize( serialize( $startingData ) );
			$ret[$id]->cec_deleted = 1;
			return array_values( $ret );
		};

		yield 'Normal deletion' => [
			'enwiki',
			1,
			$makeDataWithIDDeleted( 1 ),
		];
		yield 'Already deleted' => [
			'enwiki',
			888,
			array_values( $startingData ),
		];
		yield 'Homonym, one already deleted' => [
			'ptwiki',
			1,
			$makeDataWithIDDeleted( 5 ),
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
}
