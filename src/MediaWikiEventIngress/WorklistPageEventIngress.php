<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MediaWikiEventIngress;

use IDBAccessObject;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\DomainEvent\DomainEventIngress;
use MediaWiki\Extension\CampaignEvents\Event\PageEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\Worklist\UpdateWorklistPagesSecondaryStoreJob;
use MediaWiki\Extension\CampaignEvents\Worklist\WorklistEventsStore;
use MediaWiki\Extension\CampaignEvents\Worklist\WorklistSecondaryStore;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\Page\Event\PageCreatedEvent;
use MediaWiki\Page\Event\PageCreatedListener;
use MediaWiki\Page\Event\PageDeletedEvent;
use MediaWiki\Page\Event\PageDeletedListener;
use MediaWiki\Page\Event\PageHistoryVisibilityChangedEvent;
use MediaWiki\Page\Event\PageHistoryVisibilityChangedListener;
use MediaWiki\Page\Event\PageLatestRevisionChangedEvent;
use MediaWiki\Page\Event\PageLatestRevisionChangedListener;
use MediaWiki\Page\Event\PageMovedEvent;
use MediaWiki\Page\Event\PageMovedListener;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Page\PageRecord;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Title\TitleFactory;
use MediaWiki\Title\TitleFormatter;
use MediaWiki\WikiMap\WikiMap;
use RuntimeException;

/**
 * Listens for changes to worklist pages and keeps both the worklist secondary storage
 * (ce_worklists / ce_worklist_pages) and the event ↔ worklist association (ce_worklist_events) in
 * sync: it creates, updates, moves and deletes the secondary-store rows, and links a worklist to an
 * event when the worklist is created as (or moved onto) the event page's "/Worklist" subpage.
 *
 * Deliberately NOT handled (considered rare enough to skip for the MVP): a worklist "/Worklist"
 * subpage created before its event registration exists is not associated with the event, because
 * there is no event to resolve at creation time.
 */
class WorklistPageEventIngress extends DomainEventIngress implements
	PageCreatedListener,
	PageDeletedListener,
	PageMovedListener,
	PageLatestRevisionChangedListener,
	PageHistoryVisibilityChangedListener
{
	/** Leaf name of the subpage that holds an event's worklist (e.g. "Event:Foo/Worklist"). */
	private const WORKLIST_SUBPAGE = 'Worklist';

	public function __construct(
		private readonly WorklistSecondaryStore $worklistSecondaryStore,
		private readonly TitleFactory $titleFactory,
		private readonly TitleFormatter $titleFormatter,
		private readonly RevisionLookup $revisionLookup,
		private readonly CampaignsCentralUserLookup $centralUserLookup,
		private readonly JobQueueGroup $jobQueueGroup,
		private readonly WorklistEventsStore $worklistEventsStore,
		private readonly PageEventLookup $pageEventLookup,
	) {
	}

	private function getPageContentModel( PageIdentity $page ): string {
		$title = $this->titleFactory->newFromPageReference( $page );
		return $title->getContentModel();
	}

	/**
	 * Resolves the ID of the event that owns the given worklist page, or null if there is none.
	 *
	 * The worklist lives at a fixed "/Worklist" subpage of the event page, so the event page is its
	 * base title.
	 */
	private function getEventIDForWorklistPage( PageIdentity $worklistPage ): ?int {
		$worklistTitle = $this->titleFactory->newFromPageReference( $worklistPage );
		if ( $worklistTitle->getSubpageText() !== self::WORKLIST_SUBPAGE ) {
			return null;
		}
		$event = $this->pageEventLookup->getRegistrationForLocalPage( $worklistTitle->getBaseTitle() );
		return $event?->getID();
	}

	private function createOrUpdateWorklistFromEvent(
		PageCreatedEvent|PageLatestRevisionChangedEvent $event,
		bool $needsCreation
	): void {
		try {
			$performer = $this->centralUserLookup->newFromUserIdentity( $event->getPerformer() );
		} catch ( UserNotGlobalException ) {
			// Cannot sync the worklist, but this should be rare.
			return;
		}

		DeferredUpdates::addCallableUpdate( function () use ( $event, $performer, $needsCreation ): void {
			$wiki = WikiMap::getCurrentWikiId();
			$page = $event->getPageRecordAfter();
			$revID = $event->getLatestRevisionAfter()->getId();

			if ( $needsCreation ) {
				$worklistID = $this->worklistSecondaryStore->createWorklist(
					$wiki,
					$page->getId(),
					$this->titleFormatter->getPrefixedText( $page ),
					$performer,
					$event->getPerformer()->getName(),
					$event->getEventTimestamp()
				);

				// Link the new worklist to its event (primary storage in ce_worklist_events) when it
				// is an event's "/Worklist" subpage. Done only on creation to avoid a replica-read/
				// master-write race on every edit; moves are handled by handlePageMovedEvent().
				$eventID = $this->getEventIDForWorklistPage( $page );
				if ( $eventID !== null ) {
					$this->worklistEventsStore->associateEventWithWorklist( $eventID, $worklistID );
				}
			} else {
				$worklistID = $this->worklistSecondaryStore->getWorklistIDFromPage( $wiki, $page->getId() );
				if ( !$worklistID ) {
					throw new RuntimeException( "Cannot find worklist to update that should exist for $page" );
				}
			}

			$job = UpdateWorklistPagesSecondaryStoreJob::newForUpdate( $page, $worklistID, $performer, $revID );
			$this->jobQueueGroup->push( $job );
		} );
	}

	private function deleteWorklistFromPage( PageRecord $page, ?int $revIDAfter ): void {
		DeferredUpdates::addCallableUpdate( function () use ( $page, $revIDAfter ): void {
			$wiki = WikiMap::getCurrentWikiId();

			$worklistID = $this->worklistSecondaryStore->getWorklistIDFromPage( $wiki, $page->getId() );
			if ( !$worklistID ) {
				throw new RuntimeException( "Cannot find worklist to delete that should exist for $page" );
			}
			$this->worklistSecondaryStore->deleteWorklist( $wiki, $page->getId() );
			$eventID = $this->getEventIDForWorklistPage( $page );
			if ( $eventID !== null ) {
				$this->worklistEventsStore->removeWorklistAssociation( $worklistID, $eventID );
			}
			$job = UpdateWorklistPagesSecondaryStoreJob::newForDeletion( $page, $worklistID, $revIDAfter );
			$this->jobQueueGroup->push( $job );
		} );
	}

	public function handlePageCreatedEvent(
		PageCreatedEvent $event
	): void {
		$page = $event->getPageRecordAfter();
		$contentModel = $this->getPageContentModel( $page );
		if ( $contentModel !== CONTENT_MODEL_WORKLIST ) {
			return;
		}

		$this->createOrUpdateWorklistFromEvent( $event, true );
	}

	public function handlePageDeletedEvent( PageDeletedEvent $event ): void {
		$contentModel = $event->getLatestRevisionBefore()->getMainContentModel();
		if ( $contentModel !== CONTENT_MODEL_WORKLIST ) {
			return;
		}

		$this->deleteWorklistFromPage( $event->getPageRecordBefore(), null );
	}

	public function handlePageMovedEvent( PageMovedEvent $event ): void {
		$pageAfter = $event->getPageRecordAfter();
		$contentModel = $this->getPageContentModel( $pageAfter );
		if ( $contentModel !== CONTENT_MODEL_WORKLIST ) {
			return;
		}

		$pageBefore = $event->getPageRecordBefore();
		DeferredUpdates::addCallableUpdate( function () use ( $event, $pageBefore, $pageAfter ): void {
			$wiki = WikiMap::getCurrentWikiId();
			$this->worklistSecondaryStore->moveWorklist(
				$wiki,
				$event->getPageId(),
				$this->titleFormatter->getPrefixedText( $pageAfter )
			);

			// Keep the event association in sync with the page's location: a page moved onto an
			// event's "/Worklist" subpage becomes associated, and one moved away from it is
			// dissociated (otherwise it would keep a stale association, and recreating "/Worklist"
			// would leave the event with two associated worklists).
			$eventIDBefore = $this->getEventIDForWorklistPage( $pageBefore );
			$eventIDAfter = $this->getEventIDForWorklistPage( $pageAfter );
			if ( $eventIDBefore === $eventIDAfter ) {
				return;
			}
			$worklistID = $this->worklistSecondaryStore->getWorklistIDFromPage( $wiki, $pageAfter->getId() );
			if ( !$worklistID ) {
				return;
			}
			if ( $eventIDBefore !== null ) {
				$this->worklistEventsStore->removeWorklistAssociation( $worklistID, $eventIDBefore );
			}
			if ( $eventIDAfter !== null ) {
				$this->worklistEventsStore->associateEventWithWorklist( $eventIDAfter, $worklistID );
			}
		} );
	}

	public function handlePageLatestRevisionChangedEvent( PageLatestRevisionChangedEvent $event ): void {
		$pageBefore = $event->getPageRecordBefore();
		if ( !$pageBefore ) {
			// Page creation, handled separately (checked like this instead of `isCreation` for static analysis)
			return;
		}
		// Check for content model changes
		$contentModelBefore = $event->getLatestRevisionBefore()->getMainContentModel();
		$contentModelAfter = $event->getLatestRevisionAfter()->getMainContentModel();

		if ( $contentModelAfter === CONTENT_MODEL_WORKLIST ) {
			// Page became a worklist, or it was just changed
			$this->createOrUpdateWorklistFromEvent( $event, $contentModelBefore !== CONTENT_MODEL_WORKLIST );
		} elseif ( $contentModelBefore === CONTENT_MODEL_WORKLIST ) {
			// No longer a worklist
			$this->deleteWorklistFromPage( $pageBefore, $event->getLatestRevisionAfter()->getId() );
		}
	}

	public function handlePageHistoryVisibilityChangedEvent( PageHistoryVisibilityChangedEvent $event ): void {
		$page = $event->getPage();
		$contentModel = $this->getPageContentModel( $page );
		if ( $contentModel !== CONTENT_MODEL_WORKLIST ) {
			return;
		}

		$firstPageRevID = $this->revisionLookup->getFirstRevision( $page )->getId();
		if ( !in_array( $firstPageRevID, $event->getAffectedRevisionIDs(), true ) ) {
			return;
		}
		$visibilityBefore = $event->getVisibilityBefore( $firstPageRevID );
		$visibilityAfter = $event->getVisibilityAfter( $firstPageRevID );
		$hasDeletedUser = static fn ( int $bits ): bool => ( $bits & RevisionRecord::DELETED_USER ) !== 0;

		if ( !$hasDeletedUser( $visibilityBefore ) && $hasDeletedUser( $visibilityAfter ) ) {
			// Author name was deleted
			$newName = null;
		} elseif ( $hasDeletedUser( $visibilityBefore ) && !$hasDeletedUser( $visibilityAfter ) ) {
			// Author name was undeleted. Use a placeholder so we can read the value from master (asynchronously
			// to avoid master reads in the main request).
			$newName = true;
		} else {
			return;
		}

		DeferredUpdates::addCallableUpdate( function () use ( $page, $newName ): void {
			if ( $newName === true ) {
				// Read from master to get the name post-undeletion, without bypassing the FOR_PUBLIC visibility check
				// to avoid any chance of info leaks.
				$firstRevFromMaster = $this->revisionLookup->getFirstRevision( $page, IDBAccessObject::READ_LATEST );
				$newName = $firstRevFromMaster->getUser()->getName();
			}
			$this->worklistSecondaryStore->updateWorklistCreatorName(
				WikiMap::getCurrentWikiId(),
				$page->getId(),
				$newName
			);
		} );
	}
}
