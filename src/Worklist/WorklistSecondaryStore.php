<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Worklist;

use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use RuntimeException;
use Wikimedia\Rdbms\IDBAccessObject;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Secondary store for worklists, allowing easier global access, pagination, reports, etc. This is meant to mirror
 * the primary store, essentially in the `page` table, of pages with the 'worklist' content model, which remain the
 * source of truth.
 */
class WorklistSecondaryStore {
	public const SERVICE_NAME = 'CampaignEventsWorklistSecondaryStore';

	public function __construct(
		private readonly CampaignsDatabaseHelper $dbHelper,
	) {
	}

	/**
	 * Creates a new worklist and returns its ID
	 */
	public function createWorklist(
		string $wiki,
		int $pageID,
		string $prefixedText,
		CentralUser $creator,
		string $creatorName,
		ConvertibleTimestamp $timestamp
	): int {
		$dbw = $this->dbHelper->getPrimaryConnection();
		$dbw->newInsertQueryBuilder()
			->insertInto( 'ce_worklists' )
			->row( [
				'cew_wiki' => $wiki,
				'cew_page_id' => $pageID,
				'cew_page_prefixedtext' => $prefixedText,
				'cew_user_id' => $creator->getCentralID(),
				'cew_username' => $creatorName,
				'cew_timestamp' => $dbw->timestamp( $timestamp->getTimestamp() ),
				'cew_content_rev' => null,
			] )
			->caller( __METHOD__ )
			->execute();
		return $dbw->insertId();
	}

	public function deleteWorklist( string $wiki, int $pageID ): void {
		$this->dbHelper->getPrimaryConnection()->newDeleteQueryBuilder()
			->deleteFrom( 'ce_worklists' )
			->where( [
				'cew_wiki' => $wiki,
				'cew_page_id' => $pageID,
			] )
			->caller( __METHOD__ )
			->execute();
	}

	public function moveWorklist( string $wiki, int $pageID, string $newPrefixedText ): void {
		$this->dbHelper->getPrimaryConnection()->newUpdateQueryBuilder()
			->update( 'ce_worklists' )
			->set( [
				'cew_page_prefixedtext' => $newPrefixedText,
			] )
			->where( [
				'cew_wiki' => $wiki,
				'cew_page_id' => $pageID,
			] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * Returns the ID of the worklist for the given page, or null if there is no such worklist.
	 */
	public function getWorklistIDFromPage( string $wiki, int $pageID ): ?int {
		$dbr = $this->dbHelper->getReplicaConnection();
		$storedID = $dbr->newSelectQueryBuilder()
			->select( 'cew_id' )
			->from( 'ce_worklists' )
			->where( [
				'cew_wiki' => $wiki,
				'cew_page_id' => $pageID,
			] )
			->caller( __METHOD__ )
			->fetchField();
		return $storedID !== false ? (int)$storedID : null;
	}

	/**
	 * @param string $wiki
	 * @param int $pageID
	 * @param string|null $newName Null to indicate a deletion
	 */
	public function updateWorklistCreatorName( string $wiki, int $pageID, ?string $newName ): void {
		$this->dbHelper->getPrimaryConnection()->newUpdateQueryBuilder()
			->update( 'ce_worklists' )
			->set( [
				'cew_username' => $newName,
			] )
			->where( [
				'cew_wiki' => $wiki,
				'cew_page_id' => $pageID,
			] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * Returns the ID of the revision that was last used to synchronize the content of a worklist with the wikipage.
	 * The caller is responsible for making sure that the worklist exists.
	 *
	 * @param int $worklistID
	 * @param int $readFlags One of the IDBAccessObject::READ_* constants
	 */
	public function getWorklistContentSyncedRev(
		int $worklistID,
		int $readFlags = IDBAccessObject::READ_NORMAL
	): ?int {
		if ( ( $readFlags & IDBAccessObject::READ_LATEST ) === IDBAccessObject::READ_LATEST ) {
			$db = $this->dbHelper->getPrimaryConnection();
		} else {
			$db = $this->dbHelper->getReplicaConnection();
		}

		$val = $db->newSelectQueryBuilder()
			->select( 'cew_content_rev' )
			->from( 'ce_worklists' )
			->where( [
				'cew_id' => $worklistID,
			] )
			->caller( __METHOD__ )
			->recency( $readFlags )
			->fetchField();
		if ( $val === false ) {
			throw new RuntimeException( "Worklist $worklistID doesn't exist" );
		}
		return $val !== null ? (int)$val : null;
	}

	/**
	 * Updates the revision ID that was last used to synchronize the content of a worklist with the wikipage.
	 */
	public function updateWorklistContentSyncedRev( int $worklistID, int $revID ): void {
		$this->dbHelper->getPrimaryConnection()->newUpdateQueryBuilder()
			->update( 'ce_worklists' )
			->set( [
				'cew_content_rev' => $revID,
			] )
			->where( [
				'cew_id' => $worklistID,
			] )
			->caller( __METHOD__ )
			->execute();
	}
}
