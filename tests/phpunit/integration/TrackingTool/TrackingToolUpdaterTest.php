<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\TrackingTool;

use Generator;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolAssociation;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolUpdater;
use MediaWikiIntegrationTestCase;
use MWTimestamp;

/**
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolUpdater
 * @group Database
 */
class TrackingToolUpdaterTest extends MediaWikiIntegrationTestCase {
	private const FAKE_TIME = '20210115000000';

	/** @inheritDoc */
	protected $tablesUsed = [ 'ce_tracking_tools' ];

	/**
	 * @inheritDoc
	 */
	protected function setUp(): void {
		parent::setUp();
		MWTimestamp::setFakeTime( self::FAKE_TIME );
	}

	public function addDBData() {
		$rows = [
			[
				'cett_event' => 1,
				'cett_tool_id' => 42,
				'cett_tool_event_id' => 'foo',
				'cett_sync_status' => TrackingToolUpdater::syncStatusToDB(
					TrackingToolAssociation::SYNC_STATUS_UNKNOWN
				),
				'cett_last_sync' => null,
			],
			[
				'cett_event' => 1,
				'cett_tool_id' => 42,
				'cett_tool_event_id' => 'bar',
				'cett_sync_status' => TrackingToolUpdater::syncStatusToDB(
					TrackingToolAssociation::SYNC_STATUS_SYNCED
				),
				'cett_last_sync' => '20230115000000',
			]
		];
		$this->getDb()->insert( 'ce_tracking_tools', $rows, __METHOD__ );
	}

	/**
	 * @covers ::replaceEventTools
	 * @dataProvider provideReplaceEventTools
	 */
	public function testReplaceEventTools(
		int $eventID,
		array $tools,
		array $expectedNewRows
	) {
		$updater = CampaignEventsServices::getTrackingToolUpdater();
		$updater->replaceEventTools( $eventID, $tools );
		$res = $this->getDb()->select(
			'ce_tracking_tools',
			[ 'cett_event', 'cett_tool_id', 'cett_tool_event_id', 'cett_sync_status', 'cett_last_sync' ],
			[ 'cett_event' => $eventID ],
			__METHOD__,
			[ 'ORDER BY' => 'cett_id' ]
		);
		$actualRows = [];
		foreach ( $res as $row ) {
			$actualRows[] = get_object_vars( $row );
		}
		$formattedExpected = [];
		foreach ( $expectedNewRows as $expectedRow ) {
			$formattedExpected[] = array_map( static function ( $val ) {
				return is_int( $val ) ? (string)$val : $val;
			}, $expectedRow );
		}
		$this->objectAssociativeSort( $formattedExpected );
		$this->objectAssociativeSort( $actualRows );
		$this->assertSame( array_values( $formattedExpected ), array_values( $actualRows ) );
	}

	public function provideReplaceEventTools(): Generator {
		yield 'Has no tools, adding one' => [
			2,
			[ new TrackingToolAssociation( 5, 'foobar', TrackingToolAssociation::SYNC_STATUS_UNKNOWN, null ) ],
			[
				[
					'cett_event' => 2,
					'cett_tool_id' => 5,
					'cett_tool_event_id' => 'foobar',
					'cett_sync_status' => TrackingToolUpdater::syncStatusToDB(
						TrackingToolAssociation::SYNC_STATUS_UNKNOWN
					),
					'cett_last_sync' => null,
				]
			]
		];
		yield 'Has a tool, adding a new one' => [
			1,
			[
				new TrackingToolAssociation( 42, 'foo', TrackingToolAssociation::SYNC_STATUS_UNKNOWN, null ),
				new TrackingToolAssociation(
					42,
					'bar',
					TrackingToolAssociation::SYNC_STATUS_SYNCED,
					wfTimestamp( TS_UNIX, '20230115000000' )
				),
				new TrackingToolAssociation(
					100,
					'foobar',
					TrackingToolAssociation::SYNC_STATUS_UNKNOWN,
					null
				)
			],
			[
				[
					'cett_event' => 1,
					'cett_tool_id' => 42,
					'cett_tool_event_id' => 'foo',
					'cett_sync_status' => TrackingToolUpdater::syncStatusToDB(
						TrackingToolAssociation::SYNC_STATUS_UNKNOWN
					),
					'cett_last_sync' => null,
				],
				[
					'cett_event' => 1,
					'cett_tool_id' => 42,
					'cett_tool_event_id' => 'bar',
					'cett_sync_status' => TrackingToolUpdater::syncStatusToDB(
						TrackingToolAssociation::SYNC_STATUS_SYNCED
					),
					'cett_last_sync' => '20230115000000',
				],
				[
					'cett_event' => 1,
					'cett_tool_id' => 100,
					'cett_tool_event_id' => 'foobar',
					'cett_sync_status' => TrackingToolUpdater::syncStatusToDB(
						TrackingToolAssociation::SYNC_STATUS_UNKNOWN
					),
					'cett_last_sync' => null,
				]
			]
		];
		yield 'Has a tool, adding new event in the same tool' => [
			1,
			[
				new TrackingToolAssociation( 42, 'foo', TrackingToolAssociation::SYNC_STATUS_UNKNOWN, null ),
				new TrackingToolAssociation(
					42,
					'bar',
					TrackingToolAssociation::SYNC_STATUS_SYNCED,
					wfTimestamp( TS_UNIX, '20230115000000' )
				),
				new TrackingToolAssociation(
					42,
					'foobar',
					TrackingToolAssociation::SYNC_STATUS_UNKNOWN,
					null
				)
			],
			[
				[
					'cett_event' => 1,
					'cett_tool_id' => 42,
					'cett_tool_event_id' => 'foo',
					'cett_sync_status' => TrackingToolUpdater::syncStatusToDB(
						TrackingToolAssociation::SYNC_STATUS_UNKNOWN
					),
					'cett_last_sync' => null,
				],
				[
					'cett_event' => 1,
					'cett_tool_id' => 42,
					'cett_tool_event_id' => 'bar',
					'cett_sync_status' => TrackingToolUpdater::syncStatusToDB(
						TrackingToolAssociation::SYNC_STATUS_SYNCED
					),
					'cett_last_sync' => '20230115000000',
				],
				[
					'cett_event' => 1,
					'cett_tool_id' => 42,
					'cett_tool_event_id' => 'foobar',
					'cett_sync_status' => TrackingToolUpdater::syncStatusToDB(
						TrackingToolAssociation::SYNC_STATUS_UNKNOWN
					),
					'cett_last_sync' => null,
				]
			]
		];
		yield 'Has a tool, updating status and ts' => [
			1,
			[
				new TrackingToolAssociation(
					42,
					'foo',
					TrackingToolAssociation::SYNC_STATUS_SYNCED,
					wfTimestamp( TS_UNIX, '20200115000000' )
				),
				new TrackingToolAssociation(
					42,
					'bar',
					TrackingToolAssociation::SYNC_STATUS_SYNCED,
					wfTimestamp( TS_UNIX, '20230115000000' )
				)
			],
			[
				[
					'cett_event' => 1,
					'cett_tool_id' => 42,
					'cett_tool_event_id' => 'foo',
					'cett_sync_status' => TrackingToolUpdater::syncStatusToDB(
						TrackingToolAssociation::SYNC_STATUS_SYNCED
					),
					'cett_last_sync' => '20200115000000',
				],
				[
					'cett_event' => 1,
					'cett_tool_id' => 42,
					'cett_tool_event_id' => 'bar',
					'cett_sync_status' => TrackingToolUpdater::syncStatusToDB(
						TrackingToolAssociation::SYNC_STATUS_SYNCED
					),
					'cett_last_sync' => '20230115000000',
				]
			]
		];
		yield 'Has a tool with two IDs, replacing one of them' => [
			1,
			[
				new TrackingToolAssociation(
					42,
					'updated foo',
					TrackingToolAssociation::SYNC_STATUS_SYNCED,
					wfTimestamp( TS_UNIX, '20300115000000' )
				),
				new TrackingToolAssociation(
					42,
					'bar',
					TrackingToolAssociation::SYNC_STATUS_SYNCED,
					wfTimestamp( TS_UNIX, '20230115000000' )
				)
			],
			[
				[
					'cett_event' => 1,
					'cett_tool_id' => 42,
					'cett_tool_event_id' => 'updated foo',
					'cett_sync_status' => TrackingToolUpdater::syncStatusToDB(
						TrackingToolAssociation::SYNC_STATUS_SYNCED
					),
					'cett_last_sync' => '20300115000000',
				],
				[
					'cett_event' => 1,
					'cett_tool_id' => 42,
					'cett_tool_event_id' => 'bar',
					'cett_sync_status' => TrackingToolUpdater::syncStatusToDB(
						TrackingToolAssociation::SYNC_STATUS_SYNCED
					),
					'cett_last_sync' => '20230115000000',
				]
			]
		];
	}

	/**
	 * @covers ::updateToolSyncStatus
	 * @dataProvider provideUpdateToolSyncStatus
	 */
	public function testUpdateToolSyncStatus(
		int $eventID,
		int $toolID,
		string $toolEventID,
		int $status,
		?string $expectedTS
	) {
		$updater = CampaignEventsServices::getTrackingToolUpdater();
		$updater->updateToolSyncStatus( $eventID, $toolID, $toolEventID, $status );
		$row = $this->getDb()->selectRow(
			'ce_tracking_tools',
			'*',
			[
				'cett_event' => $eventID,
				'cett_tool_id' => $toolID,
				'cett_tool_event_id' => $toolEventID
			]
		);
		$this->assertSame( TrackingToolUpdater::syncStatusToDB( $status ), (int)$row->cett_sync_status );
		$this->assertSame( $expectedTS, $row->cett_last_sync );
	}

	public function provideUpdateToolSyncStatus(): Generator {
		yield 'Unknown -> synced' => [ 1, 42, 'foo', TrackingToolAssociation::SYNC_STATUS_SYNCED, self::FAKE_TIME ];
		yield 'Synced -> unknown' => [ 1, 42, 'bar', TrackingToolAssociation::SYNC_STATUS_UNKNOWN, '20230115000000' ];
		yield 'Synced -> synced' => [ 1, 42, 'bar', TrackingToolAssociation::SYNC_STATUS_SYNCED, self::FAKE_TIME ];
		yield 'Synced -> failed' => [ 1, 42, 'bar', TrackingToolAssociation::SYNC_STATUS_FAILED, '20230115000000' ];
	}
}
