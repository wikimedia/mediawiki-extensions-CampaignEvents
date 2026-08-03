<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Worklist;

use InvalidArgumentException;
use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;

/**
 * Secondary store for worklist pages, allowing easier global access, pagination, reports, etc. This is meant to mirror
 * the primary store inside wikipages with the 'worklist' content model, which remain the source of truth for worklists.
 * Note that, because of this, records in the secondary storage are not updated upon deletion/moves of pages in the
 * worklist: it's up to the users to do this if they so wish. For example, this means that a row may reference
 * a nonexistent page (which we also allow so that worklists can track pages to be created), or a redirect.
 */
class WorklistPagesSecondaryStore {
	public const SERVICE_NAME = 'CampaignEventsWorklistPagesSecondaryStore';

	private const BATCH_SIZE = 500;

	public function __construct(
		private readonly CampaignsDatabaseHelper $dbHelper,
		private readonly IConnectionProvider $connectionProvider,
	) {
	}

	/**
	 * Returns query info for listing pages in the worklist associated with a given event, intended
	 * for use in pagers. Pages belong to a worklist (cewp_cew_id), linked to the event via
	 * ce_worklist_events, so the event filter is applied through that join.
	 *
	 * @return array{tables: array, fields: array, conds: array, join_conds: array}
	 */
	public function getQueryInfo( int $eventId ): array {
		return [
			'tables' => [
				'cewp' => 'ce_worklist_pages',
				'cewe' => 'ce_worklist_events',
			],
			'fields' => [
				'cewp_id',
				'cewp_page_prefixedtext',
				'cewp_wiki',
				'cewp_timestamp',
			],
			'conds' => [
				'cewe.cewe_event_id' => $eventId,
			],
			'join_conds' => [
				'cewe' => [ 'JOIN', 'cewp.cewp_cew_id = cewe.cewe_cew_id' ],
			],
		];
	}

	/**
	 * Updates stored pages based on a delta of removed and added pages.
	 * This should ONLY be called from methods with outer transaction scope.
	 * To avoid ambiguities, this method should never be called with the same page in both $removed and $added.
	 *
	 * @param int $worklistID
	 * @param CentralUser $performer
	 * @param array<string,list<string>> $removed Prefixedtext keyed by wiki ID, same as in WorklistContent
	 * @param array<string,list<string>> $added Prefixedtext keyed by wiki ID, same as in WorklistContent
	 */
	public function updateWorklistPages( int $worklistID, CentralUser $performer, array $removed, array $added ): void {
		foreach ( $removed as $wiki => $removedPages ) {
			if ( isset( $added[$wiki] ) && array_intersect( $removedPages, $added[$wiki] ) ) {
				throw new InvalidArgumentException( 'Cannot remove and add the same article' );
			}
		}
		$ticket = $this->connectionProvider->getEmptyTransactionTicket( __METHOD__ );
		$dbw = $this->dbHelper->getPrimaryConnection();
		$this->removePages( $dbw, $ticket, $worklistID, $removed );
		$this->addPages( $dbw, $ticket, $worklistID, $performer, $added );
	}

	/**
	 * @param IDatabase $dbw
	 * @param mixed $transactionTicket
	 * @param int $worklistID
	 * @param array<string,list<string>> $pagesByWiki
	 */
	private function removePages(
		IDatabase $dbw,
		mixed $transactionTicket,
		int $worklistID,
		array $pagesByWiki
	): void {
		foreach ( $pagesByWiki as $wiki => $prefixedTexts ) {
			foreach ( array_chunk( $prefixedTexts, self::BATCH_SIZE ) as $prefixedTextsBatch ) {
				$dbw->newDeleteQueryBuilder()
					->deleteFrom( 'ce_worklist_pages' )
					->where( [
						'cewp_cew_id' => $worklistID,
						'cewp_wiki' => $wiki,
						'cewp_page_prefixedtext' => $prefixedTextsBatch,
					] )
					->caller( __METHOD__ )
					->execute();
				$this->connectionProvider->commitAndWaitForReplication( __METHOD__, $transactionTicket );
			}
		}
	}

	/**
	 * @param IDatabase $dbw
	 * @param mixed $transactionTicket
	 * @param int $worklistID
	 * @param CentralUser $performer
	 * @param array<string,list<string>> $pagesByWiki
	 */
	private function addPages(
		IDatabase $dbw,
		mixed $transactionTicket,
		int $worklistID,
		CentralUser $performer,
		array $pagesByWiki
	): void {
		$userID = $performer->getCentralID();
		$timestamp = $dbw->timestamp();

		$newRows = [];
		foreach ( $pagesByWiki as $wiki => $prefixedTexts ) {
			foreach ( $prefixedTexts as $prefixedText ) {
				$newRows[] = [
					'cewp_wiki' => $wiki,
					'cewp_page_prefixedtext' => $prefixedText,
					'cewp_user_id' => $userID,
					'cewp_cew_id' => $worklistID,
					'cewp_timestamp' => $timestamp,
				];
			}
		}

		foreach ( array_chunk( $newRows, self::BATCH_SIZE ) as $rowBatch ) {
			$dbw->newInsertQueryBuilder()
				->insertInto( 'ce_worklist_pages' )
				->ignore()
				->rows( $rowBatch )
				->caller( __METHOD__ )
				->execute();
			$this->connectionProvider->commitAndWaitForReplication( __METHOD__, $transactionTicket );
		}
	}

	/**
	 * Shortcut to delete all stored pages for the given worklist.
	 * This should ONLY be called from methods with outer transaction scope.
	 */
	public function deleteAllWorklistPages( int $worklistID ): void {
		$dbw = $this->dbHelper->getPrimaryConnection();
		$ticket = $this->connectionProvider->getEmptyTransactionTicket( __METHOD__ );
		do {
			$batchIDs = $dbw->newSelectQueryBuilder()
				->select( 'cewp_id' )
				->from( 'ce_worklist_pages' )
				->where( [ 'cewp_cew_id' => $worklistID ] )
				->limit( self::BATCH_SIZE )
				->caller( __METHOD__ )
				->fetchFieldValues();

			if ( $batchIDs ) {
				$dbw->newDeleteQueryBuilder()
					->deleteFrom( 'ce_worklist_pages' )
					->where( [ 'cewp_id' => $batchIDs ] )
					->caller( __METHOD__ )
					->execute();
				$this->connectionProvider->commitAndWaitForReplication( __METHOD__, $ticket );
			}
		} while ( $batchIDs );
	}
}
