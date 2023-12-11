<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Hooks\Handlers;

use ManualLogEntry;
use MediaWiki\Extension\CampaignEvents\Event\DeleteEventCommand;
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
use MediaWiki\Title\TitleFormatter;

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
	/** @var DeleteEventCommand */
	private DeleteEventCommand $deleteEventCommand;
	/** @var PageStore */
	private $pageStore;
	/** @var TitleFormatter */
	private $titleFormatter;

	/**
	 * @param MWEventLookupFromPage $eventLookupFromPage
	 * @param IEventStore $eventStore
	 * @param DeleteEventCommand $deleteEventCommand
	 * @param PageStore $pageStore
	 * @param TitleFormatter $titleFormatter
	 */
	public function __construct(
		MWEventLookupFromPage $eventLookupFromPage,
		IEventStore $eventStore,
		DeleteEventCommand $deleteEventCommand,
		PageStore $pageStore,
		TitleFormatter $titleFormatter
	) {
		$this->eventLookupFromPage = $eventLookupFromPage;
		$this->eventStore = $eventStore;
		$this->deleteEventCommand = $deleteEventCommand;
		$this->pageStore = $pageStore;
		$this->titleFormatter = $titleFormatter;
	}

	/**
	 * @inheritDoc
	 * @note We need to handle this hook, and not PageDeleteHook, to make sure that we only delete the event
	 * if the page deletion was successful. This //does// mean that we can't abort the page deletion from here.
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
		$registration = $this->eventLookupFromPage->getRegistrationForPage( $page, MWEventLookupFromPage::READ_LATEST );
		if ( !$registration ) {
			return;
		}
		$this->deleteEventCommand->deleteUnsafe(
			$registration,
			// Skip tracking tool validation, it's too late to report any error and we also don't want to prevent
			// a page from being deleted just for errors in an external tool that the deleting user likely has
			// no control over.
			DeleteEventCommand::SKIP_TRACKING_TOOL_VALIDATION
		);
	}

	/**
	 * @inheritDoc
	 */
	public function onPageMoveComplete( $old, $new, $user, $pageid, $redirid, $reason, $revision ) {
		// This code runs in a DeferredUpdate, load the data from DB master (T302858#8354617)
		$registration = $this->eventLookupFromPage->getRegistrationForPage( $old, MWEventLookupFromPage::READ_LATEST );
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
			$registration->getTrackingTools(),
			$registration->getStatus(),
			$registration->getTimezone(),
			$registration->getStartLocalTimestamp(),
			$registration->getEndLocalTimestamp(),
			$registration->getType(),
			$registration->getMeetingType(),
			$registration->getMeetingURL(),
			$registration->getMeetingCountry(),
			$registration->getMeetingAddress(),
			$registration->getParticipantQuestions(),
			$registration->getCreationTimestamp(),
			$registration->getLastEditTimestamp(),
			$registration->getDeletionTimestamp()
		);

		$this->eventStore->saveRegistration( $newRegistration );
	}
}
