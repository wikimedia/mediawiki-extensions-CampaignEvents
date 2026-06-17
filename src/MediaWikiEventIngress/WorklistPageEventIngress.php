<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MediaWikiEventIngress;

use IDBAccessObject;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\DomainEvent\DomainEventIngress;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\Worklist\UpdateWorklistPagesSecondaryStoreJob;
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
 * This class listens for changes to pages, and intercept changes to worklist pages to update the secondary storage.
 */
class WorklistPageEventIngress extends DomainEventIngress implements
	PageCreatedListener,
	PageDeletedListener,
	PageMovedListener,
	PageLatestRevisionChangedListener,
	PageHistoryVisibilityChangedListener
{
	public function __construct(
		private readonly WorklistSecondaryStore $worklistSecondaryStore,
		private readonly TitleFactory $titleFactory,
		private readonly TitleFormatter $titleFormatter,
		private readonly RevisionLookup $revisionLookup,
		private readonly CampaignsCentralUserLookup $centralUserLookup,
		private readonly JobQueueGroup $jobQueueGroup,
	) {
	}

	private function getPageContentModel( PageIdentity $page ): string {
		$title = $this->titleFactory->newFromPageReference( $page );
		return $title->getContentModel();
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

		DeferredUpdates::addCallableUpdate( function () use ( $event, $pageAfter ): void {
			$this->worklistSecondaryStore->moveWorklist(
				WikiMap::getCurrentWikiId(),
				$event->getPageId(),
				$this->titleFormatter->getPrefixedText( $pageAfter )
			);
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
