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
use MediaWiki\Extension\CampaignEvents\Organizers\Organizer;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Organizers\Roles;
use MediaWiki\Extension\CampaignEvents\Special\SpecialEventDetails;
use MediaWiki\Extension\CampaignEvents\Widget\TextWithIconWidget;
use MediaWiki\Pager\IndexPager;
use MediaWiki\Pager\ReverseChronologicalPager;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\UserOptionsLookup;
use MediaWiki\Utils\MWTimestamp;
use OOUI\HtmlSnippet;
use OOUI\Tag;
use stdClass;
use UnexpectedValueException;
use Wikimedia\Rdbms\OrExpressionGroup;
use Wikimedia\Timestamp\TimestampException;

class EventsListPager extends ReverseChronologicalPager {
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
	private string $startDate;
	private string $endDate;

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
		$this->startDate = $startDate;
		$this->endDate = $endDate;
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
		$detailContainer->appendContent(
			new TextWithIconWidget( [
				'icon' => 'clock',
				'content' => $this->msg(
					'campaignevents-eventslist-date-separator',
					$this->getLanguage()->userDate( $row->event_start_utc, $this->getUser() ),
					$this->getLanguage()->userDate( $row->event_end_utc, $this->getUser() )
				)->text(),
				'label' => $this->msg( 'campaignevents-eventslist-date-label' )->text(),
				'icon_classes' => [ 'ext-campaignevents-eventslist-pager-icon' ],
			] )
		);
		$detailContainer->appendContent(
			new TextWithIconWidget( [
				'icon' => 'mapPin',
				'content' => $this->msg( $this->getMeetingTypeMsg( $row ) )->text(),
				'label' => $this->msg( 'campaignevents-eventslist-meeting-type-label' )->text(),
				'icon_classes' => [ 'ext-campaignevents-eventslist-pager-icon' ],
			] )
		);
		$detailContainer->appendContent(
			new TextWithIconWidget( [
				'icon' => 'userRights',
				'content' => $this->getOrganizersText( $row ),
				'label' => $this->msg( 'campaignevents-eventslist-organizer-label' )->text(),
				'icon_classes' => [ 'ext-campaignevents-eventslist-pager-icon' ],
				'classes' => [ 'ext-campaignevents-eventslist-pager-organizers' ],
			] )
		);
		return $htmlRow->appendContent( $detailContainer );
	}

	private function getOrganizersText( stdClass $row ): HtmlSnippet {
		$eventID = (int)$row->event_id;
		$organizersToShow = [];
		$creator = $this->organizerStore->getEventCreator( $eventID, OrganizersStore::GET_CREATOR_EXCLUDE_DELETED );
		if ( $creator ) {
			$organizersToShow[] = $creator;
		}
		$organizers = $this->organizerStore->getEventOrganizers( $eventID, 2 );
		foreach ( $organizers as $organizer ) {
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

		$organizerCount = $this->organizerStore->getOrganizerCountForEvent( $eventID );
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
			if ( $startOffset ) {
				$crossStartCondition = $this->mDb->expr( $this->getTimestampField(), '<=', $startOffset )
					->and( 'event_end_utc', '>=', $startOffset );

				$withinDatesCondition = $this->mDb->expr( $this->getTimestampField(), '>=', $startOffset );
				if ( $endOffset ) {
					$withinDatesCondition = $withinDatesCondition->and( 'event_end_utc', '<=', $endOffset );
				}
				$conds[] = new OrExpressionGroup( $crossStartCondition, $withinDatesCondition );
			} elseif ( $endOffset ) {
				$conds[] = $this->mDb->expr( $this->getTimestampField(), '<=', $endOffset );
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
}
