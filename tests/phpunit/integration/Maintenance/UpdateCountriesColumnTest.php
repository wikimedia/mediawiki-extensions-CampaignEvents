<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Maintenance;

use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventStore;
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
		foreach ( range( 1, 20 ) as $id ) {
			$dbw->newInsertQueryBuilder()->insertInto( 'campaign_events' )->row( [
					'event_id' => $id,
					'event_meeting_type' =>
						EventStore::PARTICIPATION_OPTION_MAP[EventRegistration::PARTICIPATION_OPTION_IN_PERSON],
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
			// valid
				[
					'cea_id' => 101,
					'cea_country' => 'Germany',
					'cea_country_code' => null,
					'cea_full_address' => "123 Main St \n Berlin \n Germany",
				],
			// invalid (no country)
				[
					'cea_id' => 102,
					'cea_country' => null,
					'cea_country_code' => null,
					'cea_full_address' => "Unknown \n Planet Earth",
				],
			// no event
				[
					'cea_id' => 103,
					'cea_country' => 'France',
					'cea_country_code' => null,
					'cea_full_address' => "123 Champs-Élysées \n Paris \n France",
				],
			// misspelling
				[
					'cea_id' => 104,
					'cea_country' => 'Germani',
					'cea_country_code' => null,
					'cea_full_address' => "123 Main St \n Berlin \n Germani",
				],
			// case insensitive
				[
					'cea_id' => 105,
					'cea_country' => 'GeRmAnY',
					'cea_country_code' => null,
					'cea_full_address' => "123 Main St \n Berlin \n GeRmAnY",
				],
			// non-english, valid
				[
					'cea_id' => 106,
					'cea_country' => 'Allemagne',
					'cea_country_code' => null,
					'cea_full_address' => "123 Main St \n Berlin \n Allemagne",
				],
			// rtl language, valid
				[
					'cea_id' => 107,
					'cea_country' => 'فرنسا',
					'cea_country_code' => null,
					'cea_full_address' => "123 الشانزليزيه \n  باريس، \n فرنسا",
				],
			// exception, valid
				[
					'cea_id' => 108,
					'cea_country' => 'DR Congo, Congo, Angola',
					'cea_country_code' => null,
					'cea_full_address' => "JC39+GR5 \n Katako-Kombe \n DR Congo, Congo, Angola",
				],
			// No address
				[
					'cea_id' => 109,
					'cea_country' => 'France',
					'cea_country_code' => null,
					'cea_full_address' => " \n France",
				],
			// Row already updated
				[
					'cea_id' => 110,
					'cea_country' => 'France',
					'cea_country_code' => 'FR',
					'cea_full_address' => 'No country here',
				],
			] )->execute();

		// Link each event to exactly one address
		$dbw->newInsertQueryBuilder()->insertInto( 'ce_event_address' )->rows( [
				[ 'ceea_event' => 1, 'ceea_address' => 101 ],
				[ 'ceea_event' => 2, 'ceea_address' => 102 ],
				[ 'ceea_event' => 3, 'ceea_address' => 104 ],
				[ 'ceea_event' => 4, 'ceea_address' => 105 ],
				[ 'ceea_event' => 5, 'ceea_address' => 106 ],
				[ 'ceea_event' => 6, 'ceea_address' => 107 ],
				[ 'ceea_event' => 7, 'ceea_address' => 108 ],
				[ 'ceea_event' => 8, 'ceea_address' => 109 ],
				[ 'ceea_event' => 9, 'ceea_address' => 110 ],
			] )->execute();
	}

	public function testExecuteWithCommit(): void {
		$this->maintenance->loadWithArgv( [ '--commit' ] );
		$this->overrideConfigValue( 'CampaignEventsCountrySchemaMigrationStage', SCHEMA_COMPAT_WRITE_NEW );
		$this->maintenance->execute();

		$this->assertSelect(
			'ce_address', [
				'cea_id',
				'cea_country',
				'cea_country_code',
				'cea_full_address',
			], '*',
			[
				[
					'0' => '101',
					'1' => null,
					'2' => 'DE',
					'3' => "123 Main St \n Berlin"
				],
				[
					'0' => '104',
					'1' => null,
					'2' => 'DE',
					'3' => "123 Main St \n Berlin"
				],
				[
					'0' => '105',
					'1' => null,
					'2' => 'DE',
					'3' => "123 Main St \n Berlin"
				],
				[
					'0' => '106',
					'1' => null,
					'2' => 'DE',
					'3' => "123 Main St \n Berlin"
				],
				[
					'0' => '107',
					'1' => null,
					'2' => 'FR',
					'3' => "123 الشانزليزيه \n  باريس،",
				],
				[
					'0' => '109',
					'1' => null,
					'2' => 'FR',
					'3' => ""
				],
				[
					'0' => '110',
					'1' => "France",
					'2' => 'FR',
					'3' => "No country here"
				],
			], [ 'ORDER BY' => 'cea_id' ]
		);
		// Purge check
		$this->assertSelect(
			'ce_event_address',
			[ 'ceea_address' ],
			'*',
			[
				[ '101' ],
				[ '104' ],
				[ '105' ],
				[ '106' ],
				[ '107' ],
				[ '109' ],
				[ '110' ],
			],
			[ 'ORDER BY' => 'ceea_address' ]
		);

		// Verify that invalid events are now ONLINE (value = 1)
		$this->assertSelect(
			'campaign_events',
			[ 'event_id' ],
			[ 'event_meeting_type' => EventStore::PARTICIPATION_OPTION_MAP[
			EventRegistration::PARTICIPATION_OPTION_ONLINE
			] ],
			[
				[ '2' ],
				[ '7' ],
				[ '10' ],
				[ '11' ],
				[ '12' ],
				[ '13' ],
				[ '14' ],
				[ '15' ],
				[ '16' ],
				[ '17' ],
				[ '18' ],
				[ '19' ],
				[ '20' ],
			]
		);
	}

	public function testExecuteDryRun(): void {
		$this->maintenance->execute();

		$this->assertSelect(
			'ce_address',
			[ 'cea_id', 'cea_country', 'cea_country_code' ],
			'*',
			[
				[ '0' => '101', '1' => 'Germany', '2' => null ],
				[ '0' => '102', '1' => null, '2' => null ],
				[ '0' => '103', '1' => 'France', '2' => null ],
				[ '0' => '104', '1' => 'Germani', '2' => null ],
				[ '0' => '105', '1' => 'GeRmAnY', '2' => null ],
				[ '0' => '106', '1' => 'Allemagne', '2' => null ],
				[ '0' => '107', '1' => 'فرنسا', '2' => null ],
				[ '0' => '108', '1' => 'DR Congo, Congo, Angola', '2' => null ],
				[ '0' => '109', '1' => 'France', '2' => null ],
				[ '0' => '110', '1' => 'France', '2' => 'FR' ],
			],
			[ 'ORDER BY' => 'cea_id' ]
		);
		$output = $this->getActualOutput();

		$this->assertStringContainsString(
			'1 Purged rows',
			$output,
			'Should display purged rows section in dry run'
		);

		$this->assertStringContainsString(
			'11 Events made online',
			$output,
			'Should display Events to online section in dry run'
		);

		$this->assertStringContainsString(
			'6 Matches',
			$output,
			'Should display correct number of matched country conversions'
		);

		$this->assertStringContainsString(
			'2 Unmatched',
			$output,
			'Should display correct number of unmatched rows'
		);
	}

	public function testExecuteWithExceptions(): void {
		$this->maintenance->loadWithArgv( [
			'--exceptions',
			'extensions/CampaignEvents/maintenance/countryExceptionMappings.csv',
			'--commit'
		] );
		$this->overrideConfigValue( 'CampaignEventsCountrySchemaMigrationStage', SCHEMA_COMPAT_WRITE_NEW );
		$this->maintenance->execute();

		$this->assertSelect(
			'ce_address',
			[ 'cea_id', 'cea_country', 'cea_country_code', 'cea_full_address' ],
			[ 'cea_id' => 108 ],
			[
				[ '0' => '108', '1' => null, '2' => 'CD', '3' => "JC39+GR5 \n Katako-Kombe" ]
			]
		);
	}
}
