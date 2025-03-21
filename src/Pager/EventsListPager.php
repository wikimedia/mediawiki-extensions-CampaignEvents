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
use OOUI\HtmlSnippet;
use stdClass;
use UnexpectedValueException;
use Wikimedia\Assert\Assert;
use Wikimedia\Rdbms\IResultWrapper;

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

	/** @var bool Reverse parent ordering, so events are ordered from oldest to newest. */
	public $mDefaultDirection = IndexPager::DIR_ASCENDING;

	private string $search;
	/** One of the EventRegistration::MEETING_TYPE_* constants */
	private ?int $meetingType;
	/** dbnames of the wikis chosen */
	private array $filterWiki;
	/** @var string[] */
	private array $filterTopics;
	protected ?string $startDate;
	protected ?string $endDate;

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

	/**
	 * @note Callers are responsible for verifying that $startDate and $endDate are valid timestamps (or null).
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
		string $search,
		?int $meetingType,
		?string $startDate,
		?string $endDate,
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
		Assert::parameter(
			$startDate === null || ( $startDate !== '' && MWTimestamp::convert( TS_UNIX, $startDate ) !== false ),
			'$startDate',
			'Must be a valid timestamp or null'
		);
		$this->startDate = $startDate;
		Assert::parameter(
			$endDate === null || ( $endDate !== '' && MWTimestamp::convert( TS_UNIX, $endDate ) !== false ),
			'$endDate',
			'Must be a valid timestamp or null'
		);
		$this->endDate = $endDate;

		$this->getDateRangeCond( $startDate, $endDate );
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
		$rowContent = '';

		$timestampField = $this->getTimestampField();
		$timestamp = $row->$timestampField;
		$rowContent .= Html::element(
			'div',
			[ 'class' => 'ext-campaignevents-events-list-day' ],
			$this->getDayFromTimestamp( $timestamp )
		);

		$detailsContent = '';

		$page = $this->getEventPageFromRow( $row );
		$eventPageLinkElement = Html::element(
			'a',
			[
				"href" => $this->pageURLResolver->getUrl( $page ),
				"class" => 'ext-campaignevents-events-list-link'
			],
			$row->event_name
		);
		$detailsContent .= Html::rawElement( 'h4', [], $eventPageLinkElement );

		$datesText = Html::element(
			'strong',
			[],
			$this->msg(
				'campaignevents-eventslist-date-separator',
				$this->getLanguage()->userDate( $row->event_start_utc, $this->getUser() ),
				$this->getLanguage()->userDate( $row->event_end_utc, $this->getUser() )
			)->text()
		);
		$detailsContent .= Html::rawElement( 'div', [], $datesText );

		$detailsContent .= TextWithIconWidget::build( [
			'icon' => 'mapPin',
			'content' => $this->msg( $this->getMeetingTypeMsg( $row ) )->text(),
			'label' => $this->msg( 'campaignevents-eventslist-meeting-type-label' )->text(),
			'icon_classes' => [ 'ext-campaignevents-events-list-icon' ],
		] );

		if ( $this->eventWikis[$row->event_id] ) {
			$detailsContent .= $this->getWikiList( $row->event_id );
		}

		$eventTopics = $this->eventTopics[(int)$row->event_id];
		if ( $eventTopics ) {
			$detailsContent .= $this->getTopicList( $eventTopics );
		}

		$detailsContent .= TextWithIconWidget::build( [
			'icon' => 'userRights',
			'content' => $this->getOrganizersText( $row ),
			'label' => $this->msg( 'campaignevents-eventslist-organizer-label' )->text(),
			'icon_classes' => [ 'ext-campaignevents-events-list-icon' ],
			'classes' => [ 'ext-campaignevents-events-list-organizers' ],
		] );

		$rowContent .= Html::rawElement(
			'div',
			[ 'class' => 'ext-campaignevents-events-list-details' ],
			$detailsContent
		);

		return Html::rawElement(
			'li',
			[ 'class' => 'ext-campaignevents-events-list-row' ],
			$rowContent
		);
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

	private function getMonthHeader( string $timestamp ): string {
		$timestamp = $this->offsetTimestamp( $timestamp );
		// TODO This is not guaranteed to return the month name in a format suitable for section headings (e.g.,
		// it may need to be capitalized).
		return $this->getLanguage()->sprintfDate( 'F Y', $timestamp );
	}

	private function getDayFromTimestamp( string $timestamp ): string {
		$timestamp = $this->offsetTimestamp( $timestamp );
		return $this->getLanguage()->sprintfDate( 'j', $timestamp );
	}

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
		if ( $this->filterWiki ) {
			$query['tables'][] = 'ce_event_wikis';
			$query['join_conds']['ce_event_wikis'] = [
				'JOIN',
				[
					'event_id=ceew_event_id',
					'ceew_wiki' => [ ...$this->filterWiki, EventWikisStore::ALL_WIKIS_DB_VALUE ]
				]
			];
		}
		if ( $this->filterTopics ) {
			$query['tables'][] = 'ce_event_topics';
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
		[ $startOffset, $endOffset ] = $this->getDateRangeCond( $this->startDate, $this->endDate );
		if ( $startOffset ) {
			$conds[] = $this->mDb->expr( 'event_start_utc', '>=', $startOffset );
		}
		if ( $endOffset ) {
			$conds[] = $this->mDb->expr( 'event_start_utc', '<=', $endOffset );
		}
		return [ $tables, $fields, $conds, $fname, $options, $join_conds ];
	}

	/**
	 * @param string|null $startDate
	 * @param string|null $endDate
	 * @return array<string|null>
	 */
	protected function getDateRangeCond( ?string $startDate, ?string $endDate ): array {
		$startOffset = null;
		if ( $startDate !== null ) {
			$startTimestamp = MWTimestamp::getInstance( $startDate );
			$startOffset = $this->mDb->timestamp( $startTimestamp->getTimestamp() );
		}

		if ( $endDate !== null ) {
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
	}

	private function getWikiList( string $eventID ): string {
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
	 * @return string
	 */
	public function getWikiListWidget( string $eventID, array $eventWikis ): string {
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
		return TextWithIconWidget::build( [
			'icon' => $this->wikiLookup->getWikiIcon( $eventWikis ),
			'content' => new HtmlSnippet( $language->listToText( $escapedWikiNames ) ),
			'label' => $this->msg( 'campaignevents-eventslist-wiki-label' )->text(),
			'icon_classes' => [ 'ext-campaignevents-events-list-icon' ],
		] );
	}

	private function getTopicList( array $topics ): string {
		$localizedTopicNames = array_map(
			fn ( string $msgKey ) => $this->msg( $msgKey )->escaped(),
			$this->topicRegistry->getTopicMessages( $topics )
		);
		sort( $localizedTopicNames );

		return TextWithIconWidget::build( [
			'icon' => 'tag',
			'content' => new HtmlSnippet( $this->getLanguage()->commaList( $localizedTopicNames ) ),
			'label' => $this->msg( 'campaignevents-eventslist-topics-label' )->text(),
			'icon_classes' => [ 'ext-campaignevents-events-list-icon' ],
		] );
	}
}
