<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Pager;

use MediaWiki\Cache\LinkBatchFactory;
use MediaWiki\Config\Config;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFactory;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWPageProxy;
use MediaWiki\WikiMap\WikiMap;
use stdClass;
use Wikimedia\Rdbms\IExpression;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\IResultWrapper;
use Wikimedia\Rdbms\LikeValue;
use Wikimedia\Rdbms\Subquery;

/**
 * @property string $search;
 * @property string $status;
 * @property IReadableDatabase $mDb;
 * @property LinkBatchFactory $linkBatchFactory;
 * @property CampaignsPageFactory $campaignsPageFactory;
 * @method Config getConfig()
 */
trait EventPagerTrait {
	/** @var array<int,MWPageProxy> Cache of event page objects, keyed by event ID */
	private array $eventPageCache = [];

	/**
	 * @todo The joins and grouping below are not used by EventsListPager (which wouldn't even need a subquery), and
	 * they just slow the query down. We should either implement those features in the list pager, or move the
	 * complexity to EventsTablePager.
	 * @return array<string,mixed>
	 */
	public function getSubqueryInfo(): array {
		$eventFields = [
			'event_id',
			'event_name',
			'event_page_namespace',
			'event_page_title',
			'event_page_wiki',
			'event_page_prefixedtext',
			'event_chat_url',
			'event_status',
			'event_timezone',
			'event_start_local',
			'event_start_utc',
			'event_end_local',
			'event_end_utc',
			'event_types',
			'event_meeting_type',
			'event_meeting_url',
			'event_created_at',
			'event_last_edit',
			'event_deleted_at',
			'event_is_test_event',
		];
		if ( $this->getConfig()->get( 'CampaignEventsEnableContributionTracking' ) ) {
			$eventFields[] = 'event_track_contributions';
		}
		return [
			'tables' => [ 'campaign_events', 'ce_participants', 'ce_organizers' ],
			'fields' => [
				...$eventFields,
				'num_participants' => 'COUNT(cep_id)'
			],
			'conds' => [
					'event_deleted_at' => null,
			],
			'options' => [
				'GROUP BY' => [
					'cep_event_id',
					// We need to GROUP BY all fields to pass ONLY_FULL_GROUP_BY in MariaDB: even though
					// `cep_event_id` uniquely determines a row, MariaDB does not detect functional dependencies:
					// https://jira.mariadb.org/browse/MDEV-11588
					...$eventFields,
				]
			],
			'join_conds' => [
				'ce_participants' => [
					'LEFT JOIN',
					[
						'event_id=cep_event_id',
						'cep_unregistered_at' => null,
					]
				],
				'ce_organizers' => [
					'JOIN',
					[
						'event_id=ceo_event_id',
						'ceo_deleted_at' => null,
					]
				],
			],
		];
	}

	/**
	 * @inheritDoc
	 * @return array<string,mixed>
	 */
	public function getQueryInfo(): array {
		// Use a subquery and a temporary table to work around IndexPager not using HAVING for aggregates (T308694)
		// and to support postgres (which doesn't allow aliases in HAVING).
		$subqueryInfo = $this->getSubqueryInfo();
		$subquery = $this->mDb->newSelectQueryBuilder()
			->queryInfo( $subqueryInfo )
			->caller( __METHOD__ );
		$conds = [];
		if ( $this->search !== '' ) {
			// TODO Make this case-insensitive. Not easy right now because the name is a binary string and the DBAL does
			// not provide a method for converting it to a non-binary value on which LOWER can be applied.
			$conds[] = $this->mDb->expr( 'event_name', IExpression::LIKE,
				new LikeValue( $this->mDb->anyString(), $this->search, $this->mDb->anyString() ) );
		}
		return [
			'tables' => [ 'tmp' => new Subquery( $subquery->getSQL() ) ],
			'fields' => '*',
			'conds' => $conds,
			'options' => [],
			'join_conds' => []
		];
	}

	/**
	 * Add event pages to a LinkBatch to improve performance and not make one query per page.
	 * This code was stolen from AbuseFilter's pager et al.
	 * @param IResultWrapper $result
	 * @suppress PhanPluginCanUseParamType The method is inherited from IndexPager
	 */
	protected function preprocessResults( $result ): void {
		// Error suppressed, method is declared in inheritor
		// @phan-suppress-next-line PhanUndeclaredMethod
		if ( $this->getNumRows() === 0 ) {
			return;
		}
		$linkBatchFactory = $this->linkBatchFactory;
		$lb = $linkBatchFactory->newLinkBatch();
		$lb->setCaller( __METHOD__ );
		$curWikiID = WikiMap::getCurrentWikiId();
		foreach ( $result as $row ) {
			// XXX LinkCache only supports local pages, and it's not used in foreign instances of PageStore.
			if ( $row->event_page_wiki === $curWikiID ) {
				$lb->add( (int)$row->event_page_namespace, $row->event_page_title );
			}
		}
		$lb->execute();
		$result->seek( 0 );
		$this->doExtraPreprocessing( $result );
	}

	/**
	 * Override this method to run extra preprocessing steps on the result set.
	 */
	protected function doExtraPreprocessing( IResultWrapper $result ): void {
	}

	private function getEventPageFromRow( stdClass $eventRow ): MWPageProxy {
		$eventID = $eventRow->event_id;
		$eventPageCache = $this->eventPageCache;
		$campaignsPageFactory = $this->campaignsPageFactory;
		if ( !isset( $eventPageCache[$eventID] ) ) {
			$eventPageCache[$eventID] = $campaignsPageFactory->newPageFromDB(
				(int)$eventRow->event_page_namespace,
				$eventRow->event_page_title,
				$eventRow->event_page_prefixedtext,
				$eventRow->event_page_wiki
			);
		}
		return $eventPageCache[$eventID];
	}
}
