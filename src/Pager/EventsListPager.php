<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Pager;

use MediaWiki\Cache\LinkBatchFactory;
use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventStore;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFactory;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageURLResolver;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserLinker;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Widget\TextWithIconWidget;
use MediaWiki\Pager\IndexPager;
use MediaWiki\Pager\RangeChronologicalPager;
use MediaWiki\User\Options\UserOptionsLookup;
use OOUI\HtmlSnippet;
use OOUI\Tag;
use stdClass;
use UnexpectedValueException;

class EventsListPager extends RangeChronologicalPager {
	use EventPagerTrait {
		EventPagerTrait::getSubqueryInfo as getDefaultSubqueryInfo;
	}

	private UserLinker $userLinker;
	private CampaignsPageFactory $campaignsPageFactory;
	private PageURLResolver $pageURLResolver;
	private OrganizersStore $organizerStore;
	private string $lastHeaderTimestamp;
	private string $search;
	/** One of the EventRegistration::MEETING_TYPE_* constants */
	private ?int $meetingType;
	private LinkBatchFactory $linkBatchFactory;
	private UserOptionsLookup $options;

	/**
	 * @param UserLinker $userLinker
	 * @param CampaignsPageFactory $pageFactory
	 * @param PageURLResolver $pageURLResolver
	 * @param OrganizersStore $organizerStore
	 * @param LinkBatchFactory $linkBatchFactory
	 * @param UserOptionsLookup $options
	 * @param CampaignsDatabaseHelper $databaseHelper
	 * @param string $search
	 * @param int|null $meetingType
	 * @param string $startDate
	 * @param string $endDate
	 */
	public function __construct(
		UserLinker $userLinker,
		CampaignsPageFactory $pageFactory,
		PageURLResolver $pageURLResolver,
		OrganizersStore $organizerStore,
		LinkBatchFactory $linkBatchFactory,
		UserOptionsLookup $options,
		CampaignsDatabaseHelper $databaseHelper,
		string $search,
		?int $meetingType,
		string $startDate,
		string $endDate
	) {
		// Set the database before calling the parent constructor, otherwise it'll use the local one.
		$this->mDb = $databaseHelper->getDBConnection( DB_REPLICA );
		parent::__construct( $this->getContext(), $this->getLinkRenderer() );

		$this->options = $options;
		$this->userLinker = $userLinker;
		$this->campaignsPageFactory = $pageFactory;
		$this->pageURLResolver = $pageURLResolver;
		$this->organizerStore = $organizerStore;
		$this->linkBatchFactory = $linkBatchFactory;

		$this->getDateRangeCond( $startDate, $endDate );
		$this->mDefaultDirection = IndexPager::DIR_ASCENDING;
		$this->lastHeaderTimestamp = '';
		$this->search = $search;
		$this->meetingType = $meetingType;
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
			$s .= $this->getHeaderRow( $this->getMonthFromTimestamp( $timestamp ) );
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
		$month = $this->getMonthFromTimestamp( $date );
		$prevMonth = $this->getMonthFromTimestamp( $this->lastHeaderTimestamp );
		$year = $this->getYearFromTimestamp( $date );
		$prevYear = $this->getYearFromTimestamp( $this->lastHeaderTimestamp );
		return $month !== $prevMonth || $year !== $prevYear;
	}

	/**
	 * @inheritDoc
	 */
	public function formatRow( $row ) {
		$htmlRow = ( new Tag( 'li' ) )
			->addClasses( [ 'ext-campaignevents-events-list-pager-row' ] );
		$page = $this->getEventPageFromRow( $row );
		$pageUrlResolver = $this->pageURLResolver;
		$timestampField = $this->getTimestampField();
		$timestamp = $row->$timestampField;
		$htmlRow->appendContent( ( new Tag() )
			->addClasses( [ 'ext-campaignevents-events-list-pager-day' ] )
			->appendContent( $this->getDayFromTimestamp( $timestamp ) ) );
		$detailContainer = ( new Tag() )
			->addClasses( [ 'ext-campaignevents-events-list-pager-details' ] );
		$eventPageLinkElement = ( new Tag( 'a' ) )
			->setAttributes( [
				"href" => $pageUrlResolver->getUrl( $page ),
				"class" => 'ext-campaignevents-events-list-pager-link'
			] )
			->appendContent( $row->event_name );
		$detailContainer->appendContent(
			( new Tag( 'h4' ) )->appendContent( $eventPageLinkElement )
		);
		$meetingType = $this->msg( $this->getMeetingTypeMsg( $row ) )->text();
		$detailContainer->appendContent(
			new TextWithIconWidget( [
				'icon' => 'clock',
				'content' => $this->msg(
					'campaignevents-allevents-date-separator',
					$this->getLanguage()->userDate( $row->event_start_utc, $this->getUser() ),
					$this->getLanguage()->userDate( $row->event_end_utc, $this->getUser() )
				)->text(),
				'label' => 'campaignevents-allevents-date-label',
				'icon_classes' => [ 'ext-campaignevents-eventslist-pager-icon' ],
			] )
		);
		$eventType = new TextWithIconWidget( [
			'icon' => 'mapPin',
			'content' => $meetingType,
			'label' => 'campaignevents-allevents-meeting-type-label',
			'icon_classes' => [ 'ext-campaignevents-eventslist-pager-icon' ],
		] );
		$userLinker = $this->userLinker;
		$organizerStore = $this->organizerStore;
		$eventID = (int)$row->event_id;
		$organizer = $organizerStore
			->getEventCreator( $eventID, OrganizersStore::GET_CREATOR_EXCLUDE_DELETED );
		$organizer ??= $organizerStore->getEventOrganizers( $eventID, 1 )[0];
		$userLinkElement = new TextWithIconWidget( [
			'icon' => 'userRights',
			'content' => new HtmlSnippet(
				$userLinker->generateUserLinkWithFallback( $organizer->getUser(), $this->getLanguage()->getCode() )
			),
			'label' => 'campaignevents-allevents-organiser-label',
			'icon_classes' => [ 'ext-campaignevents-eventslist-pager-icon' ],
		] );
		$detailContainer->appendContent(
			( new Tag() )->addClasses(
				[ 'ext-campaignevents-eventslist-pager-bottom' ]
			)->appendContent( $eventType, $userLinkElement )
		);
		return $htmlRow->appendContent( $detailContainer );
	}

	/**
	 * @inheritDoc
	 */
	public function getIndexField() {
		return [ [ 'event_start_utc', 'event_name', 'event_id' ] ];
	}

	public function getNavigationBar(): string {
		if ( !$this->isNavigationBarShown() ) {
			return '';
		}

		if ( isset( $this->mNavigationBar ) ) {
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
	private function getMonthFromTimestamp( string $timestamp ): string {
		$timestamp = $this->offsetTimestamp( $timestamp );
		// TODO This is not guaranteed to return the month name in a format suitable for section headings (e.g.,
		// it may need to be capitalized).
		return $this->getLanguage()->sprintfDate( 'F', $timestamp );
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
		$offset = $this->options
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
		return $query;
	}
}
