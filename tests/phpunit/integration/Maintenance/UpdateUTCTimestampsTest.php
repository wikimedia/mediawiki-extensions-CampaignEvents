<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Maintenance;

use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\Maintenance\UpdateUTCTimestamps;
use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;
use MediaWiki\WikiMap\WikiMap;
use stdClass;

/**
 * @group Test
 * @group Database
 * @covers \MediaWiki\Extension\CampaignEvents\Maintenance\UpdateUTCTimestamps
 */
class UpdateUTCTimestampsTest extends MaintenanceBaseTestCase {

	/** @inheritDoc */
	protected $tablesUsed = [ 'campaign_events' ];

	/** @var stdClass[]|null Rows added by addDBData() */
	private $oldRows;

	/**
	 * @inheritDoc
	 */
	protected function getMaintenanceClass(): string {
		return UpdateUTCTimestamps::class;
	}

	/**
	 * @inheritDoc
	 */
	public function addDBData() {
		$dbw = CampaignEventsServices::getDatabaseHelper()->getDBConnection( DB_PRIMARY );
		$baseRow = [
			'event_name' => 'Test',
			'event_page_namespace' => NS_EVENT,
			'event_page_wiki' => WikiMap::getCurrentWikiId(),
			'event_chat_url' => '',
			'event_tracking_tool_id' => null,
			'event_tracking_tool_event_id' => null,
			'event_status' => 1,
			'event_type' => 'generic',
			'event_meeting_type' => 2,
			'event_meeting_url' => '',
			'event_created_at' => $dbw->timestamp(),
			'event_last_edit' => $dbw->timestamp(),
			'event_deleted_at' => null,
		];

		$getRow = static function ( array $timeFields ) use ( $baseRow ): array {
			static $lastID = 0;
			$curID = $lastID++;
			return [
				'event_page_title' => "Test $curID",
				'event_page_prefixedtext' => "Event:Test $curID",
			] + $timeFields + $baseRow;
		};

		$rows = [];
		// Use dates in the past so that we know the tz rules for sure.
		// The test is written so that all events should have the same start and end UTC times
		// after normalization.
		$rows[] = $getRow( [
			// Row #1 - data is correct
			'event_timezone' => 'Europe/Rome',
			'event_start_local' => '20220815160000',
			'event_start_utc' => $dbw->timestamp( '20220815140000' ),
			'event_end_local' => '20220815180000',
			'event_end_utc' => $dbw->timestamp( '20220815160000' ),
		] );
		$rows[] = $getRow( [
			// Row #2 - start is off, end is correct
			'event_timezone' => 'Europe/Rome',
			'event_start_local' => '20220815160000',
			'event_start_utc' => $dbw->timestamp( '20220815150000' ),
			'event_end_local' => '20220815180000',
			'event_end_utc' => $dbw->timestamp( '20220815160000' ),
		] );
		$rows[] = $getRow( [
			// Row #3 - start is correct, end is off
			'event_timezone' => 'Europe/Rome',
			'event_start_local' => '20220815160000',
			'event_start_utc' => $dbw->timestamp( '20220815140000' ),
			'event_end_local' => '20220815180000',
			'event_end_utc' => $dbw->timestamp( '20220815170000' ),
		] );
		$rows[] = $getRow( [
			// Row #4 - start and end are off
			'event_timezone' => 'Europe/Rome',
			'event_start_local' => '20220815160000',
			'event_start_utc' => $dbw->timestamp( '20220815170000' ),
			'event_end_local' => '20220815180000',
			'event_end_utc' => $dbw->timestamp( '20220815200000' ),
		] );

		$dbw->insert( 'campaign_events', $rows );
		$this->oldRows = $rows;
	}

	public function testExecute() {
		// Check estimate of affected rows.
		$this->expectOutputRegex( '/~3 updated/' );
		$this->maintenance->execute();
		$dbr = CampaignEventsServices::getDatabaseHelper()->getDBConnection( DB_REPLICA );
		$res = $dbr->select( 'campaign_events', '*' );
		$this->assertCount( 4, $res, 'Total number of rows' );
		$this->assertNotNull( $this->oldRows, 'oldRows should be set by this point' );
		$changedRowsCount = 0;
		foreach ( $res as $i => $row ) {
			if (
				$row->event_start_utc !== $this->oldRows[$i]['event_start_utc'] ||
				$row->event_end_utc !== $this->oldRows[$i]['event_end_utc']
			) {
				$changedRowsCount++;
			}
		}
		$this->assertSame( 3, $changedRowsCount, '3 rows should have been updated.' );
		foreach ( $res as $i => $row ) {
			// Match the ordinals used in the comments above.
			$rowNum = $i + 1;
			$this->assertSame( '20220815160000', $row->event_start_local, "Local start, row $rowNum" );
			$this->assertSame( $dbr->timestamp( '20220815140000' ), $row->event_start_utc, "UTC start, row $rowNum" );
			$this->assertSame( '20220815180000', $row->event_end_local, "Local end, row $rowNum" );
			$this->assertSame( $dbr->timestamp( '20220815160000' ), $row->event_end_utc, "UTC end, row $rowNum" );
		}
	}
}
