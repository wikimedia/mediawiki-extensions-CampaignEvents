<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Maintenance;

// @codeCoverageIgnoreStart
$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";
// @codeCoverageIgnoreEnd

use DateTime;
use DateTimeZone;
use Maintenance;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsDatabase;

/**
 * This script can be used to update UTC timestamps stored in the campaign_events table to make sure
 * that they reflect the latest timezone rules as published in the Olson database. One important assumption here
 * is that the Olson data read here is the same that is available for normal web request. This allows us to not worry
 * about concurrent updates: if someone updates an event while the script is running, we will recompute the correct
 * UTC timestamp on save anyway, and so we don't have to do it here.
 */
class UpdateUTCTimestamps extends Maintenance {
	/** @var ICampaignsDatabase|null */
	private ?ICampaignsDatabase $dbw;
	/** @var ICampaignsDatabase|null */
	private ?ICampaignsDatabase $dbr;
	/** @var DateTimeZone|null */
	private ?DateTimeZone $utcTimezone;

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Recompute UTC timestamps in the campaign_events table' );
		$this->setBatchSize( 500 );
		$this->requireExtension( 'CampaignEvents' );
		$this->addOption(
			'timezone',
			'Names of the timezones to update',
			false,
			true,
			false,
			true
		);
	}

	/**
	 * @inheritDoc
	 */
	public function execute() {
		$this->output( "Updating UTC timestamps in the campaign_events table...\n" );
		$dbHelper = CampaignEventsServices::getDatabaseHelper();
		$this->dbr = $dbHelper->getDBConnection( DB_REPLICA );
		$this->dbw = $dbHelper->getDBConnection( DB_PRIMARY );
		$batchSize = $this->getBatchSize();
		$updateTimezones = $this->getOption( 'timezone' );

		$prevID = 0;
		$curID = $batchSize;
		$this->utcTimezone = new DateTimeZone( 'UTC' );
		do {
			$foundRows = $this->updateBatch( $prevID, $curID, $updateTimezones );
			$prevID = $curID;
			$curID += $batchSize;
			$dbHelper->waitForReplication();
		} while ( $foundRows );

		$this->output( "Done.\n" );
	}

	/**
	 * @param int $prevID
	 * @param int $curID
	 * @param array|null $updateTimezones
	 * @return int Number of rows found
	 */
	private function updateBatch( int $prevID, int $curID, ?array $updateTimezones ): int {
		$where = [
			"event_id > $prevID",
			"event_id <= $curID",
		];
		if ( $updateTimezones ) {
			$where['event_timezone'] = $updateTimezones;
		}
		$res = $this->dbr->select( 'campaign_events', '*', $where );

		$newRows = [];
		foreach ( $res as $row ) {
			$tz = new DateTimeZone( $row->event_timezone );
			$localStartDateTime = new DateTime( $row->event_start_local, $tz );
			$utcStartTime = $localStartDateTime->setTimezone( $this->utcTimezone )->getTimestamp();
			$newStartTS = wfTimestamp( TS_MW, $utcStartTime );
			$localEndDateTime = new DateTime( $row->event_end_local, $tz );
			$utcEndTime = $localEndDateTime->setTimezone( $this->utcTimezone )->getTimestamp();
			$newEndTS = wfTimestamp( TS_MW, $utcEndTime );

			if ( $newStartTS !== $row->event_start_utc || $newEndTS !== $row->event_end_utc ) {
				$newRows[] = [
						'event_start_utc' => $this->dbw->timestamp( $newStartTS ),
						'event_end_utc' => $this->dbw->timestamp( $newEndTS ),
					] + get_object_vars( $row );
			}
		}
		if ( $newRows ) {
			// Use INSERT ODKU to update all rows at once. This will never insert, only update.
			$this->dbw->upsert(
				'campaign_events',
				$newRows,
				'event_id',
				[
					'event_start_utc = ' . $this->getUpdateTimeConditional( 'event_start_utc' ),
					'event_end_utc = ' . $this->getUpdateTimeConditional( 'event_end_utc' )
				]
			);

			// TODO: Ideally we would use affectedRows here, but our implementation does not distinguish between
			// matched and changed rows (T304680); additionally, MySQL counts updated rows as 2 (T314100).
			$affectedRows = '~' . count( $newRows );
		} else {
			$affectedRows = 0;
		}

		$this->output( "Batch $prevID-$curID: $affectedRows updated.\n" );
		return count( $res );
	}

	/**
	 * Returns an SQL fragment that conditionally updates the given field if the other fields haven't changed
	 * since we read the row.
	 *
	 * @param string $fieldName
	 * @return string SQL
	 */
	private function getUpdateTimeConditional( string $fieldName ): string {
		return $this->dbw->conditional(
			[
				'event_timezone = ' . $this->dbw->buildExcludedValue( 'event_timezone' ),
				'event_start_local = ' . $this->dbw->buildExcludedValue( 'event_start_local' ),
				'event_end_local = ' . $this->dbw->buildExcludedValue( 'event_end_local' )
			],
			$this->dbw->buildExcludedValue( $fieldName ),
			// Fall back to identity
			$fieldName
		);
	}
}

$maintClass = UpdateUTCTimestamps::class;
require_once RUN_MAINTENANCE_IF_MAIN;
