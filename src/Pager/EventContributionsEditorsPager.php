<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Pager;

use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContribution;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionStore;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserLinker;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Page\LinkBatchFactory;
use MediaWiki\Pager\CodexTablePager;
use MediaWiki\RecentChanges\ChangesList;
use UnexpectedValueException;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\IResultWrapper;

class EventContributionsEditorsPager extends CodexTablePager {
	use EventContributionsPagerTrait;

	/**
	 * Stringified username to be used in the pager query to avoid wrong sorting with NULL values (see
	 * T404995#11321541 and following comments). Note that this cannot be used with an alias as that won't
	 * work in MySQL/MariaDB (T416569).
	 */
	private const QUERY_USERNAME_STR = 'COALESCE(cec_user_name, "")';

	protected const INDEX_FIELDS = [
		'user_name' => [
			self::QUERY_USERNAME_STR,
			'cec_user_id'
		],
		'articles_created' => [
			'articles_added',
			'cec_user_id'
		],
		'articles_edited' => [
			'articles_edited',
			'cec_user_id'
		],
		'edit_count' => [
			'edit_count',
			'cec_user_id'
		],
		'bytes' => [
			'bytes',
			'cec_user_id'
		],
	];

	/** @var array<string,mixed> */
	private array $extraQuery = [];

	public function __construct(
		protected readonly IReadableDatabase $db,
		LinkRenderer $linkRenderer,
		private readonly LinkBatchFactory $linkBatchFactory,
		protected UserLinker $userLinker,
		private readonly PermissionChecker $permissionChecker,
		protected CampaignsCentralUserLookup $centralUserLookup,
		protected readonly ExistingEventRegistration $event,
		private readonly EventContributionStore $eventContributionStore,
		IContextSource $context
	) {
		// Set the database before calling the parent constructor, otherwise it'll use the local one.
		$this->mDb = $db;
		parent::__construct(
			$this->msg( 'campaignevents-event-details-contributions-editors-table-caption' )->text(),
			$context,
			$linkRenderer
		);
	}

	/**
	 * Allow callers to pass extra query parameters that should be preserved
	 * on generated links (e.g., active tab on Special:EventDetails).
	 *
	 * @param array<string,mixed> $params
	 */
	public function setExtraQuery( array $params ): void {
		$this->extraQuery = $params;
	}

	/**
	 * @inheritDoc
	 * @return array<int,array<int,string>>
	 */
	public function getIndexField(): array {
		return [ self::INDEX_FIELDS[$this->mSort] ];
	}

	/**
	 * @param IResultWrapper $result
	 */
	public function preprocessResults( $result ): void {
		$this->preloadUserData( $result );
	}

	protected function getFieldNames(): array {
		return [
			'user_name' => $this->msg( 'campaignevents-event-details-contributions-editors-username' )->text(),
			'articles_created' => $this->msg( 'campaignevents-event-details-contributions-editors-created' )->text(),
			'articles_edited' => $this->msg( 'campaignevents-event-details-contributions-editors-edited' )->text(),
			'edit_count' => $this->msg( 'campaignevents-event-details-contributions-editors-count' )->text(),
			'bytes' => $this->msg( 'campaignevents-event-details-contributions-editors-bytes' )->text(),
		];
	}

	/**
	 * @inheritDoc
	 * @return array<string,mixed>
	 */
	public function getQueryInfo(): array {
		// We need to GROUP BY all fields to pass ONLY_FULL_GROUP_BY in MariaDB: even though
		// `cec_user_id` uniquely determines a row, MariaDB does not detect functional dependencies:
		// https://jira.mariadb.org/browse/MDEV-11588
		$simpleFields = [
			'cec_user_name',
			self::QUERY_USERNAME_STR,
			'cec_user_id',
			'cep_private',
		];
		$queryInfo = [
			'tables' => [
				'cec' => 'ce_event_contributions',
				'cep' => 'ce_participants',
			],
			'fields' => [
				...$simpleFields,
				'articles_added' => 'SUM(' . $this->db->conditional(
						$this->db->bitAnd( 'cec.cec_edit_flags', EventContribution::EDIT_FLAG_PAGE_CREATION ) . ' != 0',
						1,
						0
					) . ')',
				'articles_edited' => 'COUNT(DISTINCT ' . $this->db->conditional(
						$this->db->bitAnd( 'cec.cec_edit_flags', EventContribution::EDIT_FLAG_PAGE_CREATION ) . ' = 0',
						$this->db->buildConcat( [ 'cec.cec_wiki', $this->db->addQuotes( '|' ), 'cec.cec_page_id' ] ),
						'NULL'
					) . ')',
				'edit_count' => 'COUNT(*)',
				'bytes' => 'SUM(cec_bytes_delta)',
			],
			'conds' => [
				'cec.cec_event_id' => $this->event->getID(),
				'cec.cec_deleted' => 0,
			],
			'join_conds' => [
				'cep' => [
					'JOIN',
					[
						'cec.cec_event_id = cep.cep_event_id',
						'cec.cec_user_id = cep.cep_user_id',
						'cep.cep_unregistered_at' => null,
					],
				],
			],
			'options' => [
				'GROUP BY' => $simpleFields,
			]
		];

		$this->addPrivateParticipantConds( $queryInfo );
		return $queryInfo;
	}

	/**
	 * @inheritDoc
	 */
	protected function isFieldSortable( $field ): bool {
		return isset( self::INDEX_FIELDS[$field] );
	}

	/**
	 * @inheritDoc
	 */
	public function formatValue( $name, $value ): string {
		$row = $this->mCurrentRow;
		$language = $this->getOutput()->getLanguage();
		return match ( $name ) {
			'user_name' => $this->formatUsername( $row ),
			'articles_created' => $language->formatNum( $row->articles_added ),
			'articles_edited' => $language->formatNum( $row->articles_edited ),
			'edit_count' => $language->formatNum( $row->edit_count ),
			'bytes' => ChangesList::showCharacterDifference( 0, (int)$row->bytes, $this->getContext() ),
			default => throw new UnexpectedValueException( 'Unexpected column: ' . $name ),
		};
	}

	/**
	 * @inheritDoc
	 */
	public function getDefaultSort() {
		return 'user_name';
	}

	/**
	 * Override getDefaultQuery to ensure tab parameter is preserved
	 *
	 * @return array<string,mixed>
	 */
	public function getDefaultQuery(): array {
		return parent::getDefaultQuery() + $this->extraQuery;
	}
}
