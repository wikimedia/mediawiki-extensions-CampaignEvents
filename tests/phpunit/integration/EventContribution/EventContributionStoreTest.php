<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\EventContribution;

use Generator;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContribution;
use MediaWikiIntegrationTestCase;

/**
 * @group Test
 * @group Database
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionStore
 * @covers ::__construct()
 */
class EventContributionStoreTest extends MediaWikiIntegrationTestCase {

	/**
	 * @inheritDoc
	 */
	public function addDBData(): void {
		$db = $this->getDb();
		$rows = [
			[
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
		];

		$db->newInsertQueryBuilder()
			->insertInto( 'ce_event_contributions' )
			->rows( $rows )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * Tests associating an edit contribution with an event.
	 * @covers ::saveEventContribution
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

}
