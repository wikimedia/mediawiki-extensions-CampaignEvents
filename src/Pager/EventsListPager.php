<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Pager;

use MediaWiki\Cache\LinkBatchFactory;
use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventStore;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventTopicsStore;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventWikisStore;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFactory;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageURLResolver;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserLinker;
use MediaWiki\Extension\CampaignEvents\MWEntity\WikiLookup;
use MediaWiki\Extension\CampaignEvents\Organizers\Organizer;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Organizers\Roles;
use MediaWiki\Extension\CampaignEvents\Special\SpecialEventDetails;
use MediaWiki\Extension\CampaignEvents\Topics\ITopicRegistry;
use MediaWiki\Extension\CampaignEvents\Widget\TextWithIconWidget;
use MediaWiki\Html\Html;
use MediaWiki\Pager\IndexPager;
use MediaWiki\Pager\ReverseChronologicalPager;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\Options\UserOptionsLookup;
use MediaWiki\Utils\MWTimestamp;
use MediaWiki\WikiMap\WikiMap;
use OOUI\Exception;
use OOUI\HtmlSnippet;
use OOUI\Tag;
use stdClass;
use UnexpectedValueException;
use Wikimedia\Rdbms\IResultWrapper;
use Wikimedia\Timestamp\TimestampException;

class EventsListPager extends ReverseChronologicalPager {
	use EventPagerTrait {
		EventPagerTrait::getSubqueryInfo as getDefaultSubqueryInfo;
	}

	private const DISPLAYED_WIKI_COUNT = 3;
	private UserLinker $userLinker;
	private CampaignsPageFactory $campaignsPageFactory;
	private PageURLResolver $pageURLResolver;
	private OrganizersStore $organizerStore;
	private LinkBatchFactory $linkBatchFactory;
	private UserOptionsLookup $userOptionsLookup;
	private CampaignsCentralUserLookup $centralUserLookup;
	private WikiLookup $wikiLookup;
	private EventWikisStore $eventWikisStore;
	private ITopicRegistry $topicRegistry;
	private EventTopicsStore $eventTopicsStore;

	private string $search;
	/** One of the EventRegistration::MEETING_TYPE_* constants */
	private ?int $meetingType;
	/** dbnames of the wikis chosen */
	private array $filterWiki;
	/** @var string[] */
	private array $filterTopics;
	private string $startDate;
	private string $endDate;
	private bool $showOngoing;

	private string $lastHeaderTimestamp;
	/** @var array<int,Organizer|null> Maps event ID to the event creator, if available, else to null. */
	private array $creators = [];
	/**
	 * @var array<int,Organizer[]> Maps event ID to a list of additional event organizers,
	 * NOT including the event creator.
	 */
	private array $extraOrganizers = [];
	/** @var array<int,int> Maps event ID to the total number of organizers of that event. */
	private array $organizerCounts = [];
	/** @var array<int,string[]|true> Maps event ID to all wikis assigned to the event. */
	private array $eventWikis = [];
	/** @var array<int,string[]> Maps event ID to all topics assigned to the event. */
	private array $eventTopics = [];

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
		string $search,
		?int $meetingType,
		string $startDate,
		string $endDate,
		bool $showOngoing,
		array $filterWiki,
		array $filterTopics
	) {
		// Set the database before calling the parent constructor, otherwise it'll use the local one.
		$this->mDb = $databaseHelper->getDBConnection( DB_REPLICA );
		parent::__construct( $this->getContext(), $this->getLinkRenderer() );

		$this->userLinker = $userLinker;
		$this->campaignsPageFactory = $pageFactory;
		$this->pageURLResolver = $pageURLResolver;
		$this->organizerStore = $organizerStore;
		$this->linkBatchFactory = $linkBatchFactory;
		$this->userOptionsLookup = $userOptionsLookup;
		$this->centralUserLookup = $centralUserLookup;
		$this->wikiLookup = $wikiLookup;
		$this->eventWikisStore = $eventWikisStore;
		$this->topicRegistry = $topicRegistry;
		$this->eventTopicsStore = $eventTopicsStore;

		$this->search = $search;
		$this->meetingType = $meetingType;
		$this->startDate = $startDate;
		$this->endDate = $endDate;
		$this->showOngoing = $showOngoing;

		$this->getDateRangeCond( $startDate, $endDate );
		$this->mDefaultDirection = IndexPager::DIR_ASCENDING;
		$this->lastHeaderTimestamp = '';
		$this->filterWiki = $filterWiki;
		$this->filterTopics = $filterTopics;
	}

	/**
	 * @see EventPagerTrait::doExtraPreprocessing
	 */
	private function doExtraPreprocessing( IResultWrapper $result ): void {
		$eventIDs = [];
		foreach ( $result as $row ) {
			$eventIDs[] = (int)$row->event_id;
		}
		$result->seek( 0 );

		$this->eventWikis = $this->eventWikisStore->getEventWikisMulti( $eventIDs );
		$this->eventTopics = $this->eventTopicsStore->getEventTopicsMulti( $eventIDs );

		$this->creators = $this->organizerStore->getEventCreators(
			$eventIDs,
			OrganizersStore::GET_CREATOR_EXCLUDE_DELETED
		);
		$this->extraOrganizers = $this->organizerStore->getOrganizersForEvents( $eventIDs, 2 );
		$this->organizerCounts = $this->organizerStore->getOrganizerCountForEvents( $eventIDs );

		$organizerUserIDsMap = [];
		foreach ( $this->creators as $creator ) {
			if ( $creator ) {
				$organizerUserIDsMap[$creator->getUser()->getCentralID()] = null;
			}
		}
		foreach ( $this->extraOrganizers as $eventExtraOrganizer ) {
			foreach ( $eventExtraOrganizer as $organizer ) {
				$organizerUserIDsMap[ $organizer->getUser()->getCentralID() ] = null;
			}
		}

		// Run a single query to get all the organizer names at once, and also check for user page existence.
		$organizerNames = $this->centralUserLookup->getNamesIncludingDeletedAndSuppressed( $organizerUserIDsMap );
		$this->userLinker->preloadUserLinks( $organizerNames );
	}

	/**
	 * @inheritDoc
	 */
	public function getRow( $row ): string {
		$s = '';

		$timestampField = $this->getTimestampField();
		$timestamp = $row->$timestampField;
		$closeList = $this->isHeaderRowNeeded( $timestamp );
		if ( $closeList ) {
			$s .= $this->getEndGroup();
		}
		if ( $this->isHeaderRowNeeded( $timestamp ) ) {
			$s .= $this->getHeaderRow( $this->getMonthHeader( $timestamp ) );
			$this->lastHeaderTimestamp = $timestamp;
		}
		$s .= $this->formatRow( $row );

		return $s;
	}

	/**
	 * Copied from {@see TablePager::getLimitSelectList()}.
	 * XXX This should probably live elsewhere in core and be easier to reuse.
	 *
	 * @return array
	 */
	public function getLimitSelectList() {
		# Add the current limit from the query string
		# to avoid that the limit is lost after clicking Go next time
		if ( !in_array( $this->mLimit, $this->mLimitsShown, true ) ) {
			$this->mLimitsShown[] = $this->mLimit;
			sort( $this->mLimitsShown );
		}
		$ret = [];
		foreach ( $this->mLimitsShown as $key => $value ) {
			# The pair is either $index => $limit, in which case the $value
			# will be numeric, or $limit => $text, in which case the $value
			# will be a string.
			if ( is_int( $value ) ) {
				$limit = $value;
				$text = $this->getLanguage()->formatNum( $limit );
			} else {
				$limit = $key;
				$text = $value;
			}
			$ret[$text] = $limit;
		}
		return $ret;
	}

	protected function isHeaderRowNeeded( string $date ): bool {
		if ( !$this->lastHeaderTimestamp ) {
			return true;
		}
		$month = $this->getMonthHeader( $date );
		$prevMonth = $this->getMonthHeader( $this->lastHeaderTimestamp );
		$year = $this->getYearFromTimestamp( $date );
		$prevYear = $this->getYearFromTimestamp( $this->lastHeaderTimestamp );
		return $month !== $prevMonth || $year !== $prevYear;
	}

	/**
	 * @inheritDoc
	 */
	public function formatRow( $row ) {
		$htmlRow = ( new Tag( 'li' ) )
			->addClasses( [ 'ext-campaignevents-events-list-row' ] );
		$page = $this->getEventPageFromRow( $row );
		$pageUrlResolver = $this->pageURLResolver;
		$timestampField = $this->getTimestampField();
		$timestamp = $row->$timestampField;
		$htmlRow->appendContent( ( new Tag() )
			->addClasses( [ 'ext-campaignevents-events-list-day' ] )
			->appendContent( $this->getDayFromTimestamp( $timestamp ) ) );
		$detailContainer = ( new Tag() )
			->addClasses( [ 'ext-campaignevents-events-list-details' ] );
		$eventPageLinkElement = ( new Tag( 'a' ) )
			->setAttributes( [
				"href" => $pageUrlResolver->getUrl( $page ),
				"class" => 'ext-campaignevents-events-list-link'
			] )
			->appendContent( $row->event_name );
		$detailContainer->appendContent(
			( new Tag( 'h4' ) )->appendContent( $eventPageLinkElement )
		);
		$datesText = Html::element(
			'strong',
			[],
			$this->msg(
				'campaignevents-eventslist-date-separator',
				$this->getLanguage()->userDate( $row->event_start_utc, $this->getUser() ),
				$this->getLanguage()->userDate( $row->event_end_utc, $this->getUser() )
			)->text()
		);
		$detailContainer->appendContent( new HtmlSnippet( Html::rawElement( 'div', [], $datesText ) ) );
		$detailContainer->appendContent(
			new TextWithIconWidget( [
				'icon' => 'mapPin',
				'content' => $this->msg( $this->getMeetingTypeMsg( $row ) )->text(),
				'label' => $this->msg( 'campaignevents-eventslist-meeting-type-label' )->text(),
				'icon_classes' => [ 'ext-campaignevents-events-list-icon' ],
			] )
		);
		if ( $this->eventWikis[$row->event_id] ) {
			$detailContainer->appendContent(
				$this->getWikiList( $row->event_id )
			);
		}
		$eventTopics = $this->eventTopics[(int)$row->event_id];
		if ( $eventTopics ) {
			$detailContainer->appendContent( $this->getTopicList( $eventTopics ) );
		}
		$detailContainer->appendContent(
			new TextWithIconWidget( [
				'icon' => 'userRights',
				'content' => $this->getOrganizersText( $row ),
				'label' => $this->msg( 'campaignevents-eventslist-organizer-label' )->text(),
				'icon_classes' => [ 'ext-campaignevents-events-list-icon' ],
				'classes' => [ 'ext-campaignevents-events-list-organizers' ],
			] )
		);
		return $htmlRow->appendContent( $detailContainer );
	}

	private function getOrganizersText( stdClass $row ): HtmlSnippet {
		$eventID = (int)$row->event_id;
		$organizersToShow = [];
		$creator = $this->creators[$eventID];
		if ( $creator ) {
			$organizersToShow[] = $creator;
		}
		foreach ( $this->extraOrganizers[$eventID] as $organizer ) {
			if ( !$organizer->hasRole( Roles::ROLE_CREATOR ) ) {
				$organizersToShow[] = $organizer;
			}
			if ( count( $organizersToShow ) === 2 ) {
				break;
			}
		}

		$language = $this->getLanguage();
		$organizerLinks = array_map(
			fn ( Organizer $organizer ) => $this->userLinker->generateUserLinkWithFallback(
				$organizer->getUser(),
				$language->getCode()
			),
			$organizersToShow
		);

		$organizerCount = $this->organizerCounts[$eventID];
		if ( $organizerCount > 2 ) {
			$organizerLinks[] = $this->getLinkRenderer()->makeKnownLink(
				SpecialPage::getTitleFor( SpecialEventDetails::PAGE_NAME, (string)$eventID ),
				$this->msg( 'campaignevents-eventslist-organizers-more' )
					->numParams( $organizerCount - 2 )
					->text()
			);
		}

		return new HtmlSnippet( $language->listToText( $organizerLinks ) );
	}

	/**
	 * @inheritDoc
	 */
	public function getIndexField() {
		return [ [ 'event_start_utc', 'event_id' ] ];
	}

	public function getNavigationBar(): string {
		if ( !$this->isNavigationBarShown() ) {
			return '';
		}

		if ( $this->mNavigationBar !== null ) {
			return $this->mNavigationBar;
		}

		$navBuilder = $this->getNavigationBuilder()
			->setPrevMsg( 'prevn' )
			->setNextMsg( 'nextn' )
			->setFirstMsg( 'page_first' )
			->setLastMsg( 'page_last' );

		$this->mNavigationBar = $navBuilder->getHtml();

		return $this->mNavigationBar;
	}

	/**
	 * @param stdClass $row
	 * @return string
	 */
	private function getMeetingTypeMsg( stdClass $row ): string {
		$meetingType = EventStore::getMeetingTypeFromDBVal( $row->event_meeting_type );
		switch ( $meetingType ) {
			case EventRegistration::MEETING_TYPE_IN_PERSON:
				return 'campaignevents-eventslist-location-in-person';
			case EventRegistration::MEETING_TYPE_ONLINE:
				return 'campaignevents-eventslist-location-online';
			case EventRegistration::MEETING_TYPE_ONLINE_AND_IN_PERSON:
				return 'campaignevents-eventslist-location-online-and-in-person';
			default:
				throw new UnexpectedValueException( "Unexpected meeting type $meetingType" );
		}
	}

	/**
	 * @param string $timestamp
	 * @return string
	 */
	private function getYearFromTimestamp( string $timestamp ): string {
		$timestamp = $this->offsetTimestamp( $timestamp );
		return $this->getLanguage()->sprintfDate( 'Y', $timestamp );
	}

	/**
	 * @param string $timestamp
	 * @return string
	 */
	private function getMonthHeader( string $timestamp ): string {
		$timestamp = $this->offsetTimestamp( $timestamp );
		// TODO This is not guaranteed to return the month name in a format suitable for section headings (e.g.,
		// it may need to be capitalized).
		return $this->getLanguage()->sprintfDate( 'F Y', $timestamp );
	}

	/**
	 * @param string $timestamp
	 * @return string
	 */
	private function getDayFromTimestamp( string $timestamp ): string {
		$timestamp = $this->offsetTimestamp( $timestamp );
		return $this->getLanguage()->sprintfDate( 'j', $timestamp );
	}

	/**
	 * @param string $timestamp
	 * @return string
	 */
	private function offsetTimestamp( string $timestamp ): string {
		$offset = $this->userOptionsLookup
			->getOption( $this->getUser(), 'timecorrection' );

		return $this->getLanguage()->userAdjust( $timestamp, $offset );
	}

	/**
	 * @return array
	 */
	public function getSubqueryInfo(): array {
		$query = $this->getDefaultSubqueryInfo();
		if ( $this->meetingType !== null ) {
			$query['conds']['event_meeting_type'] = EventStore::meetingTypeToDBVal( $this->meetingType );
		}
		$query['conds']['event_is_test_event'] = false;
		if ( $this->filterWiki && $this->getConfig()->get( 'CampaignEventsEnableEventWikis' ) ) {
			$query['tables'][] = 'ce_event_wikis';
			array_push( $query['fields'], 'ceew_wiki', 'ceew_event_id' );
			$query['join_conds']['ce_event_wikis'] = [
				'JOIN',
				[
					'event_id=ceew_event_id',
					'ceew_wiki' => [ ...$this->filterWiki, EventWikisStore::ALL_WIKIS_DB_VALUE ]
				]
			];
		}
		if ( $this->filterTopics && $this->getConfig()->get( 'CampaignEventsEnableEventTopics' ) ) {
			$query['tables'][] = 'ce_event_topics';
			array_push( $query['fields'], 'ceet_topic', 'ceet_event_id' );
			$query['join_conds']['ce_event_topics'] = [
				'JOIN',
				[
					'event_id=ceet_event_id',
					'ceet_topic' => $this->filterTopics,
				]
			];
		}
		return $query;
	}

	/**
	 * @param int|null|string $offset
	 * @param int $limit
	 * @param bool $order
	 * @return array
	 */
	public function buildQueryInfo( $offset, $limit, $order ): array {
		[ $tables, $fields, $conds, $fname, $options, $join_conds ] = parent::buildQueryInfo( $offset, $limit, $order );
		// this is required to set the offsets correctly
		$offsets = $this->getDateRangeCond( $this->startDate, $this->endDate );
		if ( $offsets ) {
			[ $startOffset, $endOffset ] = $offsets;

			if ( $this->showOngoing ) {
				if ( $startOffset ) {
					$conds[] = $this->mDb->expr( 'event_end_utc', '>=', $startOffset );
				}
				if ( $endOffset ) {
					$conds[] = $this->mDb->expr( 'event_start_utc', '<=', $endOffset );
				}
			} else {
				if ( $startOffset ) {
					$conds[] = $this->mDb->expr( 'event_start_utc', '>=', $startOffset );
				}
				if ( $endOffset ) {
					$conds[] = $this->mDb->expr( 'event_start_utc', '<=', $endOffset );
				}
			}
		}
		return [ $tables, $fields, $conds, $fname, $options, $join_conds ];
	}

	/**
	 * @param string $startDate
	 * @param string $endDate
	 * @return array|null
	 */
	private function getDateRangeCond( string $startDate, string $endDate ): ?array {
		try {
			$startOffset = null;
			if ( $startDate !== '' ) {
				$startTimestamp = MWTimestamp::getInstance( $startDate );
				$startOffset = $this->mDb->timestamp( $startTimestamp->getTimestamp() );
			}

			if ( $endDate !== '' ) {
				$endTimestamp = MWTimestamp::getInstance( $endDate );
				// Turned to use '<' for consistency with the parent class,
				// add one second for compatibility with existing use cases
				$endTimestamp->timestamp = $endTimestamp->timestamp->modify( '+1 second' );
				$this->endOffset = $this->mDb->timestamp( $endTimestamp->getTimestamp() );

				// populate existing variables for compatibility with parent
				$this->mYear = (int)$endTimestamp->format( 'Y' );
				$this->mMonth = (int)$endTimestamp->format( 'm' );
				$this->mDay = (int)$endTimestamp->format( 'd' );
			}

			return [ $startOffset, $this->endOffset ];
		} catch ( TimestampException $ex ) {
			return null;
		}
	}

	private function getWikiList( string $eventID ): TextWithIconWidget {
		$eventWikis = $this->eventWikis[(int)$eventID];

		if ( $eventWikis === EventRegistration::ALL_WIKIS ) {
			$wikiName = [ $this->msg( 'campaignevents-eventslist-all-wikis' )->text() ];
			return $this->getWikiListWidget( $eventID, $wikiName );
		}
		$currentWikiId = WikiMap::getCurrentWikiId();
		$curWikiKey = array_search( $currentWikiId, $eventWikis, true );
		if ( $curWikiKey !== false ) {
			unset( $eventWikis[$curWikiKey] );
			array_unshift( $eventWikis, $currentWikiId );
		}
		return $this->getWikiListWidget( $eventID, $eventWikis );
	}

	/**
	 * @param string $eventID
	 * @param string[] $eventWikis
	 * @return TextWithIconWidget
	 * @throws Exception
	 */
	public function getWikiListWidget( string $eventID, array $eventWikis ): TextWithIconWidget {
		$language = $this->getLanguage();
		$displayedWikiNames = $this->wikiLookup->getLocalizedNames(
			array_slice( $eventWikis, 0, self::DISPLAYED_WIKI_COUNT )
		);
		$wikiCount = count( $eventWikis );
		$escapedWikiNames = [];
		foreach ( $displayedWikiNames as $name ) {
			$escapedWikiNames[] = htmlspecialchars( $name );
		}

		if ( $wikiCount > self::DISPLAYED_WIKI_COUNT ) {
			$escapedWikiNames[] = $this->getLinkRenderer()->makeKnownLink(
				SpecialPage::getTitleFor( SpecialEventDetails::PAGE_NAME, $eventID ),
				$this->msg( 'campaignevents-eventslist-wikis-more' )
					->numParams( $wikiCount - self::DISPLAYED_WIKI_COUNT )
					->text()
			);
		}
		return new TextWithIconWidget( [
			'icon' => $this->wikiLookup->getWikiIcon( $eventWikis ),
			'content' => new HtmlSnippet( $language->listToText( $escapedWikiNames ) ),
			'label' => $this->msg( 'campaignevents-eventslist-wiki-label' )->text(),
			'icon_classes' => [ 'ext-campaignevents-events-list-icon' ],
		] );
	}

	private function getTopicList( array $topics ): TextWithIconWidget {
		$localizedTopicNames = array_map(
			fn ( string $msgKey ) => $this->msg( $msgKey )->escaped(),
			$this->topicRegistry->getTopicMessages( $topics )
		);
		sort( $localizedTopicNames );

		return new TextWithIconWidget( [
			'icon' => 'tag',
			'content' => new HtmlSnippet( $this->getLanguage()->commaList( $localizedTopicNames ) ),
			'label' => $this->msg( 'campaignevents-eventslist-topics-label' )->text(),
			'icon_classes' => [ 'ext-campaignevents-events-list-icon' ],
		] );
	}
}
