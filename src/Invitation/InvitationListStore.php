<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Invitation;

use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Page\PageIdentityValue;
use MediaWiki\Page\PageStoreFactory;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\Assert\Assert;
use Wikimedia\Rdbms\IResultWrapper;
use Wikimedia\Rdbms\SelectQueryBuilder;

class InvitationListStore {
	public const SERVICE_NAME = 'CampaignEventsInvitationListStore';

	private CampaignsDatabaseHelper $databaseHelper;
	private PageStoreFactory $pageStoreFactory;

	public function __construct(
		CampaignsDatabaseHelper $databaseHelper,
		PageStoreFactory $pageStoreFactory
	) {
		$this->databaseHelper = $databaseHelper;
		$this->pageStoreFactory = $pageStoreFactory;
	}

	public function createInvitationList(
		string $name,
		?int $eventID,
		CentralUser $creator
	): int {
		$dbw = $this->databaseHelper->getDBConnection( DB_PRIMARY );
		$dbw->newInsertQueryBuilder()
			->insertInto( 'ce_invitation_lists' )
			->row( [
				'ceil_name' => $name,
				'ceil_event_id' => $eventID,
				'ceil_status' => InvitationList::STATUS_PENDING,
				'ceil_user_id' => $creator->getCentralID(),
				'ceil_wiki' => WikiMap::getCurrentWikiId(),
				'ceil_created_at' => $dbw->timestamp(),
			] )
			->caller( __METHOD__ )
			->execute();

		return $dbw->insertId();
	}

	public function storeWorklist( int $invitationListID, Worklist $worklist ): void {
		$pagesByWiki = $worklist->getPagesByWiki();
		$curWikiID = WikiMap::getCurrentWikiId();
		Assert::precondition( count( $pagesByWiki ) === 1, 'Max 1 wiki' );
		Assert::precondition( key( $pagesByWiki ) === $curWikiID, 'Pages must be local' );
		$localPages = $pagesByWiki[$curWikiID];
		$rows = [];
		foreach ( $localPages as $article ) {
			$rows[] = [
				'cewa_page_id' => $article->getId( $article->getWikiId() ),
				'cewa_page_title' => $article->getDBkey(),
				'cewa_ceil_id' => $invitationListID,
			];
		}
		$dbw = $this->databaseHelper->getDBConnection( DB_PRIMARY );
		$dbw->newInsertQueryBuilder()
			->insertInto( 'ce_worklist_articles' )
			->rows( $rows )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * @param int $invitationList
	 * @param int $status One of the InvitationList::STATUS_* constants
	 */
	public function updateStatus( int $invitationList, int $status ): void {
		$dbw = $this->databaseHelper->getDBConnection( DB_PRIMARY );
		$dbw->newUpdateQueryBuilder()
			->update( 'ce_invitation_lists' )
			->set( [ 'ceil_status' => $status ] )
			->where( [ 'ceil_id' => $invitationList ] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * @param int $invitationListID
	 * @param array<int,int> $users [ user ID => score ]
	 */
	public function storeInvitationListUsers( int $invitationListID, array $users ): void {
		$rows = [];
		foreach ( $users as $userID => $score ) {
			$rows[] = [
				'ceilu_user_id' => $userID,
				'ceilu_ceil_id' => $invitationListID,
				'ceilu_score' => $score
			];
		}
		$dbw = $this->databaseHelper->getDBConnection( DB_PRIMARY );
		$dbw->newInsertQueryBuilder()
			->insertInto( 'ce_invitation_list_users' )
			->rows( $rows )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * @throws InvitationListNotFoundException
	 */
	public function getInvitationList( int $listID ): InvitationList {
		$dbr = $this->databaseHelper->getDBConnection( DB_REPLICA );
		$row = $dbr->newSelectQueryBuilder()
			->select( '*' )
			->from( 'ce_invitation_lists' )
			->where( [ 'ceil_id' => $listID ] )
			->caller( __METHOD__ )
			->fetchRow();

		if ( !$row ) {
			throw new InvitationListNotFoundException( $listID );
		}

		$eventID = $row->ceil_event_id !== null ? (int)$row->ceil_event_id : null;
		return new InvitationList(
			(int)$row->ceil_id,
			$row->ceil_name,
			$eventID,
			(int)$row->ceil_status,
			new CentralUser( (int)$row->ceil_user_id ),
			$row->ceil_wiki,
			$row->ceil_created_at
		);
	}

	public function getWorklist( int $invitationListID ): Worklist {
		$dbr = $this->databaseHelper->getDBConnection( DB_REPLICA );
		$invitationListWiki = $dbr->newSelectQueryBuilder()
			->select( 'ceil_wiki' )
			->from( 'ce_invitation_lists' )
			->where( [ 'ceil_id' => $invitationListID ] )
			->caller( __METHOD__ )
			->fetchField();
		$res = $dbr->newSelectQueryBuilder()
			->select( '*' )
			->from( 'ce_worklist_articles' )
			->where( [ 'cewa_ceil_id' => $invitationListID ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		$pages = $this->loadPagesFromDB( $res, $invitationListWiki );
		return new Worklist( [ $invitationListWiki => $pages ] );
	}

	/**
	 * @return PageIdentity[]
	 */
	private function loadPagesFromDB( IResultWrapper $rows, string $invitationListWiki ): array {
		$titlesById = [];
		foreach ( $rows as $row ) {
			$titlesById[$row->cewa_page_id] = $row->cewa_page_title;
		}

		$wikiID = $invitationListWiki === WikiMap::getCurrentWikiId() ? WikiAwareEntity::LOCAL : $invitationListWiki;
		$pageStore = $this->pageStoreFactory->getPageStore( $wikiID );
		// Note: PageSelectQueryBuilder uses LinkCache internally, so all the pages get automatically cached.
		$pageRecords = $pageStore->newSelectQueryBuilder()
			->wherePageIds( array_keys( $titlesById ) )
			->caller( __METHOD__ )
			->fetchPageRecordArray();

		$pages = [];
		foreach ( $titlesById as $id => $title ) {
			if ( isset( $pageRecords[$id] ) ) {
				$pages[] = $pageRecords[$id];
			} else {
				// XXX: If a page is deleted and then recreated from scratch, we'd consider it as nonexistent and show
				// a red link; should be fixable by querying the titles in addition to page IDs, but probably
				// unnecessary for now.
				$pages[] = new PageIdentityValue( 0, NS_MAIN, $title, $wikiID );
			}
		}
		return $pages;
	}

	/**
	 * @return array<int,int> [ user => score ] A maximum of 200 users is returned, ordered by score (high to low)
	 */
	public function getInvitationListUsers( int $invitationListID ): array {
		$dbr = $this->databaseHelper->getDBConnection( DB_REPLICA );
		$res = $dbr->newSelectQueryBuilder()
			->select( [ 'ceilu_user_id', 'ceilu_score' ] )
			->from( 'ce_invitation_list_users' )
			->where( [ 'ceilu_ceil_id' => $invitationListID ] )
			->orderBy( 'ceilu_score', SelectQueryBuilder::SORT_DESC )
			->limit( PotentialInviteesFinder::RESULT_USER_LIMIT )
			->caller( __METHOD__ )
			->fetchResultSet();

		$users = [];
		foreach ( $res as $row ) {
			$users[$row->ceilu_user_id] = (int)$row->ceilu_score;
		}
		return $users;
	}
}
