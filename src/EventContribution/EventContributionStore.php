<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\EventContribution;

use BadMethodCallException;
use InvalidArgumentException;
use LogicException;
use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\Utils;
use MediaWiki\Page\ProperPageIdentity;
use stdClass;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Rdbms\IDatabase;

/**
 * Store for managing event contributions.
 */
class EventContributionStore {

	public const SERVICE_NAME = 'CampaignEventsEventContributionStore';

	/**
	 * Stringified username expression for query ordering, to avoid wrong sorting with NULL values (T404995).
	 * Cannot be used with an alias in MySQL/MariaDB (T416569).
	 */
	public const QUERY_USERNAME_STR = 'COALESCE(cec_user_name, "")';

	private const UPDATES_BATCH_SIZE = 500;

	public function __construct(
		private readonly CampaignsDatabaseHelper $dbHelper,
		private readonly WANObjectCache $wanCache
	) {
	}

	/**
	 * Associate an edit with an event.
	 *
	 * @param EventContribution $editObject The edit contribution to associate
	 */
	public function saveEventContribution( EventContribution $editObject ): void {
		$dbw = $this->dbHelper->getPrimaryConnection();

		$dbw->newInsertQueryBuilder()
			->insertInto( 'ce_event_contributions' )
			->row( [
				'cec_event_id' => $editObject->getEventId(),
				'cec_user_id' => $editObject->getUserId(),
				'cec_user_name' => $editObject->getUserName(),
				'cec_wiki' => $editObject->getWiki(),
				'cec_page_id' => $editObject->getPageId(),
				'cec_page_prefixedtext' => $editObject->getPagePrefixedtext(),
				'cec_revision_id' => $editObject->getRevisionId(),
				'cec_edit_flags' => $editObject->getEditFlags(),
				'cec_bytes_delta' => $editObject->getBytesDelta(),
				'cec_links_delta' => $editObject->getLinksDelta(),
				'cec_references_delta' => $editObject->getReferencesDelta(),
				'cec_timestamp' => $dbw->timestamp( $editObject->getTimestamp() ),
				'cec_deleted' => $editObject->isDeleted() ? 1 : 0,
			] )
			->caller( __METHOD__ )
			->execute();
		$dbw->onTransactionPreCommitOrIdle( function () use ( $editObject ): void {
			$this->clearInsertionLock( $editObject->getWiki(), $editObject->getRevisionId() );
		}, __METHOD__ );
	}

	/**
	 * Create a new EventContribution from a database row.
	 *
	 * @param stdClass $row Database row object containing contribution data
	 * @return EventContribution New EventContribution instance
	 */
	public function newFromRow( stdClass $row ): EventContribution {
		$this->assertValidRow( $row );

		return new EventContribution(
			(int)$row->cec_event_id,
			(int)$row->cec_user_id,
			$row->cec_user_name,
			$row->cec_wiki,
			$row->cec_page_prefixedtext,
			(int)$row->cec_page_id,
			(int)$row->cec_revision_id,
			(int)$row->cec_edit_flags,
			(int)$row->cec_bytes_delta,
			(int)$row->cec_links_delta,
			(int)$row->cec_references_delta,
			$row->cec_timestamp,
			(bool)$row->cec_deleted
		);
	}

	/**
	 * Returns the base query info for querying contributions for a given event.
	 * Callers should prefer {@see getEditsQueryInfo} or {@see getEditorsQueryInfo} where applicable;
	 * use this only when building a custom field set. Callers must still add privacy filtering via
	 * addPrivateParticipantConds() or equivalent.
	 *
	 * @return array{tables: array, fields: array, conds: array, join_conds: array, options: array}
	 */
	public function getQueryInfo( int $eventId ): array {
		return [
			'tables' => [
				'cec' => 'ce_event_contributions',
				'cep' => 'ce_participants',
			],
			'fields' => [],
			'conds' => [
				'cec.cec_event_id' => $eventId,
				'cec.cec_deleted' => 0,
			],
			'join_conds' => [
				'cep' => [
					'JOIN',
					[
						'cec.cec_event_id = cep.cep_event_id',
						'cec.cec_user_id = cep.cep_user_id',
						'cep.cep_unregistered_at' => null,
					],
				],
			],
			'options' => [],
		];
	}

	/**
	 * Returns query info for the per-edit contributions pager, including all row-level fields.
	 * Callers must still apply privacy filtering.
	 *
	 * @return array{tables: array, fields: array, conds: array, join_conds: array, options: array}
	 */
	public function getEditsQueryInfo( int $eventId ): array {
		$queryInfo = $this->getQueryInfo( $eventId );
		$queryInfo['fields'] = [
			'cec_id',
			'cec_event_id',
			'cec_page_prefixedtext',
			'cec_wiki',
			'cec_user_id',
			'cec_user_name',
			self::QUERY_USERNAME_STR,
			'cec_timestamp',
			'cec_bytes_delta',
			'cec_links_delta',
			'cec_references_delta',
			'cec_edit_flags',
			'cec_revision_id',
			'cec_page_id',
			'cec_deleted',
			'cep_private',
		];
		return $queryInfo;
	}

	/**
	 * Returns query info for the per-editor contributions pager, with aggregated fields and GROUP BY.
	 * Callers must still apply privacy filtering.
	 *
	 * @return array{tables: array, fields: array, conds: array, join_conds: array, options: array}
	 */
	public function getEditorsQueryInfo( int $eventId ): array {
		$dbr = $this->dbHelper->getReplicaConnection();
		// We need to GROUP BY all non-aggregate fields to satisfy ONLY_FULL_GROUP_BY in MariaDB: even though
		// `cec_user_id` uniquely determines a row, MariaDB does not detect functional dependencies:
		// https://jira.mariadb.org/browse/MDEV-11588
		$groupByFields = [
			'cec_user_name',
			self::QUERY_USERNAME_STR,
			'cec_user_id',
			'cep_private',
		];
		$queryInfo = $this->getQueryInfo( $eventId );
		$queryInfo['fields'] = [
			...$groupByFields,
			'articles_added' => 'SUM(' . $dbr->conditional(
				$dbr->bitAnd( 'cec.cec_edit_flags', EventContribution::EDIT_FLAG_PAGE_CREATION ) . ' != 0',
				1,
				0
			) . ')',
			'articles_edited' => 'COUNT(DISTINCT ' . $dbr->conditional(
				$dbr->bitAnd( 'cec.cec_edit_flags', EventContribution::EDIT_FLAG_PAGE_CREATION ) . ' = 0',
				$dbr->buildConcat( [ 'cec.cec_wiki', $dbr->addQuotes( '|' ), 'cec.cec_page_id' ] ),
				'NULL'
			) . ')',
			'edit_count' => 'COUNT(*)',
			'bytes' => 'SUM(cec_bytes_delta)',
		];
		$queryInfo['options'] = [ 'GROUP BY' => $groupByFields ];
		return $queryInfo;
	}

	/**
	 * Get summary data for an event's contributions.
	 *
	 * @param int $eventId The event ID
	 * @param CentralUser|null $viewingUser Current user, for visibility checks (null for anonymous and non-global
	 * users, will show public data).
	 * @param bool $includePrivateParticipants Whether to include other users' private contributions
	 * @return EventContributionSummary Summary data for the event
	 */
	public function getEventSummaryData(
		int $eventId,
		?CentralUser $viewingUser,
		bool $includePrivateParticipants
	): EventContributionSummary {
		$dbr = $this->dbHelper->getReplicaConnection();

		// Build visibility conditions for private participants
		$visibilityConditions = [];
		if ( !$includePrivateParticipants ) {
			if ( $viewingUser ) {
				// Only show current user's contributions and public contributions from others
				$visibilityConditions[] = $dbr->expr( 'cec.cec_user_id', '=', $viewingUser->getCentralID() )
					->or( 'cep.cep_private', '=', 0 );
			} else {
				// Anonymous users can only see public contributions
				$visibilityConditions['cep.cep_private'] = 0;
			}
		}

		$row = $dbr->newSelectQueryBuilder()
			->select( [
				'participants_count' => 'COUNT(DISTINCT cec.cec_user_id)',
				'wikis_count' => 'COUNT(DISTINCT cec.cec_wiki)',
				'articles_created_count' => 'SUM(' . $dbr->conditional(
					$dbr->bitAnd( 'cec.cec_edit_flags', EventContribution::EDIT_FLAG_PAGE_CREATION ) . ' != 0',
					1,
					0
					) . ')',
				'articles_edited_count' => 'COUNT(DISTINCT ' . $dbr->conditional(
					$dbr->bitAnd( 'cec.cec_edit_flags', EventContribution::EDIT_FLAG_PAGE_CREATION ) . ' = 0',
					$dbr->buildConcat( [ 'cec.cec_wiki', $dbr->addQuotes( '|' ), 'cec.cec_page_id' ] ),
					'NULL'
					) . ')',
				'bytes_added' => 'SUM(' . $dbr->conditional( 'cec.cec_bytes_delta > 0',
					'cec.cec_bytes_delta', 0 ) . ')',
				'bytes_removed' => 'SUM(' . $dbr->conditional( 'cec.cec_bytes_delta < 0',
					'cec.cec_bytes_delta', 0 ) . ')',
				'links_added' => 'SUM(' . $dbr->conditional( 'cec.cec_links_delta > 0',
					'cec.cec_links_delta', 0 ) . ')',
				'links_removed' => 'SUM(' . $dbr->conditional( 'cec.cec_links_delta < 0',
					'cec.cec_links_delta', 0 ) . ')',
				'references_added' => 'SUM(' . $dbr->conditional( 'cec.cec_references_delta > 0',
					'cec.cec_references_delta', 0 ) . ')',
				'references_removed' => 'SUM(' . $dbr->conditional( 'cec.cec_references_delta < 0',
					'cec.cec_references_delta', 0 ) . ')',
				'edit_count' => 'COUNT(*)',
			] )
			->from( 'ce_event_contributions', 'cec' )
			->join(
				'ce_participants',
				'cep',
				[ 'cep.cep_event_id = cec.cec_event_id', 'cep.cep_user_id = cec.cec_user_id' ]
			)
			->where( [ 'cec.cec_event_id' => $eventId, 'cec.cec_deleted' => 0 ] )
			->andWhere( $visibilityConditions )
			->caller( __METHOD__ )
			->fetchRow();

		return new EventContributionSummary(
			(int)( $row->participants_count ?? 0 ),
			(int)( $row->wikis_count ?? 0 ),
			(int)( $row->articles_created_count ?? 0 ),
			(int)( $row->articles_edited_count ?? 0 ),
			(int)( $row->bytes_added ?? 0 ),
			(int)( $row->bytes_removed ?? 0 ),
			(int)( $row->links_added ?? 0 ),
			(int)( $row->links_removed ?? 0 ),
			(int)( $row->edit_count ?? 0 ),
			(int)( $row->references_added ?? 0 ),
			(int)( $row->references_removed ?? 0 )
		);
	}

	/**
	 * Assert that a database row has the required fields.
	 *
	 * @param stdClass $row The row to validate
	 */
	private function assertValidRow( stdClass $row ): void {
		$requiredFields = [
			'cec_event_id', 'cec_user_id', 'cec_user_name', 'cec_wiki', 'cec_page_id', 'cec_page_prefixedtext',
			'cec_revision_id', 'cec_edit_flags', 'cec_bytes_delta', 'cec_links_delta', 'cec_references_delta',
			'cec_timestamp', 'cec_deleted'
		];

		foreach ( $requiredFields as $field ) {
			if ( !property_exists( $row, $field ) ) {
				throw new InvalidArgumentException( "Missing required field: $field" );
			}
		}
	}

	public function hasContributionsForPage( ProperPageIdentity $page ): bool {
		$dbr = $this->dbHelper->getReplicaConnection();
		$row = $dbr->newSelectQueryBuilder()
			->select( '1' )
			->from( 'ce_event_contributions' )
			->where( [
				'cec_wiki' => Utils::getWikiIDString( $page->getWikiId() ),
				'cec_page_id' => $page->getId( $page->getWikiId() )
			] )
			->caller( __METHOD__ )
			->fetchField();
		return $row !== false;
	}

	/**
	 * Fetch a single contribution by its primary key.
	 *
	 * @param int $contribID The cec_id
	 * @return EventContribution|null The contribution object, or null if not found
	 */
	public function getByID( int $contribID ): ?EventContribution {
		$dbr = $this->dbHelper->getReplicaConnection();
		$row = $dbr->newSelectQueryBuilder()
			->select( '*' )
			->from( 'ce_event_contributions' )
			->where( [ 'cec_id' => $contribID ] )
			->caller( __METHOD__ )
			->fetchRow();
		if ( !$row ) {
			return null;
		}
		return $this->newFromRow( $row );
	}

	/**
	 * Permanently delete a contribution by its primary key.
	 *
	 * @param int $contribID The cec_id to delete; validation is up to the caller.
	 */
	public function deleteByID( int $contribID ): void {
		$dbw = $this->dbHelper->getPrimaryConnection();
		$row = $dbw->newSelectQueryBuilder()
			->select( [ 'cec_wiki', 'cec_revision_id' ] )
			->from( 'ce_event_contributions' )
			->where( [ 'cec_id' => $contribID ] )
			->caller( __METHOD__ )
			->fetchRow();
		if ( !$row ) {
			// Should typically not happen, but potentially might if the caller validated from a lagged replica.
			return;
		}
		$dbw->newDeleteQueryBuilder()
			->deleteFrom( 'ce_event_contributions' )
			->where( [ 'cec_id' => $contribID ] )
			->caller( __METHOD__ )
			->execute();
		$dbw->onTransactionPreCommitOrIdle( function () use ( $row ): void {
			$this->clearInsertionLock( $row->cec_wiki, (int)$row->cec_revision_id );
		}, __METHOD__ );
	}

	/**
	 * Tries to lock insertion for the given revision. If a row already exists, or if a lock for the same revision has
	 * already been acquired, returns the ID of the event of the existing (or tentative) association. Otherwise it
	 * acquires the lock and returns null.
	 * This is necessary because while validation happens synchronously, the actual insertion is deferred and performed
	 * via the JobQueue. Therefore, we need to avoid situations where two requests try to insert a row for the same
	 * revision, see T422844. This solution should work sufficiently well, provided a cache backend is available.
	 * Additionally, we rely on job deduplication and a unique index on (cec_wiki, cec_revision_id).
	 */
	public function tryAcquireInsertionLock( string $wikiID, int $revisionID, int $eventID ): ?int {
		$fname = __METHOD__;
		$newlyAcquired = false;
		$previousEventID = $this->wanCache->getWithSetCallback(
			$this->makeInsertionLockKey( $wikiID, $revisionID ),
			WANObjectCache::TTL_HOUR,
			function () use ( $wikiID, $revisionID, $eventID, $fname, &$newlyAcquired ): int {
				// If no lock is set, check the database.
				$dbr = $this->dbHelper->getReplicaConnection();
				$storedEventID = $dbr->newSelectQueryBuilder()
					->select( 'cec_event_id' )
					->from( 'ce_event_contributions' )
					->where( [
						'cec_wiki' => $wikiID,
						'cec_revision_id' => $revisionID,
					] )
					->caller( $fname )
					->fetchField();
				// Return (and cache) the stored ID if set...
				if ( $storedEventID !== false ) {
					return (int)$storedEventID;
				}
				// Otherwise, acquire the lock now for the given event
				$newlyAcquired = true;
				return $eventID;
			}
		);

		// Note, it's important that we return null for a newly-acquired lock, as otherwise callers would
		// have no way to distinguish between lock acquired, and the revision being already associated with
		// the requested event.
		return $newlyAcquired ? null : $previousEventID;
	}

	public function clearInsertionLock( string $wikiID, int $revisionID ): void {
		$this->wanCache->delete( $this->makeInsertionLockKey( $wikiID, $revisionID ) );
	}

	private function makeInsertionLockKey( string $wikiID, int $revisionID ): string {
		return $this->wanCache->makeGlobalKey( 'CampaignEvents-contribution-insert', $wikiID, $revisionID );
	}

	/**
	 * @phan-param mixed[] $where
	 * @phan-param mixed[] $set
	 */
	private function doBatchedUpdate( IDatabase $dbw, array $where, array $set ): void {
		$lastBatchIDs = [];
		do {
			$curBatchIDs = $dbw->newSelectQueryBuilder()
				->select( 'cec_id' )
				->from( 'ce_event_contributions' )
				->where( $where )
				->limit( self::UPDATES_BATCH_SIZE )
				->caller( __METHOD__ )
				->fetchFieldValues();

			if ( !$curBatchIDs ) {
				break;
			}

			if ( $curBatchIDs === $lastBatchIDs ) {
				throw new LogicException(
					'Infinite recursion detected! Make sure the WHERE conditions filter out already updated rows.'
				);
			}

			$dbw->newUpdateQueryBuilder()
				->update( 'ce_event_contributions' )
				->set( $set )
				->where( [ 'cec_id' => $curBatchIDs ] )
				->caller( __METHOD__ )
				->execute();

			$lastBatchIDs = $curBatchIDs;
		} while ( true );
	}

	public function updateTitle( string $wiki, int $pageID, string $newPrefixedText ): void {
		$dbw = $this->dbHelper->getPrimaryConnection();
		$this->doBatchedUpdate(
			$dbw,
			[
				'cec_wiki' => $wiki,
				'cec_page_id' => $pageID,
				$dbw->expr( 'cec_page_prefixedtext', '!=', $newPrefixedText ),
			],
			[ 'cec_page_prefixedtext' => $newPrefixedText ]
		);
	}

	private function updateVisibilityForPage( string $wiki, int $pageID, bool $deleted ): void {
		$this->doBatchedUpdate(
			$this->dbHelper->getPrimaryConnection(),
			[
				'cec_wiki' => $wiki,
				'cec_page_id' => $pageID,
				'cec_deleted' => (int)!$deleted,
			],
			[ 'cec_deleted' => (int)$deleted ]
		);
	}

	public function updateForPageDeleted( string $wiki, int $pageID ): void {
		$this->updateVisibilityForPage( $wiki, $pageID, true );
	}

	public function updateForPageRestored( string $wiki, int $pageID ): void {
		$this->updateVisibilityForPage( $wiki, $pageID, false );
	}

	/**
	 * @phan-param list<int> $deletedRevIDs
	 * @phan-param list<int> $restoredRevIDs
	 */
	public function updateRevisionVisibility(
		string $wiki,
		int $pageID,
		array $deletedRevIDs,
		array $restoredRevIDs
	): void {
		$dbw = $this->dbHelper->getPrimaryConnection();
		$whereBase = [
			'cec_wiki' => $wiki,
			// The page ID is technically redundant, but is included here because cec_revision_id is not indexed, so
			// the following queries can use the index on wiki+page instead, and then scan and filter the matches.
			'cec_page_id' => $pageID,
		];
		foreach ( array_chunk( $deletedRevIDs, self::UPDATES_BATCH_SIZE ) as $deletedRevsBatch ) {
			$dbw->newUpdateQueryBuilder()
				->update( 'ce_event_contributions' )
				->set( [ 'cec_deleted' => 1 ] )
				->where( $whereBase )
				->andWhere( [ 'cec_revision_id' => $deletedRevsBatch ] )
				->caller( __METHOD__ )
				->execute();
		}
		foreach ( array_chunk( $restoredRevIDs, self::UPDATES_BATCH_SIZE ) as $restoredRevsBatch ) {
			$dbw->newUpdateQueryBuilder()
				->update( 'ce_event_contributions' )
				->set( [ 'cec_deleted' => 0 ] )
				->where( $whereBase )
				->andWhere( [ 'cec_revision_id' => $restoredRevsBatch ] )
				->caller( __METHOD__ )
				->execute();
		}
	}

	public function hasContributionsFromUser( CentralUser $user ): bool {
		$dbr = $this->dbHelper->getReplicaConnection();
		$res = $dbr->newSelectQueryBuilder()
			->select( '1' )
			->from( 'ce_event_contributions' )
			->where( [
				'cec_user_id' => $user->getCentralID()
			] )
			->caller( __METHOD__ )
			->fetchField();
		return $res !== false;
	}

	public function updateUserName( CentralUser $user, string $newUserName ): void {
		$dbw = $this->dbHelper->getPrimaryConnection();
		$this->doBatchedUpdate(
			$dbw,
			[
				'cec_user_id' => $user->getCentralID(),
				$dbw->expr( 'cec_user_name', '!=', $newUserName ),
			],
			[ 'cec_user_name' => $newUserName ]
		);
	}

	/**
	 * Updates a user's visibility. The username needs to be passed in if and only if $isHidden is false.
	 * A null cec_user_name is used to indicate a deleted/hidden user; in particular, cec_deleted is unaffected.
	 */
	public function updateUserVisibility( CentralUser $user, bool $isHidden, ?string $userName = null ): void {
		if ( !$isHidden && !$userName ) {
			throw new BadMethodCallException( 'Missing required $userName for user unhide.' );
		}
		$newDBName = $isHidden ? null : $userName;
		$dbw = $this->dbHelper->getPrimaryConnection();
		$whereInequality = $dbw->expr( 'cec_user_name', '!=', $newDBName );
		if ( $newDBName !== null ) {
			// The column is nullable, so when the RHS is a string `cec_user_name != 'literal'` will fail for null
			// values. So, compare with null explicitly.
			$whereInequality = $whereInequality->or( 'cec_user_name', '=', null );
		}
		$this->doBatchedUpdate(
			$dbw,
			[
				'cec_user_id' => $user->getCentralID(),
				$whereInequality,
			],
			[ 'cec_user_name' => $newDBName ]
		);
	}
}
