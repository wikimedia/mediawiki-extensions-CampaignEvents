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
use MediaWiki\User\UserOptionsLookup;
use OOUI\HtmlSnippet;
use OOUI\Tag;
use stdClass;

class EventsListPager extends RangeChronologicalPager {
	use EventPagerTrait {
		EventPagerTrait::getSubqueryInfo as getDefaultSubqueryInfo;
	}

	private UserLinker $userLinker;
	private CampaignsPageFactory $campaignsPageFactory;
	private PageURLResolver $pageURLResolver;
	private OrganizersStore $organizerStore;
	private string $lastHeaderMonth;
	private string $search;
	private ?string $meetingType;
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
	 * @param string|null $meetingType
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
		?string $meetingType,
		string $startDate,
		string $endDate
	) {
		// Set the database before calling the parent constructor, otherwise it'll use the local one.

		$this->mDb = $databaseHelper->getDBConnection( DB_REPLICA );
		$this->options = $options;
		$this->userLinker = $userLinker;
		$this->campaignsPageFactory = $pageFactory;
		$this->pageURLResolver = $pageURLResolver;
		$this->organizerStore = $organizerStore;
		$this->linkBatchFactory = $linkBatchFactory;
		parent::__construct( $this->getContext(), $this->getLinkRenderer() );
		$this->getDateRangeCond( $startDate, $endDate );
		$this->mDefaultDirection = IndexPager::DIR_ASCENDING;
		$this->lastHeaderMonth = '';
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
		$Month = $this->getMonthFromTimestamp( $timestamp );
		$closeList = $this->lastHeaderMonth && $Month !== $this->lastHeaderMonth;
		if ( $closeList ) {
			$s .= $this->getEndGroup();
		}
		if ( $Month && $this->isHeaderRowNeeded( $Month ) ) {
			$s .= $this->getHeaderRow( $Month );
			$this->lastHeaderMonth = $Month;
		}
		$s .= $this->formatRow( $row );

		return $s;
	}

	/**
	 * Get a list of items to show in a "<select>" element of limits.
	 * This can be passed directly to XmlSelect::addOptions().
	 *
	 * @since 1.22
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

	protected function isHeaderRowNeeded( string $month ): bool {
		return $this->lastHeaderMonth !== $month;
	}

	/**
	 * @inheritDoc
	 */
	public function formatRow( $row ) {
		$htmlRow = ( new Tag( 'li' ) )
			->setAttributes( [ "class" => 'ext-campaignevents-events-list-pager-row' ] );
		$page = $this->getEventPageFromRow( $row );
		$pageUrlResolver = $this->pageURLResolver;
		$timestampField = $this->getTimestampField();
		$timestamp = $row->$timestampField;
		$htmlRow->appendContent( ( new Tag() )
			->setAttributes( [ "class" => 'ext-campaignevents-events-list-pager-day' ] )
			->appendContent( $this->getDayFromTimestamp( $timestamp ) ) );
		$detailContainer = ( new Tag() )
			->setAttributes( [ "class" => 'ext-campaignevents-events-list-pager-details' ] );
		$eventPageLinkElement = ( new Tag( 'a' ) )
			->setAttributes( [
				"href" => $pageUrlResolver->getUrl( $page ),
				"class" => 'ext-campaignevents-events-list-pager-link'
			] )
			->appendContent( $row->event_name );
		$detailContainer->appendContent(
			( new Tag( 'h4' ) )->appendContent( $eventPageLinkElement )
		);
		$meetingType = $this->msg( $this->getMeetingType( $row ) );
		$detailContainer->appendContent(
			new TextWithIconWidget( [
				'icon' => 'clock',
				'content' => ( new Tag( 'p' ) )->appendContent(
					$this->msg( 'campaignevents-allevents-date-separator',
						$this->getLanguage()->userDate( $row->event_start_utc, $this->getUser() ),
						$this->getLanguage()->userDate( $row->event_end_utc, $this->getUser() ) )
						->parse()

				),
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
				$userLinker->generateUserLinkWithFallback( $organizer->getUser(), $this->getLanguage()->getCode()
				)
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
	private function getMeetingType( $row ): string {
		$meetingtype = EventStore::getMeetingTypeFromDBVal( $row->event_meeting_type );
		if (
			$meetingtype === EventStore::getMeetingTypeFromDBVal( (string)EventRegistration::MEETING_TYPE_IN_PERSON )
		) {
			return 'campaignevents-eventslist-location-in-person';
		}
		if ( $meetingtype === EventStore::getMeetingTypeFromDBVal( (string)EventRegistration::MEETING_TYPE_ONLINE ) ) {
			return 'campaignevents-eventslist-location-online';
		}
		if (
			$meetingtype
			=== EventStore::getMeetingTypeFromDBVal( (string)EventRegistration::MEETING_TYPE_ONLINE_AND_IN_PERSON ) ) {
			return 'campaignevents-eventslist-location-online-and-in-person';
		}
		return '';
	}

	/**
	 * @param string $timestamp
	 * @return string
	 */
	private function getMonthFromTimestamp( string $timestamp ): string {
		$timestamp = $this->offsetTimestamp( $timestamp );
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
	public function offsetTimestamp( string $timestamp ): string {
		$offset = $this->options
			->getOption( $this->getUser(), 'timecorrection' );

		return $this->getLanguage()->userAdjust( $timestamp, $offset );
	}

	/**
	 * @return array
	 */
	public function getSubqueryInfo(): array {
		$query = $this->getDefaultSubqueryInfo();
		if ( $this->meetingType !== '' ) {
			$query['conds']['event_meeting_type'] = $this->meetingType;
		}
		return $query;
	}
}
