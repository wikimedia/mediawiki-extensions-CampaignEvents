<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\TrackingTool;

use InvalidArgumentException;

/**
 * This class updates the information about tracking tools stored in our database.
 * For now it is just a stub, but it will be expanded soon, and some logic from EventStore might potentially
 * be moved here.
 */
class TrackingToolUpdater {
	private const SYNC_STATUS_TO_DB_MAP = [
		TrackingToolAssociation::SYNC_STATUS_UNKNOWN => 1,
		TrackingToolAssociation::SYNC_STATUS_SYNCED => 2,
		TrackingToolAssociation::SYNC_STATUS_FAILED => 3,
	];

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
}
