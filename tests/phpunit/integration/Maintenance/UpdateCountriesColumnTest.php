<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Maintenance;

use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\Maintenance\UpdateCountriesColumn;
use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;
use MediaWiki\WikiMap\WikiMap;

/**
 * @group Test
 * @group Database
 * @covers \MediaWiki\Extension\CampaignEvents\Maintenance\UpdateCountriesColumn
 */
class UpdateCountriesColumnTest extends MaintenanceBaseTestCase {
	protected function getMaintenanceClass(): string {
		return UpdateCountriesColumn::class;
	}

	public function addDBData(): void {
		$dbw = CampaignEventsServices::getDatabaseHelper()->getDBConnection( DB_PRIMARY );
		foreach ( range( 1, 3 ) as $id ) {
			$dbw->newInsertQueryBuilder()->insertInto( 'campaign_events' )->row( [
					'event_id' => $id,
					'event_meeting_type' => 2,
					'event_name' => "Test Event $id",
					'event_page_namespace' => NS_EVENT,
					'event_page_title' => "Test_Event_$id",
					'event_page_prefixedtext' => "Event:Test_Event_$id",
					'event_page_wiki' => WikiMap::getCurrentWikiId(),
					'event_chat_url' => '',
					'event_status' => 1,
					'event_meeting_url' => '',
					'event_created_at' => $dbw->timestamp(),
					'event_last_edit' => $dbw->timestamp(),
					'event_deleted_at' => null,
					'event_timezone' => 'Europe/Rome',
					'event_start_local' => '20220815160000',
					'event_start_utc' => $dbw->timestamp( '20220815140000' ),
					'event_end_local' => '20220815180000',
					'event_end_utc' => $dbw->timestamp( '20220815160000' ),
				] )->execute();
		}

		// Insert corresponding addresses
		$dbw->newInsertQueryBuilder()->insertInto( 'ce_address' )->rows( [
				[
					'cea_id' => 101,
					'cea_country' => 'Germany',
					'cea_country_code' => null,
					'cea_full_address' => "123 Main St \n Berlin \n Germany",
				],
				[
					'cea_id' => 102,
					'cea_country' => null,
					'cea_country_code' => null,
					'cea_full_address' => "Unknown \n Planet Earth",
				],
				[
					'cea_id' => 103,
					'cea_country' => 'France',
					'cea_country_code' => null,
					'cea_full_address' => "123 Champs-Élysées \n Paris \n France",
				],
				[
					'cea_id' => 104,
					'cea_country' => 'Germani',
					'cea_country_code' => null,
					'cea_full_address' => "123 Main St \n Berlin \n Germani",
				],
				[
					'cea_id' => 105,
					'cea_country' => 'GeRmAnY',
					'cea_country_code' => null,
					'cea_full_address' => "123 Main St \n Berlin \n GeRmAnY",
				],
				[
					'cea_id' => 106,
					'cea_country' => 'Allemagne',
					'cea_country_code' => null,
					'cea_full_address' => "123 Main St \n Berlin \n Allemagne",
				],
				[
					'cea_id' => 107,
					'cea_country' => 'فرنسا',
					'cea_country_code' => null,
					'cea_full_address' => "123 الشانزليزيه\n باريس،\n فرنسا",
				],
				[
				'cea_id' => 108,
				'cea_country' => 'DR Congo, Congo, Angola',
				'cea_country_code' => null,
				'cea_full_address' => 'JC39+GR5 \n Katako-Kombe \n DR Congo, Congo, Angola',
				],
			] )->execute();

		// Link each event to exactly one address
		$dbw->newInsertQueryBuilder()->insertInto( 'ce_event_address' )->rows( [
				[
					'ceea_event' => 1,
					'ceea_address' => 101
				],
				[
					'ceea_event' => 2,
					'ceea_address' => 102
				],
				[
					'ceea_event' => 3,
					'ceea_address' => 104
				],
				[
					'ceea_event' => 4,
					'ceea_address' => 105
				],
				[
					'ceea_event' => 5,
					'ceea_address' => 106
				],
				[
					'ceea_event' => 6,
					'ceea_address' => 107
				],
				[
					'ceea_event' => 7,
					'ceea_address' => 108
				],
			] )->execute();
	}

	public function testExecuteWithCommit(): void {
		$this->maintenance->loadWithArgv( [ '--commit' ] );
		$this->maintenance->execute();

		$dbr = CampaignEventsServices::getDatabaseHelper()->getDBConnection( DB_REPLICA );
		$results = $dbr->newSelectQueryBuilder()->select(
				[
					'cea_id',
					'cea_country',
					'cea_country_code',
					'cea_full_address'
				]
			)->from( 'ce_address' )->orderBy( 'cea_id' )->caller( __METHOD__ )->fetchResultSet();

		$rows = iterator_to_array( $results );

		// check orphaned address was purged
		$this->assertCount( 7, $rows, 'One orphaned address should have been purged' );
		$remainingIds = array_map( static fn ( $row ) => (int)$row->cea_id, $rows );
		$this->assertNotContains( 103, $remainingIds, 'Address 103 should be purged' );

		// check valid address (Germany) was updated correctly
		$this->assertSame( 101, (int)$rows[0]->cea_id );
		$this->assertSame( 'DE', $rows[0]->cea_country_code );
		$this->assertNull( $rows[0]->cea_country );
		$this->assertStringNotContainsString( 'Germany', $rows[0]->cea_full_address );

		// check invalid address (null country) was assigned VA
		$this->assertSame( 102, (int)$rows[1]->cea_id );
		$this->assertSame( 'VA', $rows[1]->cea_country_code );

		// check valid address with misspelling (Germani) was updated correctly
		$this->assertSame( 104, (int)$rows[2]->cea_id );
		$this->assertSame( 'DE', $rows[2]->cea_country_code );
		$this->assertNull( $rows[2]->cea_country );
		$this->assertStringNotContainsString( 'Germani', $rows[2]->cea_full_address );

		// check valid address (GeRmAnY) was updated correctly (case-insensitive)
		$this->assertSame( 105, (int)$rows[3]->cea_id );
		$this->assertSame( 'DE', $rows[3]->cea_country_code );
		$this->assertNull( $rows[3]->cea_country );
		$this->assertStringNotContainsString( 'GeRmAnY', $rows[3]->cea_full_address );

		// check valid non-english address (Allemagne) was updated correctly
		$this->assertSame( 106, (int)$rows[4]->cea_id );
		$this->assertSame( 'DE', $rows[4]->cea_country_code );
		$this->assertNull( $rows[4]->cea_country );
		$this->assertStringNotContainsString( 'Allemagne', $rows[4]->cea_full_address );

		// check valid non-english RTL address (فرنسا) was updated correctly
		$this->assertSame( 107, (int)$rows[5]->cea_id );
		$this->assertSame( 'FR', $rows[5]->cea_country_code );
		$this->assertNull( $rows[5]->cea_country );
		$this->assertStringNotContainsString( 'فرنسا', $rows[5]->cea_full_address );

		// check exception (DR Congo, Congo, Angola) was updated with default, country left unchanged
		$this->assertSame( 108, (int)$rows[6]->cea_id );
		$this->assertSame( 'VA', $rows[6]->cea_country_code );
		$this->assertSame( 'DR Congo, Congo, Angola', $rows[6]->cea_country );
		$this->assertStringNotContainsString( 'DR Congo, Congo, Angola', $rows[6]->cea_full_address );
	}

	public function testExecuteDryRun(): void {
		$this->maintenance->execute();

		$dbr = CampaignEventsServices::getDatabaseHelper()->getDBConnection( DB_REPLICA );
		$results = $dbr->newSelectQueryBuilder()->select(
				[
					'cea_id',
					'cea_country',
					'cea_country_code',
					'cea_full_address'
				]
			)->from( 'ce_address' )->orderBy( 'cea_id' )->caller( __METHOD__ )->fetchResultSet();

		$rows = iterator_to_array( $results );

		// check orphaned address was not purged
		$this->assertCount( 8, $rows, 'One orphaned address should not have been purged' );
		$remainingIds = array_map( static fn ( $row ) => (int)$row->cea_id, $rows );
		$this->assertContains( 103, $remainingIds, 'Address 103 should not be purged' );

		// check valid address (Germany) was not updated
		$this->assertSame( 101, (int)$rows[0]->cea_id );
		$this->assertSame( null, $rows[0]->cea_country_code );
		$this->assertSame( "Germany", $rows[0]->cea_country );
		$this->assertStringContainsString( 'Germany', $rows[0]->cea_full_address );

		// check invalid address (null country) was not updated
		$this->assertSame( 102, (int)$rows[1]->cea_id );
		$this->assertNull( $rows[1]->cea_country );
		$this->assertNull( $rows[1]->cea_country_code );

		// check valid address with misspelling (Germani) was not updated
		$this->assertSame( 104, (int)$rows[3]->cea_id );
		$this->assertNull( $rows[2]->cea_country_code );
		$this->assertSame( "Germani", $rows[3]->cea_country );
		$this->assertStringContainsString( 'Germani', $rows[3]->cea_full_address );

		// check valid address (GeRmAnY) not updated (case-insensitive)
		$this->assertSame( 105, (int)$rows[4]->cea_id );
		$this->assertNull( $rows[3]->cea_country_code );
		$this->assertSame( "GeRmAnY", $rows[4]->cea_country );
		$this->assertStringContainsString( 'GeRmAnY', $rows[4]->cea_full_address );

		// check valid non-english address (Allemagne) was not updated
		$this->assertSame( 106, (int)$rows[5]->cea_id );
		$this->assertNull( $rows[5]->cea_country_code );
		$this->assertSame( "Allemagne", $rows[5]->cea_country );
		$this->assertStringContainsString( 'Allemagne', $rows[5]->cea_full_address );

		// check valid non-english RTL address (فرنسا) was not updated
		$this->assertSame( 107, (int)$rows[6]->cea_id );
		$this->assertNull( $rows[6]->cea_country_code );
		$this->assertSame( "فرنسا", $rows[6]->cea_country );
		$this->assertStringContainsString( 'فرنسا', $rows[6]->cea_full_address );
	}

	public function testExecuteWithExceptions(): void {
		$this->maintenance->loadWithArgv( [
			'--exceptions',
			'extensions/CampaignEvents/maintenance/countryExceptionMappings.csv',
			'--commit'
		] );
		$this->maintenance->execute();

		$dbr = CampaignEventsServices::getDatabaseHelper()->getDBConnection( DB_REPLICA );
		$results = $dbr->newSelectQueryBuilder()->select(
			[
				'cea_id',
				'cea_country',
				'cea_country_code',
				'cea_full_address'
			]
		)->from( 'ce_address' )->orderBy( 'cea_id' )->caller( __METHOD__ )->fetchResultSet();

		$rows = iterator_to_array( $results );

		// check valid exception (DR Congo, Congo, Angola) was updated correctly
		$this->assertSame( 108, (int)$rows[6]->cea_id );
		$this->assertSame( 'CD', $rows[6]->cea_country_code );
		$this->assertNull( $rows[6]->cea_country );
		$this->assertStringNotContainsString( 'DR Congo, Congo, Angola', $rows[6]->cea_full_address );
	}
}
