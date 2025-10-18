<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MediaWikiEventIngress;

use MediaWiki\DomainEvent\DomainEventIngress;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionStore;
use MediaWiki\Extension\CampaignEvents\EventContribution\UpdatePageContributionRecordsJob;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\Page\Event\PageCreatedEvent;
use MediaWiki\Page\Event\PageCreatedListener;
use MediaWiki\Page\Event\PageDeletedEvent;
use MediaWiki\Page\Event\PageDeletedListener;
use MediaWiki\Page\Event\PageHistoryVisibilityChangedEvent;
use MediaWiki\Page\Event\PageHistoryVisibilityChangedListener;
use MediaWiki\Page\Event\PageMovedEvent;
use MediaWiki\Page\Event\PageMovedListener;
use MediaWiki\Storage\PageUpdateCauses;
use MediaWiki\Title\TitleFormatter;
use MediaWiki\WikiMap\WikiMap;

/**
 * This class listens to page changes (moves, deletions, revision deletions) and issues updates to any stored
 * associations of an impacted edit and an event.
 * Most pages are not associated with events, so we try not to schedule anything in that case.
 */
class ContributionAssociationPageEventIngress extends DomainEventIngress implements
	PageDeletedListener,
	PageCreatedListener,
	PageMovedListener,
	PageHistoryVisibilityChangedListener
{
	public function __construct(
		private readonly EventContributionStore $eventContributionStore,
		private readonly TitleFormatter $titleFormatter,
		private readonly JobQueueGroup $jobQueueGroup,
	) {
	}

	public function handlePageDeletedEvent( PageDeletedEvent $event ): void {
		$page = $event->getDeletedPage();
		if ( !$this->eventContributionStore->hasContributionsForPage( $page ) ) {
			return;
		}
		$job = new UpdatePageContributionRecordsJob( [
			'type' => UpdatePageContributionRecordsJob::TYPE_DELETE,
			'wiki' => WikiMap::getCurrentWikiId(),
			'pageID' => $page->getID( $page->getWikiId() ),
		] );
		$this->jobQueueGroup->push( $job );
	}

	public function handlePageCreatedEvent( PageCreatedEvent $event ): void {
		if ( !$event->hasCause( PageUpdateCauses::CAUSE_UNDELETE ) ) {
			return;
		}

		$page = $event->getPageRecordAfter();
		if ( !$this->eventContributionStore->hasContributionsForPage( $page ) ) {
			return;
		}
		$job = new UpdatePageContributionRecordsJob( [
			'type' => UpdatePageContributionRecordsJob::TYPE_RESTORE,
			'wiki' => WikiMap::getCurrentWikiId(),
			'pageID' => $page->getID( $page->getWikiId() ),
		] );
		$this->jobQueueGroup->push( $job );
	}

	public function handlePageMovedEvent( PageMovedEvent $event ): void {
		$page = $event->getPageRecordBefore();
		if ( !$this->eventContributionStore->hasContributionsForPage( $page ) ) {
			return;
		}
		$job = new UpdatePageContributionRecordsJob( [
			'type' => UpdatePageContributionRecordsJob::TYPE_MOVE,
			'wiki' => WikiMap::getCurrentWikiId(),
			'pageID' => $page->getID( $page->getWikiId() ),
			'newPrefixedText' => $this->titleFormatter->getPrefixedText( $event->getPageRecordAfter() )
		] );
		$this->jobQueueGroup->push( $job );
	}

	public function handlePageHistoryVisibilityChangedEvent( PageHistoryVisibilityChangedEvent $event ): void {
		$revIDs = $event->getAffectedRevisionIDs();
		$newlyDeleted = $newlyRestored = [];
		foreach ( $revIDs as $revID ) {
			$before = $event->getVisibilityBefore( $revID );
			$after = $event->getVisibilityAfter( $revID );
			if ( $before === 0 && $after !== 0 ) {
				$newlyDeleted[] = $revID;
			} elseif ( $before !== 0 && $after === 0 ) {
				$newlyRestored[] = $revID;
			}
		}

		if ( !$newlyDeleted && !$newlyRestored ) {
			return;
		}

		$page = $event->getPage();
		if ( !$this->eventContributionStore->hasContributionsForPage( $page ) ) {
			return;
		}

		$job = new UpdatePageContributionRecordsJob( [
			'type' => UpdatePageContributionRecordsJob::TYPE_REV_DELETE,
			'wiki' => WikiMap::getCurrentWikiId(),
			'pageID' => $page->getID( $page->getWikiId() ),
			'deletedRevIDs' => $newlyDeleted,
			'restoredRevIDs' => $newlyRestored,
		] );
		$this->jobQueueGroup->push( $job );
	}
}
