<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Hooks\Handlers;

use DatabaseUpdater;
use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

class SchemaChangesHandler implements LoadExtensionSchemaUpdatesHook {
	/**
	 * @param DatabaseUpdater $updater
	 */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		// NOTE: This hook does not support DI.
		global $wgCampaignEventsDatabaseCluster, $wgCampaignEventsDatabaseName;

		// We only want to create the schema on the shared DB. However, that seems hard to do due to how the
		// installer/updater works (also, it's apparently not totally safe to access services at this point).
		if (
			// These globals are not set when running install.php
			( $wgCampaignEventsDatabaseCluster !== null && $wgCampaignEventsDatabaseName !== null ) &&
			( $wgCampaignEventsDatabaseCluster !== false && $wgCampaignEventsDatabaseName !== false )
		) {
			return;
		}

		$dbType = $updater->getDB()->getType();
		$dir = __DIR__ . "/../../../db_patches";

		$updater->addExtensionTable(
			'ce_organizers',
			"$dir/$dbType/tables-generated.sql"
		);
	}
}
