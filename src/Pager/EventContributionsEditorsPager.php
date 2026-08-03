<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Pager;

use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionStore;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserLinker;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Page\LinkBatchFactory;
use MediaWiki\Pager\CodexTablePager;
use MediaWiki\RecentChanges\ChangesList;
use UnexpectedValueException;
use Wikimedia\Rdbms\IResultWrapper;

class EventContributionsEditorsPager extends CodexTablePager {
	use EventContributionsPagerTrait;

	protected const INDEX_FIELDS = [
		'user_name' => [
			EventContributionStore::QUERY_USERNAME_STR,
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

	private const AGGREGATE_INDICES = [ 'articles_created', 'articles_edited', 'edit_count', 'bytes' ];

	/** @var array<string,mixed> */
	private array $extraQuery = [];

	public function __construct(
		CampaignsDatabaseHelper $databaseHelper,
		private readonly LinkBatchFactory $linkBatchFactory,
		protected UserLinker $userLinker,
		private readonly PermissionChecker $permissionChecker,
		protected CampaignsCentralUserLookup $centralUserLookup,
		private readonly EventContributionStore $eventContributionStore,
		IContextSource $context,
		LinkRenderer $linkRenderer,
		protected readonly ExistingEventRegistration $event,
	) {
		// Set the database before calling the parent constructor, otherwise it'll use the local one.
		$this->mDb = $databaseHelper->getReplicaConnection();
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
		$queryInfo = $this->eventContributionStore->getEditorsQueryInfo( $this->event->getID() );
		$this->addPrivateParticipantConds( $queryInfo );
		return $queryInfo;
	}

	protected function indexUsesAggregate(): bool {
		return in_array( $this->mSort, self::AGGREGATE_INDICES, true );
	}

	protected function adjustQueryStringOffsets( array &$offsets ): void {
		if ( $this->indexUsesAggregate() ) {
			$aggregateCol = self::INDEX_FIELDS[$this->mSort][0];
			$offsets[$aggregateCol] = (int)$offsets[$aggregateCol];
		}
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
