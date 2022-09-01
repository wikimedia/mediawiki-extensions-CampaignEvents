<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Hooks\Handlers;

use ManualLogEntry;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventStore;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWEventLookupFromPage;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWPageProxy;
use MediaWiki\Hook\PageMoveCompleteHook;
use MediaWiki\Page\Hook\PageDeleteCompleteHook;
use MediaWiki\Page\PageStore;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Permissions\Authority;
use MediaWiki\Revision\RevisionRecord;
use TitleFormatter;

/**
 * This handler is used for page move and deletion. If the page is an event page, we update the registration:
 *  - Delete it in case of event page deletion
 *  - Make it point to the new page in case of page move
 */
class PageMoveAndDeleteHandler implements PageMoveCompleteHook, PageDeleteCompleteHook {
	/** @var MWEventLookupFromPage */
	private $eventLookupFromPage;
	/** @var IEventStore */
	private $eventStore;
	/** @var PageStore */
	private $pageStore;
	/** @var TitleFormatter */
	private $titleFormatter;

	/**
	 * @param MWEventLookupFromPage $eventLookupFromPage
	 * @param IEventStore $eventStore
	 * @param PageStore $pageStore
	 * @param TitleFormatter $titleFormatter
	 */
	public function __construct(
		MWEventLookupFromPage $eventLookupFromPage,
		IEventStore $eventStore,
		PageStore $pageStore,
		TitleFormatter $titleFormatter
	) {
		$this->eventLookupFromPage = $eventLookupFromPage;
		$this->eventStore = $eventStore;
		$this->pageStore = $pageStore;
		$this->titleFormatter = $titleFormatter;
	}

	/**
	 * @inheritDoc
	 */
	public function onPageDeleteComplete(
		ProperPageIdentity $page,
		Authority $deleter,
		string $reason,
		int $pageID,
		RevisionRecord $deletedRev,
		ManualLogEntry $logEntry,
		int $archivedRevisionCount
	) {
		$registration = $this->eventLookupFromPage->getRegistrationForPage( $page );
		if ( !$registration ) {
			return;
		}
		$this->eventStore->deleteRegistration( $registration );
	}

	/**
	 * @inheritDoc
	 */
	public function onPageMoveComplete( $old, $new, $user, $pageid, $redirid, $reason, $revision ) {
		$registration = $this->eventLookupFromPage->getRegistrationForPage( $old );
		if ( !$registration ) {
			return;
		}

		$newPageIdentity = $this->pageStore->getPageForLink( $new );
		$newEventPage = new MWPageProxy( $newPageIdentity, $this->titleFormatter->getPrefixedText( $newPageIdentity ) );
		$newRegistration = new ExistingEventRegistration(
			$registration->getID(),
			$this->titleFormatter->getText( $newPageIdentity ),
			$newEventPage,
			$registration->getChatURL(),
			$registration->getTrackingToolID(),
			$registration->getTrackingToolEventID(),
			$registration->getStatus(),
			$registration->getTimezone(),
			$registration->getStartLocalTimestamp(),
			$registration->getEndLocalTimestamp(),
			$registration->getType(),
			$registration->getMeetingType(),
			$registration->getMeetingURL(),
			$registration->getMeetingCountry(),
			$registration->getMeetingAddress(),
			$registration->getCreationTimestamp(),
			$registration->getLastEditTimestamp(),
			$registration->getDeletionTimestamp()
		);

		$this->eventStore->saveRegistration( $newRegistration );
	}
}
