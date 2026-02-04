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
use Wikimedia\Rdbms\IDatabase;

/**
 * Store for managing event contributions.
 */
class EventContributionStore {

	public const SERVICE_NAME = 'CampaignEventsEventContributionStore';

	private const UPDATES_BATCH_SIZE = 500;

	public function __construct(
		private readonly CampaignsDatabaseHelper $dbHelper,
	) {
	}

	/**
	 * Associate an edit with an event.
	 *
	 * @param EventContribution $editObject The edit contribution to associate
	 */
	public function saveEventContribution( EventContribution $editObject ): void {
		$dbw = $this->dbHelper->getDBConnection( DB_PRIMARY );

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
				'cec_timestamp' => $dbw->timestamp( $editObject->getTimestamp() ),
				'cec_deleted' => $editObject->isDeleted() ? 1 : 0,
			] )
			->caller( __METHOD__ )
			->execute();
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
			$row->cec_timestamp,
			(bool)$row->cec_deleted
		);
	}

	/**
	 * Get summary data for an event's contributions.
	 *
	 * @param int $eventId The event ID
	 * @param int $currentUserId Current user ID for visibility checks (0 for anonymous users)
	 * @param bool $includePrivateParticipants Whether to include other users' private contributions
	 * @return EventContributionSummary Summary data for the event
	 */
	public function getEventSummaryData(
		int $eventId,
		int $currentUserId,
		bool $includePrivateParticipants
	): EventContributionSummary {
		$dbr = $this->dbHelper->getDBConnection( DB_REPLICA );

		// Build visibility conditions for private participants
		$visibilityConditions = [];
		if ( !$includePrivateParticipants ) {
			if ( $currentUserId ) {
				// Only show current user's contributions and public contributions from others
				$visibilityConditions[] = $dbr->expr( 'cec.cec_user_id', '=', $currentUserId )
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
			(int)( $row->edit_count ?? 0 )
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
			'cec_revision_id', 'cec_edit_flags', 'cec_bytes_delta', 'cec_links_delta', 'cec_timestamp', 'cec_deleted'
		];

		foreach ( $requiredFields as $field ) {
			if ( !property_exists( $row, $field ) ) {
				throw new InvalidArgumentException( "Missing required field: $field" );
			}
		}
	}

	public function hasContributionsForPage( ProperPageIdentity $page ): bool {
		$dbr = $this->dbHelper->getDBConnection( DB_REPLICA );
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
		$dbr = $this->dbHelper->getDBConnection( DB_REPLICA );
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
	 * @param int $contribID The cec_id to delete
	 */
	public function deleteByID( int $contribID ): void {
		$dbw = $this->dbHelper->getDBConnection( DB_PRIMARY );
		$dbw->newDeleteQueryBuilder()
			->deleteFrom( 'ce_event_contributions' )
			->where( [ 'cec_id' => $contribID ] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * Returns the ID of the event with which the given revision is associated, or null if it's not
	 * associated with any events.
	 */
	public function getEventIDForRevision( string $wikiID, int $revisionID ): ?int {
		$dbr = $this->dbHelper->getDBConnection( DB_REPLICA );
		$eventID = $dbr->newSelectQueryBuilder()
			->select( 'cec_event_id' )
			->from( 'ce_event_contributions' )
			->where( [
				'cec_wiki' => $wikiID,
				'cec_revision_id' => $revisionID,
			] )
			->caller( __METHOD__ )
			->fetchField();
		return $eventID !== false ? (int)$eventID : null;
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
		$dbw = $this->dbHelper->getDBConnection( DB_PRIMARY );
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
			$this->dbHelper->getDBConnection( DB_PRIMARY ),
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
		$dbw = $this->dbHelper->getDBConnection( DB_PRIMARY );
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
		$dbr = $this->dbHelper->getDBConnection( DB_REPLICA );
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
		$dbw = $this->dbHelper->getDBConnection( DB_PRIMARY );
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
		$dbw = $this->dbHelper->getDBConnection( DB_PRIMARY );
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
