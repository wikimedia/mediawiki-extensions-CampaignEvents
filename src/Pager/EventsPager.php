<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Pager;

use IContextSource;
use LogicException;
use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventStore;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFactory;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsPage;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWDatabaseProxy;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWUserProxy;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageURLResolver;
use MediaWiki\Extension\CampaignEvents\Special\SpecialEditEventRegistration;
use MediaWiki\Extension\CampaignEvents\Special\SpecialEventRegistration;
use MediaWiki\Linker\LinkRenderer;
use OOUI\ButtonWidget;
use SpecialPage;
use stdClass;
use TablePager;

class EventsPager extends TablePager {
	public const STATUS_ANY = 'any';
	public const STATUS_OPEN = 'open';
	public const STATUS_CLOSED = 'closed';

	private const SORT_INDEXES = [
		'event_start' => [ 'event_start', 'event_name', 'event_id' ],
		'event_name' => [ 'event_name', 'event_start', 'event_id' ],
		'num_participants' => [ 'num_participants', 'event_start', 'event_id' ],
	];

	/** @var CampaignsCentralUserLookup */
	private $centralUserLookup;
	/** @var CampaignsPageFactory */
	private $campaignsPageFactory;
	/** @var PageURLResolver */
	private $pageURLResolver;

	/** @var string */
	private $search;
	/** @var string */
	private $status;

	/** @var array<int,ICampaignsPage> Cache of event page objects, keyed by event ID */
	private $eventPageCache = [];

	/**
	 * @param IContextSource $context
	 * @param LinkRenderer $linkRenderer
	 * @param CampaignsDatabaseHelper $databaseHelper
	 * @param CampaignsCentralUserLookup $centralUserLookup
	 * @param CampaignsPageFactory $campaignsPageFactory
	 * @param PageURLResolver $pageURLResolver
	 * @param string $search
	 * @param string $status One of the self::STATUS_* constants
	 */
	public function __construct(
		IContextSource $context,
		LinkRenderer $linkRenderer,
		CampaignsDatabaseHelper $databaseHelper,
		CampaignsCentralUserLookup $centralUserLookup,
		CampaignsPageFactory $campaignsPageFactory,
		PageURLResolver $pageURLResolver,
		string $search,
		string $status
	) {
		// Set the database before calling the parent constructor, otherwise it'll use the local one.
		$dbWrapper = $databaseHelper->getDBConnection( DB_REPLICA );
		if ( !$dbWrapper instanceof MWDatabaseProxy ) {
			throw new LogicException( "Wrong DB class?!" );
		}
		$this->mDb = $dbWrapper->getMWDatabase();
		parent::__construct( $context, $linkRenderer );
		$this->centralUserLookup = $centralUserLookup;
		$this->campaignsPageFactory = $campaignsPageFactory;
		$this->pageURLResolver = $pageURLResolver;
		$this->search = $search;
		$this->status = $status;
	}

	/**
	 * @inheritDoc
	 */
	public function getQueryInfo(): array {
		$conds = [];

		if ( $this->search !== '' ) {
			// TODO Make this case-insensitive. Not easy right now because the name is a binary string and the DBAL does
			// not provide a method for converting it to a non-binary value on which LOWER can be applied.
			$conds[] = 'event_name' . $this->mDb->buildLike(
				$this->mDb->anyString(), $this->search, $this->mDb->anyString() );
		}

		switch ( $this->status ) {
			case self::STATUS_ANY:
				break;
			case self::STATUS_OPEN:
				$conds['event_status'] = EventStore::getEventStatusDBVal( EventRegistration::STATUS_OPEN );
				break;
			case self::STATUS_CLOSED:
				$conds['event_status'] = EventStore::getEventStatusDBVal( EventRegistration::STATUS_CLOSED );
				break;
			default:
				// Invalid statuses can only be entered by messing with the HTML or query params, ignore.
		}

		$campaignsUser = new MWUserProxy( $this->getUser(), $this->getAuthority() );

		// Use a subquery and a temporary table to work around IndexPager not using HAVING for aggregates (T308694)
		// and to support postgres (which doesn't allow aliases in HAVING).
		$subquery = $this->mDb->buildSelectSubquery(
			[ 'campaign_events', 'ce_participants', 'ce_organizers' ],
			[
				'event_id',
				'event_name',
				'event_page_namespace',
				'event_page_title',
				'event_page_prefixedtext',
				'event_page_wiki',
				'event_status',
				'event_start',
				'event_meeting_type',
				'num_participants' => 'COUNT(cep_id)'
			],
			array_merge(
				$conds,
				[
					'event_deleted_at' => null,
					'cep_unregistered_at' => null,
					'ceo_user_id' => $this->centralUserLookup->getCentralID( $campaignsUser )
				]
			),
			__METHOD__,
			[
				'GROUP BY' => [
					'cep_event_id',
					'event_id',
					'event_name',
					'event_page_namespace',
					'event_page_title',
					'event_page_prefixedtext',
					'event_page_wiki',
					'event_status',
					'event_start',
					'event_meeting_type'
				]
			],
			[
				'ce_participants' => [
					'LEFT JOIN',
					'event_id=cep_event_id'
				],
				'ce_organizers' => [
					'LEFT JOIN',
					'event_id=ceo_event_id'
				]
			]
		);

		return [
			'tables' => [ 'tmp' => $subquery ],
			'fields' => [
				'event_id',
				'event_name',
				'event_page_namespace',
				'event_page_title',
				'event_page_prefixedtext',
				'event_page_wiki',
				'event_status',
				'event_start',
				'event_meeting_type',
				'num_participants'
			],
			'conds' => [],
			'options' => [],
			'join_conds' => []
		];
	}

	/**
	 * @inheritDoc
	 */
	public function formatValue( $name, $value ): string {
		switch ( $name ) {
			case 'event_start':
				return htmlspecialchars( $this->getLanguage()->userDate( $value, $this->getUser() ) );
			case 'event_name':
				return $this->getLinkRenderer()->makeKnownLink(
					SpecialPage::getTitleFor( SpecialEventRegistration::PAGE_NAME, $this->mCurrentRow->event_id ),
					$value,
					[ 'class' => 'ext-campaignevents-eventspager-eventpage-link' ]
				);
			case 'event_location':
				$meetingType = EventStore::getMeetingTypeFromDBVal( $this->mCurrentRow->event_meeting_type );
				if ( $meetingType === EventRegistration::MEETING_TYPE_ONLINE ) {
					$msgKey = 'campaignevents-eventslist-location-online';
				} elseif ( $meetingType === EventRegistration::MEETING_TYPE_PHYSICAL ) {
					$msgKey = 'campaignevents-eventslist-location-physical';
				} elseif ( $meetingType === EventRegistration::MEETING_TYPE_ONLINE_AND_PHYSICAL ) {
					$msgKey = 'campaignevents-eventslist-location-online-and-physical';
				} else {
					throw new LogicException( "Unexpected meeting type: $meetingType" );
				}
				return $this->msg( $msgKey )->escaped();
			case 'num_participants':
				return htmlspecialchars( $this->getLanguage()->formatNum( $value ) );
			case 'manage_event':
				$eventID = $this->mCurrentRow->event_id;
				// This will be replaced with a ButtonMenuSelectWidget in JS.
				$btn = new ButtonWidget( [
					'framed' => false,
					'label' => $this->msg( 'campaignevents-eventslist-manage-btn-info' )->text(),
					'title' => $this->msg( 'campaignevents-eventslist-manage-btn-info' )->text(),
					'invisibleLabel' => true,
					'icon' => 'ellipsis',
					'href' => SpecialPage::getTitleFor(
						SpecialEditEventRegistration::PAGE_NAME,
						$eventID
					)->getLocalURL(),
					'classes' => [ 'ext-campaignevents-eventspager-manage-btn' ]
				] );
				$eventStatus = EventStore::getEventStatusFromDBVal( $this->mCurrentRow->event_status );
				$eventPage = $this->getEventPageFromRow( $this->mCurrentRow );
				$btn->setAttributes( [
					'data-event-id' => $eventID,
					'data-event-name' => $this->mCurrentRow->event_name,
					'data-is-closed' => $eventStatus === EventRegistration::STATUS_CLOSED ? 1 : 0,
					'data-event-page-url' => $this->pageURLResolver->getFullUrl( $eventPage )
				] );
				return $btn->toString();
			default:
				throw new LogicException( "Unexpected name $name" );
		}
	}

	/**
	 * @param stdClass $eventRow
	 * @return ICampaignsPage
	 */
	private function getEventPageFromRow( stdClass $eventRow ): ICampaignsPage {
		$eventID = $eventRow->event_id;
		if ( !isset( $this->eventPageCache[$eventID] ) ) {
			$this->eventPageCache[$eventID] = $this->campaignsPageFactory->newExistingPage(
				(int)$eventRow->event_page_namespace,
				$eventRow->event_page_title,
				$eventRow->event_page_prefixedtext,
				$eventRow->event_page_wiki
			);
		}
		return $this->eventPageCache[$eventID];
	}

	/**
	 * @inheritDoc
	 */
	protected function getFieldNames(): array {
		return [
			'event_start' => $this->msg( 'campaignevents-eventslist-column-date' )->text(),
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
		return 'event_start';
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
				'ext.campaignEvents.specialmyevents.styles',
				'oojs-ui.styles.icons-interactions'
			]
		);
	}

	/**
	 * @return string[] An array of (non-style) RL modules.
	 */
	public function getModules(): array {
		// Avoid creating a new module for the pager only.
		return [ 'ext.campaignEvents.specialmyevents' ];
	}
}
