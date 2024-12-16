<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\TrackingTool;

use InvalidArgumentException;
use LogicException;
use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use Wikimedia\Rdbms\IDatabase;

/**
 * This class updates the information about tracking tools stored in our database.
 */
class TrackingToolUpdater {
	public const SERVICE_NAME = 'CampaignEventsTrackingToolUpdater';

	private const SYNC_STATUS_TO_DB_MAP = [
		TrackingToolAssociation::SYNC_STATUS_UNKNOWN => 1,
		TrackingToolAssociation::SYNC_STATUS_SYNCED => 2,
		TrackingToolAssociation::SYNC_STATUS_FAILED => 3,
	];

	private CampaignsDatabaseHelper $dbHelper;

	/**
	 * @param CampaignsDatabaseHelper $dbHelper
	 */
	public function __construct( CampaignsDatabaseHelper $dbHelper ) {
		$this->dbHelper = $dbHelper;
	}

	/**
	 * Converts a TrackingToolAssociation::SYNC_STATUS_* constant to the respective DB value
	 * @param int $status
	 * @return int
	 */
	public static function syncStatusToDB( int $status ): int {
		if ( !isset( self::SYNC_STATUS_TO_DB_MAP[$status] ) ) {
			throw new InvalidArgumentException( "Unknown sync status $status" );
		}
		return self::SYNC_STATUS_TO_DB_MAP[$status];
	}

	/**
	 * Converts a DB value for ce_tracking_tools.cett_sync_status to the respective
	 * TrackingToolAssociation::SYNC_STATUS_* constant.
	 * @param int $dbVal
	 * @return int
	 */
	public static function dbSyncStatusToConst( int $dbVal ): int {
		$const = array_search( $dbVal, self::SYNC_STATUS_TO_DB_MAP, true );
		if ( $const === false ) {
			throw new InvalidArgumentException( "Unknown DB value for sync status: $dbVal" );
		}
		return $const;
	}

	/**
	 * Replaces the tools associated to an event with the given array of tool associations.
	 *
	 * @param int $eventID
	 * @param TrackingToolAssociation[] $tools
	 * @param IDatabase|null $dbw Optional, in case the caller opened an atomic section and wants to make sure
	 * that writes are done on the same DB handle.
	 * @return void
	 */
	public function replaceEventTools( int $eventID, array $tools, ?IDatabase $dbw = null ): void {
		$dbw ??= $this->dbHelper->getDBConnection( DB_PRIMARY );

		// Make a map of tools with faster lookup to compare existing values
		$toolsMap = [];
		foreach ( $tools as $toolAssociation ) {
			$key = $toolAssociation->getToolID() . '|' . $toolAssociation->getToolEventID();
			$toolsMap[$key] = $toolAssociation;
		}

		// Make changes by primary key to avoid lock contention
		$currentToolRows = $dbw->newSelectQueryBuilder()
			->select( '*' )
			->from( 'ce_tracking_tools' )
			->where( [ 'cett_event' => $eventID ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		// TODO Add support for multiple tracking tools per event
		if ( count( $currentToolRows ) > 1 && !defined( 'MW_PHPUNIT_TEST' ) ) {
			throw new LogicException( "Events should have only one tracking tool." );
		}

		// Delete rows for tools that are no longer connected or with outdated sync info
		$deleteIDs = [];
		foreach ( $currentToolRows as $curRow ) {
			$lookupKey = $curRow->cett_tool_id . '|' . $curRow->cett_tool_event_id;
			$syncStatus = self::dbSyncStatusToConst( (int)$curRow->cett_sync_status );
			if (
				!isset( $toolsMap[$lookupKey] ) ||
				$syncStatus !== $toolsMap[$lookupKey]->getSyncStatus() ||
				wfTimestampOrNull( TS_UNIX, $curRow->cett_last_sync ) !== $toolsMap[$lookupKey]->getLastSyncTimestamp()
			) {
				$deleteIDs[] = $curRow->cett_id;
			}
		}

		if ( $deleteIDs ) {
			$dbw->newDeleteQueryBuilder()
				->deleteFrom( 'ce_tracking_tools' )
				->where( [ 'cett_id' => $deleteIDs ] )
				->caller( __METHOD__ )
				->execute();
		}

		$newRows = [];
		foreach ( $tools as $toolAssoc ) {
			$syncTS = $toolAssoc->getLastSyncTimestamp();
			$newRows[] = [
				'cett_event' => $eventID,
				'cett_tool_id' => $toolAssoc->getToolID(),
				'cett_tool_event_id' => $toolAssoc->getToolEventID(),
				'cett_sync_status' => self::syncStatusToDB( $toolAssoc->getSyncStatus() ),
				'cett_last_sync' => $syncTS !== null ? $dbw->timestamp( $syncTS ) : $syncTS,
			];
		}

		if ( $newRows ) {
			// Insert the remaining rows. We can ignore conflicting rows in the database, as the checks above guarantee
			// that they're identical to the new rows.
			$dbw->newInsertQueryBuilder()
				->insertInto( 'ce_tracking_tools' )
				->ignore()
				->rows( $newRows )
				->caller( __METHOD__ )
				->execute();
		}
	}

	/**
	 * Updates the sync status of a tool in the database, updating the last sync timestamp if $status is
	 * SYNC_STATUS_SYNCED.
	 *
	 * @param int $eventID
	 * @param int $toolID
	 * @param string $toolEventID
	 * @param int $status One of the TrackingToolAssociation::SYNC_STATUS_* constants
	 */
	public function updateToolSyncStatus( int $eventID, int $toolID, string $toolEventID, int $status ): void {
		$dbw = $this->dbHelper->getDBConnection( DB_PRIMARY );
		$setConds = [
			'cett_sync_status' => self::syncStatusToDB( $status ),
		];
		if ( $status === TrackingToolAssociation::SYNC_STATUS_SYNCED ) {
			$setConds['cett_last_sync'] = $dbw->timestamp();
		}

		$dbw->newUpdateQueryBuilder()
			->update( 'ce_tracking_tools' )
			->set( $setConds )
			->where( [
				'cett_event' => $eventID,
				'cett_tool_id' => $toolID,
				'cett_tool_event_id' => $toolEventID
			] )
			->caller( __METHOD__ )
			->execute();
	}
}
