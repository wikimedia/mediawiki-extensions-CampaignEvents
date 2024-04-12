<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Pager;

use IContextSource;
use LogicException;
use MediaWiki\Cache\LinkBatchFactory;
use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventStore;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFactory;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageURLResolver;
use MediaWiki\Extension\CampaignEvents\Special\SpecialEditEventRegistration;
use MediaWiki\Extension\CampaignEvents\Special\SpecialEventDetails;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Pager\TablePager;
use MediaWiki\SpecialPage\SpecialPage;
use OOUI\ButtonWidget;

class EventsTablePager extends TablePager {
	use EventPagerTrait {
		EventPagerTrait::getSubqueryInfo as getDefaultSubqueryInfo;
	}

	public const STATUS_ANY = 'any';
	public const STATUS_OPEN = 'open';
	public const STATUS_CLOSED = 'closed';

	private const SORT_INDEXES = [
		'event_start_utc' => [ 'event_start_utc', 'event_name', 'event_id' ],
		'event_name' => [ 'event_name', 'event_start_utc', 'event_id' ],
		'num_participants' => [ 'num_participants', 'event_start_utc', 'event_id' ],
	];

	private CampaignsPageFactory $campaignsPageFactory;
	private PageURLResolver $pageURLResolver;
	private LinkBatchFactory $linkBatchFactory;

	private CentralUser $centralUser;

	private string $search;
	private string $status;

	/**
	 * @param IContextSource $context
	 * @param LinkRenderer $linkRenderer
	 * @param CampaignsDatabaseHelper $databaseHelper
	 * @param CampaignsPageFactory $campaignsPageFactory
	 * @param PageURLResolver $pageURLResolver
	 * @param LinkBatchFactory $linkBatchFactory
	 * @param string $search
	 * @param string $status One of the self::STATUS_* constants
	 * @param CentralUser $user
	 */
	public function __construct(
		IContextSource $context,
		LinkRenderer $linkRenderer,
		CampaignsDatabaseHelper $databaseHelper,
		CampaignsPageFactory $campaignsPageFactory,
		PageURLResolver $pageURLResolver,
		LinkBatchFactory $linkBatchFactory,
		string $search,
		string $status,
		CentralUser $user
	) {
		// Set the database before calling the parent constructor, otherwise it'll use the local one.
		$this->mDb = $databaseHelper->getDBConnection( DB_REPLICA );
		parent::__construct( $context, $linkRenderer );
		$this->campaignsPageFactory = $campaignsPageFactory;
		$this->pageURLResolver = $pageURLResolver;
		$this->linkBatchFactory = $linkBatchFactory;
		$this->centralUser = $user;
		$this->search = $search;
		$this->status = $status;
	}

	/**
	 * @inheritDoc
	 */
	public function formatValue( $name, $value ): string {
		switch ( $name ) {
			case 'event_start_utc':
				return htmlspecialchars( $this->getLanguage()->userDate( $value, $this->getUser() ) );
			case 'event_name':
				return $this->getLinkRenderer()->makeKnownLink(
					SpecialPage::getTitleFor( SpecialEventDetails::PAGE_NAME, $this->mCurrentRow->event_id ),
					$value,
					[ 'class' => 'ext-campaignevents-eventspager-eventpage-link' ]
				);
			case 'event_location':
				$meetingType = EventStore::getMeetingTypeFromDBVal( $this->mCurrentRow->event_meeting_type );
				if ( $meetingType === EventRegistration::MEETING_TYPE_ONLINE ) {
					$msgKey = 'campaignevents-eventslist-location-online';
				} elseif ( $meetingType === EventRegistration::MEETING_TYPE_IN_PERSON ) {
					$msgKey = 'campaignevents-eventslist-location-in-person';
				} elseif ( $meetingType === EventRegistration::MEETING_TYPE_ONLINE_AND_IN_PERSON ) {
					$msgKey = 'campaignevents-eventslist-location-online-and-in-person';
				} else {
					throw new LogicException( "Unexpected meeting type: $meetingType" );
				}
				return $this->msg( $msgKey )->escaped();
			case 'num_participants':
				return htmlspecialchars( $this->getLanguage()->formatNum( $value ) );
			case 'manage_event':
				$eventID = $this->mCurrentRow->event_id;
				$btnLabel = $this->msg( 'campaignevents-eventslist-manage-btn-info' )->text();
				// This will be replaced with a ButtonMenuSelectWidget in JS.
				$btn = new ButtonWidget( [
					'framed' => false,
					'label' => $btnLabel,
					'title' => $btnLabel,
					'invisibleLabel' => true,
					'icon' => 'ellipsis',
					'href' => SpecialPage::getTitleFor(
						SpecialEditEventRegistration::PAGE_NAME,
						$eventID
					)->getLocalURL(),
					'classes' => [ 'ext-campaignevents-eventspager-manage-btn' ],
				] );
				$eventStatus = EventStore::getEventStatusFromDBVal( $this->mCurrentRow->event_status );
				$eventPage = $this->getEventPageFromRow( $this->mCurrentRow );
				$btn->setAttributes( [
					'data-mw-event-id' => $eventID,
					'data-mw-event-name' => $this->mCurrentRow->event_name,
					'data-mw-is-closed' => $eventStatus === EventRegistration::STATUS_CLOSED ? 1 : 0,
					'data-mw-event-page-url' => $this->pageURLResolver->getUrl( $eventPage ),
					'data-mw-label' => $btnLabel,
					'data-mw-is-local-wiki' => $eventPage->getWikiId() === WikiAwareEntity::LOCAL,
				] );
				return $btn->toString();
			default:
				throw new LogicException( "Unexpected name $name" );
		}
	}

	/**
	 * @inheritDoc
	 */
	protected function getFieldNames(): array {
		return [
			'event_start_utc' => $this->msg( 'campaignevents-eventslist-column-date' )->text(),
			'event_name' => $this->msg( 'campaignevents-eventslist-column-name' )->text(),
			'event_location' => $this->msg( 'campaignevents-eventslist-column-location' )->text(),
			'num_participants' => $this->msg( 'campaignevents-eventslist-column-participants-number' )->text(),
			'manage_event' => ''
		];
	}

	/**
	 * Overridden to provide additional columns to order by, since most columns are not unique.
	 * @inheritDoc
	 */
	public function getIndexField(): array {
		// XXX Work around T308697: TablePager and IndexPager seem to be incompatible and the correct
		// index is not chosen automatically.
		return [ self::SORT_INDEXES[$this->mSort] ];
	}

	/**
	 * @inheritDoc
	 */
	public function getDefaultSort(): string {
		return 'event_start_utc';
	}

	/**
	 * @inheritDoc
	 */
	protected function isFieldSortable( $field ): bool {
		return array_key_exists( $field, self::SORT_INDEXES );
	}

	/**
	 * @inheritDoc
	 */
	protected function getTableClass() {
		return parent::getTableClass() . ' ext-campaignevents-eventspager-table';
	}

	/**
	 * @inheritDoc
	 */
	protected function getCellAttrs( $field, $value ) {
		$ret = parent::getCellAttrs( $field, $value );
		$addClass = null;
		if ( $field === 'manage_event' ) {
			$addClass = 'ext-campaignevents-eventspager-cell-manage';
		}
		if ( $addClass ) {
			$ret['class'] = isset( $ret['class'] ) ? $ret['class'] . " $addClass" : $addClass;
		}
		return $ret;
	}

	/**
	 * @inheritDoc
	 */
	public function getModuleStyles() {
		return array_merge(
			parent::getModuleStyles(),
			[
				// Avoid creating a new module for the pager only.
				'ext.campaignEvents.specialeventslist.styles',
				'oojs-ui.styles.icons-interactions'
			]
		);
	}

	/**
	 * @return string[] An array of (non-style) RL modules.
	 */
	public function getModules(): array {
		// Avoid creating a new module for the pager only.
		return [ 'ext.campaignEvents.specialeventslist' ];
	}

	/**
	 * @inheritDoc
	 */
	public function getSubqueryInfo(): array {
		$query = $this->getDefaultSubqueryInfo();
		switch ( $this->status ) {
			case self::STATUS_ANY:
				break;
			case self::STATUS_OPEN:
				$query['conds']['event_status'] = EventStore::getEventStatusDBVal( EventRegistration::STATUS_OPEN );
				break;
			case self::STATUS_CLOSED:
				$query['conds']['event_status'] = EventStore::getEventStatusDBVal( EventRegistration::STATUS_CLOSED );
				break;
			default:
				// Invalid statuses can only be entered by messing with the HTML or query params, ignore.
		}
		// This should be abstracted at a later date.
		// the current implementation ties presentation and data retrieval too closely
		$query['join_conds']['ce_organizers'][1]['ceo_user_id'] = $this->centralUser->getCentralID();
		return $query;
	}
}
