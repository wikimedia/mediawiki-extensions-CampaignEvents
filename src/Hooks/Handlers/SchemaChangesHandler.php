<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Hooks\Handlers;

use MediaWiki\Extension\CampaignEvents\Utils;
use MediaWiki\Installer\DatabaseUpdater;
use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

class SchemaChangesHandler implements LoadExtensionSchemaUpdatesHook {
	/**
	 * @param DatabaseUpdater $updater
	 */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$dbType = $updater->getDB()->getType();
		$dir = __DIR__ . "/../../../db_patches";

		$updater->addExtensionUpdateOnVirtualDomain( [
			Utils::VIRTUAL_DB_DOMAIN,
			'addTable',
			'ce_event_address',
			"$dir/$dbType/tables-generated.sql",
			true
		] );
	}
}
