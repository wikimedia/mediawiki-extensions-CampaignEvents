<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Hooks\Handlers;

use MediaWiki\Extension\CampaignEvents\Utils;
use MediaWiki\Installer\DatabaseUpdater;
use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;
use Wikimedia\Rdbms\IMaintainableDatabase;

class SchemaChangesHandler implements LoadExtensionSchemaUpdatesHook {
	/**
	 * @param DatabaseUpdater $updater
	 */
	public function onLoadExtensionSchemaUpdates( $updater ): void {
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

		$updater->addExtensionUpdateOnVirtualDomain( [
			Utils::VIRTUAL_DB_DOMAIN,
			'addTable',
			'ce_event_contributions',
			"$dir/$dbType/patch-add-ce_event_contributions.sql",
			true
		] );

		$updater->addExtensionUpdateOnVirtualDomain( [
			Utils::VIRTUAL_DB_DOMAIN,
			'addField',
			'campaign_events',
			"event_track_contributions",
			"$dir/$dbType/patch-add-event_track_contributions.sql",
			true
		] );

		$updater->addExtensionUpdateOnVirtualDomain( [
			Utils::VIRTUAL_DB_DOMAIN,
			'dropField',
			'ce_address',
			"cea_country",
			"$dir/$dbType/patch-cleanup-country.sql",
			true
		] );

		$updater->addExtensionUpdateOnVirtualDomain( [
			Utils::VIRTUAL_DB_DOMAIN,
			'addField',
			'ce_participants',
			"cep_hide_contribution_association_prompt",
			"$dir/$dbType/patch-add-cep_hide_contribution_association_prompt.sql",
			true
		] );
		$updater->addExtensionUpdateOnVirtualDomain( [
			Utils::VIRTUAL_DB_DOMAIN,
			'addTable',
			'ce_event_goals',
			"$dir/$dbType/patch-add-ce-event-goals.sql",
			true
		] );

		$updater->addExtensionUpdateOnVirtualDomain( [
			Utils::VIRTUAL_DB_DOMAIN,
			'addField',
			'ce_event_contributions',
			'cec_references_delta',
			"$dir/$dbType/patch-add-cec_references_delta.sql",
			true
		] );

		$updater->addExtensionUpdateOnVirtualDomain( [
			Utils::VIRTUAL_DB_DOMAIN,
			'addTable',
			'ce_worklists',
			"$dir/$dbType/patch-add-ce_worklists.sql",
			true
		] );

		$updater->addExtensionUpdateOnVirtualDomain( [
			Utils::VIRTUAL_DB_DOMAIN,
			'addTable',
			'ce_worklist_events',
			"$dir/$dbType/patch-add-ce_worklist_events.sql",
			true
		] );

		$updater->addExtensionUpdateOnVirtualDomain( [
			Utils::VIRTUAL_DB_DOMAIN,
			'addTable',
			'ce_worklist_pages',
			"$dir/$dbType/patch-add-ce_worklist_pages.sql",
			true
		] );

		$updater->addExtensionUpdateOnVirtualDomain( [
			Utils::VIRTUAL_DB_DOMAIN,
			'addTable',
			'ce_invitation_list_articles',
			"$dir/$dbType/patch-add-ce_invitation_list_articles.sql",
			true
		] );
		$updater->addExtensionUpdateOnVirtualDomain( [
			Utils::VIRTUAL_DB_DOMAIN,
			[ $this, 'migrateAndDropWorklistArticlesTable' ]
		] );
	}

	/**
	 * Import data from the `ce_worklist_articles` table (if it exists) to `ce_invitation_list_articles`,
	 * then drop the former.
	 */
	public function migrateAndDropWorklistArticlesTable( DatabaseUpdater $updater ): void {
		$db = $updater->getDB();

		if ( !$db->tableExists( 'ce_worklist_articles', __METHOD__ ) ) {
			// It's possible that there is nothing to migrate, if updating an old install from before invitation lists
			// were introduced.
			return;
		}

		$this->migrateWorklistRows( $db );
		$db->dropTable( 'ce_worklist_articles', __METHOD__ );
	}

	private function migrateWorklistRows( IMaintainableDatabase $dbw ): void {
		while ( true ) {
			$res = $dbw->newSelectQueryBuilder()
				->select( '*' )
				->from( 'ce_worklist_articles' )
				->limit( 500 )
				->caller( __METHOD__ )
				->fetchResultSet();

			if ( !count( $res ) ) {
				break;
			}

			$deleteIDs = [];
			$newRows = [];
			foreach ( $res as $row ) {
				$deleteIDs[] = $row->cewa_id;
				$newRows[] = [
					'ceila_id' => $row->cewa_id,
					'ceila_page_id' => $row->cewa_page_id,
					'ceila_page_title' => $row->cewa_page_title,
					'ceila_ceil_id' => $row->cewa_ceil_id,
				];
			}

			$dbw->newInsertQueryBuilder()
				->insertInto( 'ce_invitation_list_articles' )
				->rows( $newRows )
				->caller( __METHOD__ )
				->execute();
			$dbw->newDeleteQueryBuilder()
				->deleteFrom( 'ce_worklist_articles' )
				->where( [ 'cewa_id' => $deleteIDs ] )
				->caller( __METHOD__ )
				->execute();
		}
	}
}
