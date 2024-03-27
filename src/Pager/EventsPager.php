<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Pager;

use IContextSource;
use LogicException;
use MediaWiki\Cache\LinkBatchFactory;
use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventStore;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFactory;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsPage;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWDatabaseProxy;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageURLResolver;
use MediaWiki\Extension\CampaignEvents\Special\SpecialEditEventRegistration;
use MediaWiki\Extension\CampaignEvents\Special\SpecialEventDetails;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Pager\TablePager;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\WikiMap\WikiMap;
use OOUI\ButtonWidget;
use stdClass;
use Wikimedia\Rdbms\IResultWrapper;

class EventsPager extends TablePager {
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

	/** @var array<int,ICampaignsPage> Cache of event page objects, keyed by event ID */
	private array $eventPageCache = [];

	/**
	 * @param IContextSource $context
	 * @param LinkRenderer $linkRenderer
	 * @param CampaignsDatabaseHelper $databaseHelper
	 * @param CampaignsPageFactory $campaignsPageFactory
	 * @param PageURLResolver $pageURLResolver
	 * @param LinkBatchFactory $linkBatchFactory
	 * @param CentralUser $user
	 * @param string $search
	 * @param string $status One of the self::STATUS_* constants
	 */
	public function __construct(
		IContextSource $context,
		LinkRenderer $linkRenderer,
		CampaignsDatabaseHelper $databaseHelper,
		CampaignsPageFactory $campaignsPageFactory,
		PageURLResolver $pageURLResolver,
		LinkBatchFactory $linkBatchFactory,
		CentralUser $user,
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
				'event_start_utc',
				'event_meeting_type',
				'num_participants' => 'COUNT(cep_id)'
			],
			array_merge(
				$conds,
				[
					'event_deleted_at' => null,
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
					'event_start_utc',
					'event_meeting_type'
				]
			],
			[
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
						'ceo_user_id' => $this->centralUser->getCentralID(),
						'ceo_deleted_at' => null,
					]
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
				'event_start_utc',
				'event_meeting_type',
				'num_participants'
			],
			'conds' => [],
			'options' => [],
			'join_conds' => []
		];
	}

	/**
	 * Add event pages to a LinkBatch to improve performance and not make one query per page.
	 * This code was stolen from AbuseFilter's pager et al.
	 * @param IResultWrapper $result
	 */
	protected function preprocessResults( $result ): void {
		if ( $this->getNumRows() === 0 ) {
			return;
		}
		$lb = $this->linkBatchFactory->newLinkBatch();
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
			$this->eventPageCache[$eventID] = $this->campaignsPageFactory->newPageFromDB(
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
}
