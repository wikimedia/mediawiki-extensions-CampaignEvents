<?php

namespace MediaWiki\Extension\CampaignEvents\Hooks\Handlers;

use DatabaseUpdater;
use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

class SchemaChangesHandler implements LoadExtensionSchemaUpdatesHook {
	/**
	 * @param DatabaseUpdater $updater
	 */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$dbType = $updater->getDB()->getType();
		$dir = dirname( dirname( dirname( __DIR__ ) ) ) . "/db_patches";

		$updater->addExtensionTable(
			'ceo_roles',
			"$dir/$dbType/tables-generated.sql"
		);
	}
}
