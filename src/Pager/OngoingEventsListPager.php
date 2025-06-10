<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Pager;

use MediaWiki\Cache\LinkBatchFactory;
use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\Event\EventTypesRegistry;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventTopicsStore;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventWikisStore;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFactory;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageURLResolver;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserLinker;
use MediaWiki\Extension\CampaignEvents\MWEntity\WikiLookup;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Topics\ITopicRegistry;
use MediaWiki\Pager\IndexPager;
use MediaWiki\Pager\ReverseChronologicalPager;
use MediaWiki\User\Options\UserOptionsLookup;
use Wikimedia\Assert\Assert;

class OngoingEventsListPager extends EventsListPager {
	/** @var bool Restore grandparent ordering, so events are ordered from newest to oldest. */
	public $mDefaultDirection = IndexPager::DIR_DESCENDING;

	/**
	 * Same as parent constructor, but start date is required and there is no end date.
	 *
	 * @phan-param list<string> $filterWiki
	 * @phan-param list<string> $filterTopics
	 * @phan-param list<string> $filterEventTypes
	 */
	public function __construct(
		UserLinker $userLinker,
		CampaignsPageFactory $pageFactory,
		PageURLResolver $pageURLResolver,
		OrganizersStore $organizerStore,
		LinkBatchFactory $linkBatchFactory,
		UserOptionsLookup $userOptionsLookup,
		CampaignsDatabaseHelper $databaseHelper,
		CampaignsCentralUserLookup $centralUserLookup,
		WikiLookup $wikiLookup,
		EventWikisStore $eventWikisStore,
		ITopicRegistry $topicRegistry,
		EventTopicsStore $eventTopicsStore,
		EventTypesRegistry $eventTypesRegistry,
		IContextSource $context,
		string $search,
		?int $participationOptions,
		string $startDate,
		array $filterWiki,
		bool $includeAllWikis,
		array $filterTopics,
		array $filterEventTypes
	) {
		parent::__construct(
			$userLinker,
			$pageFactory,
			$pageURLResolver,
			$organizerStore,
			$linkBatchFactory,
			$userOptionsLookup,
			$databaseHelper,
			$centralUserLookup,
			$wikiLookup,
			$eventWikisStore,
			$topicRegistry,
			$eventTopicsStore,
			$eventTypesRegistry,
			$context,
			$search,
			$participationOptions,
			$startDate,
			null,
			$filterWiki,
			$includeAllWikis,
			$filterTopics,
			$filterEventTypes
		);
	}

	/**
	 * @inheritDoc
	 */
	public function buildQueryInfo( $offset, $limit, $order ): array {
		[
			$tables,
			$fields,
			$conds,
			$fname,
			$options,
			$join_conds
		] = ReverseChronologicalPager::buildQueryInfo( $offset, $limit, $order );

		$startOffset = $this->getDateRangeCond( $this->startDate, $this->endDate )[0];

		Assert::postcondition( $startOffset !== null, 'Start date is required' );
		$conds[] = $this->mDb->expr( 'event_start_utc', '<=', $startOffset );
		$conds[] = $this->mDb->expr( 'event_end_utc', '>=', $startOffset );

		return [ $tables, $fields, $conds, $fname, $options, $join_conds ];
	}
}
