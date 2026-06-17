<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Worklist;

use InvalidArgumentException;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\JobQueue\GenericParameterJob;
use MediaWiki\JobQueue\Job;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Wikimedia\Rdbms\IDBAccessObject;

/**
 * This job is enqueued upon changes to the content of a worklist page, and invokes methods in the secondary
 * (relational) storage to sync it with the content of the wikipage.
 * Handling of race conditions is inspired by {@link \MediaWiki\JobQueue\Jobs\RefreshLinksJob}.
 */
class UpdateWorklistPagesSecondaryStoreJob extends Job implements GenericParameterJob {
	/** @inheritDoc */
	protected $removeDuplicates = true;

	public const TYPE_UPDATE = 'update';
	public const TYPE_DELETE = 'delete';

	private string $type;
	private int $worklistID;
	private ?CentralUser $performer;
	/** ID of the revision that triggered this update, or null if triggered by a page deletion. */
	private ?int $triggeringRevID;

	private LoggerInterface $logger;

	/**
	 * @var callable|null For use in this class' tests only: DB locks don't really work and are disabled in PHPUnit
	 * tests (T427466). So, the test can instead choose to use a mock implementation.
	 */
	public $testLockFunction;

	/**
	 * This is for use by the JobQueue infrastructure, do not call directly. Use {@link self::newForUpdate} or
	 * {@link self::newForDeletion} instead.
	 *
	 * @phpcs:ignore Generic.Files.LineLength
	 * @param array{type:string,namespace:int,title:string,worklistID:int,performerCentralID?:int,triggeringRevID:int|null} $params
	 */
	public function __construct( array $params ) {
		if (
			!isset( $params['type'] ) ||
			!in_array( $params['type'], [ self::TYPE_UPDATE, self::TYPE_DELETE ], true )
		) {
			throw new InvalidArgumentException( 'Invalid job type: ' . ( $params['type'] ?? 'null' ) );
		}

		$requiredParams = [ 'namespace', 'title', 'worklistID', 'triggeringRevID' ];
		$missingParams = array_diff( $requiredParams, array_keys( $params ) );
		if ( $missingParams ) {
			throw new InvalidArgumentException( 'Missing params: ' . implode( ', ', $missingParams ) );
		}
		if ( $params['type'] === self::TYPE_UPDATE ) {
			if ( $params['triggeringRevID'] === null ) {
				throw new InvalidArgumentException( 'triggeringRevID cannot be null for updates' );
			}
			if ( ( $params['performerCentralID'] ?? null ) === null ) {
				throw new InvalidArgumentException( 'performerCentralID cannot be null for updates' );
			}
		}

		parent::__construct( 'CampaignEventsUpdateWorklistPagesSecondaryStore', $params );

		$this->type = $params['type'];
		$this->worklistID = $params['worklistID'];
		$this->performer = isset( $params['performerCentralID'] )
			? new CentralUser( $params['performerCentralID'] )
			: null;
		$this->triggeringRevID = $params['triggeringRevID'];
		$this->logger = LoggerFactory::getInstance( 'CampaignEvents' );
	}

	/** Creates an instance of this job that handles worklist creation and updates (not deletions) */
	public static function newForUpdate(
		PageIdentity $page,
		int $worklistID,
		CentralUser $performer,
		int $triggeringRevID
	): self {
		return new self( [
			'type' => self::TYPE_UPDATE,
			'namespace' => $page->getNamespace(),
			'title' => $page->getDBkey(),
			'worklistID' => $worklistID,
			'performerCentralID' => $performer->getCentralID(),
			'triggeringRevID' => $triggeringRevID,
		] );
	}

	/** Creates an instance of this job that handles worklist deletions. */
	public static function newForDeletion( PageIdentity $page, int $worklistID, ?int $triggeringRevID ): self {
		return new self( [
			'type' => self::TYPE_DELETE,
			'namespace' => $page->getNamespace(),
			'title' => $page->getDBkey(),
			'worklistID' => $worklistID,
			'triggeringRevID' => $triggeringRevID,
		] );
	}

	public function run(): bool {
		$services = MediaWikiServices::getInstance();

		$wikiPageFactory = $services->getWikiPageFactory();
		$page = $wikiPageFactory->newFromTitle( $this->getTitle() );
		$page->loadPageData( IDBAccessObject::READ_LATEST );

		$latestRev = $page->getRevisionRecord();
		$latestRevID = $latestRev?->getId() ?? 0;
		$triggeringRevIDInt = $this->triggeringRevID ?? 0;
		if ( $latestRevID !== $triggeringRevIDInt ) {
			// The page might have been deleted or recreated in the meantime, so this job is obsolete.
			// Treat as success, the update will be done elsewhere.
			$this->logger->info(
				"UpdateWorklistPagesSecondaryStoreJob: detected obsolete job ($latestRevID vs $triggeringRevIDInt)"
			);
			return true;
		}

		$dbw = $services->getConnectionProvider()->getPrimaryDatabase();
		$lockKey = "{$dbw->getDomainID()}:UpdateWorklistPagesSecondaryStoreJob:pageid:{$page->getId()}";
		if ( defined( 'MW_PHPUNIT_TEST' ) && $this->testLockFunction ) {
			$scopedLock = ( $this->testLockFunction )();
		} else {
			$scopedLock = $dbw->getScopedLockAndFlush( $lockKey, __METHOD__, 1 );
		}

		if ( !$scopedLock ) {
			// A job is already handling an update for this page, most likely from a previous revision. Retry later.
			$this->logger->info(
				"UpdateWorklistPagesSecondaryStoreJob: cannot acquire lock for rev $latestRevID"
			);
			return false;
		}

		// Reload the revision ID, in case the page got changed between the first loading and the lock
		$newLatestRevID = $this->getTitle()->getLatestRevID( IDBAccessObject::READ_LATEST );
		if ( $newLatestRevID !== $latestRevID ) {
			// Another edit happened right before the lock. Treat as success, the update will be done elsewhere.
			$this->logger->info(
				"UpdateWorklistPagesSecondaryStoreJob: page changed before lock ($latestRevID vs $newLatestRevID)"
			);
			return true;
		}

		// Everything is up-to-date, do the actual update
		$this->syncSecondaryStore( $latestRev );
		return true;
	}

	private function syncSecondaryStore( ?RevisionRecord $latestRev ): void {
		$pagesSecondaryStore = CampaignEventsServices::getWorklistPagesSecondaryStore();

		$contentAfter = $latestRev?->getContent( SlotRecord::MAIN );
		if ( !$latestRev || !$contentAfter instanceof WorklistContent ) {
			// Page was deleted or content is no longer a worklist, delete all.
			if ( $this->type !== self::TYPE_DELETE ) {
				// Should never happen
				throw new RuntimeException( 'Planned an update but ended up deleting' );
			}
			$pagesSecondaryStore->deleteAllWorklistPages( $this->worklistID );
			// No need to update the last synced revision, as the worklist is getting deleted.
			return;
		}

		if ( $this->type !== self::TYPE_UPDATE ) {
			// Should never happen
			throw new RuntimeException( 'Planned a deletion but ended up updating' );
		}

		$worklistsStore = CampaignEventsServices::getWorklistSecondaryStore();
		$revisionLookup = MediaWikiServices::getInstance()->getRevisionLookup();

		$lastSyncedRevID = $worklistsStore->getWorklistContentSyncedRev(
			$this->worklistID,
			IDBAccessObject::READ_LATEST
		);
		if ( $lastSyncedRevID !== null ) {
			$lastSyncedRev = $revisionLookup->getRevisionById( $lastSyncedRevID );
			if ( !$lastSyncedRev ) {
				// This could happen if the worklist page gets deleted, and then recreated before the job for deletion
				// runs. In that case, the winning (latest) job will be for an update (not a deletion), but the original
				// revision no longer exists. So, try looking for an archived revision...
				$this->logger->info(
					"UpdateWorklistPagesSecondaryStoreJob: cannot find revision $lastSyncedRevID, trying archive"
				);
				$lastSyncedRev = MediaWikiServices::getInstance()->getArchivedRevisionLookup()
					->getArchivedRevisionRecord( $latestRev->getPage(), $lastSyncedRevID );
			}

			if ( !$lastSyncedRev ) {
				// This should probably never happen, but if it ever does, just delete everything and re-sync the
				// content from scratch. This can potentially affect lots of rows unnecessarily, but maybe it
				// can't actually happen in practice.
				$this->logger->error(
					"UpdateWorklistPagesSecondaryStoreJob: can't find revision $lastSyncedRevID even in archive"
				);
				$pagesSecondaryStore->deleteAllWorklistPages( $this->worklistID );
				$lastContent = null;
			} else {
				// Skip audience checks, so if the last rev was deleted in the meantime we can still compute a diff.
				$lastContent = $lastSyncedRev->getMainContentRaw();
				if ( !$lastContent instanceof WorklistContent ) {
					// Should never happen
					throw new RuntimeException( "Last synced revision ($lastSyncedRevID) must be a worklist" );
				}
			}
		} else {
			// First sync, so we have no pages
			$lastContent = null;
		}

		$contentDelta = WorklistContent::computeDelta( $lastContent, $contentAfter );
		$pagesSecondaryStore->updateWorklistPages(
			$this->worklistID,
			$this->performer,
			$contentDelta['removed'],
			$contentDelta['added']
		);

		$worklistsStore->updateWorklistContentSyncedRev( $this->worklistID, $latestRev->getId() );
	}
}
