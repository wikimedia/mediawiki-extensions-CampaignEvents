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

		$updater->addExtensionUpdateOnVirtualDomain( [
			Utils::VIRTUAL_DB_DOMAIN,
			'addTable',
			'ce_invitation_lists',
			"$dir/$dbType/patch-add-ce_invitation_lists.sql",
			true
		] );

		$updater->addExtensionUpdateOnVirtualDomain( [
			Utils::VIRTUAL_DB_DOMAIN,
			'addTable',
			'ce_worklist_articles',
			"$dir/$dbType/patch-add-ce_worklist_articles.sql",
			true
		] );

		$updater->addExtensionUpdateOnVirtualDomain( [
			Utils::VIRTUAL_DB_DOMAIN,
			'addTable',
			'ce_invitation_list_users',
			"$dir/$dbType/patch-add-ce_invitation_list_users.sql",
			true
		] );

		$updater->addExtensionUpdateOnVirtualDomain( [
			Utils::VIRTUAL_DB_DOMAIN,
			'addTable',
			'ce_event_wikis',
			"$dir/$dbType/patch-add-ce_event_wikis.sql",
			true
		] );
		$updater->addExtensionUpdateOnVirtualDomain( [
			Utils::VIRTUAL_DB_DOMAIN,
			'addField',
			'campaign_events',
			"event_is_test_event",
			"$dir/$dbType/patch-add-event_is_test_event.sql",
			true
		] );

		$updater->addExtensionUpdateOnVirtualDomain( [
			Utils::VIRTUAL_DB_DOMAIN,
			'addTable',
			'ce_event_topics',
			"$dir/$dbType/patch-add-ce_event_topics.sql",
			true
		] );

		$updater->addExtensionUpdateOnVirtualDomain( [
			Utils::VIRTUAL_DB_DOMAIN,
			'addField',
			'campaign_events',
			"event_types",
			"$dir/$dbType/patch-change_event_type.sql",
			true
		] );

		$updater->addExtensionUpdateOnVirtualDomain( [
			Utils::VIRTUAL_DB_DOMAIN,
			'addField',
			'ce_address',
			"cea_country_code",
			"$dir/$dbType/patch-add-cea_country_code.sql",
			true
		] );
	}
}
