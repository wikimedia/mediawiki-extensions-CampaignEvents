<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Hooks\Handlers;

use IDBAccessObject;
use ManualLogEntry;
use MediaWiki\Extension\CampaignEvents\Event\DeleteEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\PageEventLookup;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventStore;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFactory;
use MediaWiki\Hook\PageMoveCompleteHook;
use MediaWiki\Hook\TitleMoveHook;
use MediaWiki\Page\Hook\PageDeleteCompleteHook;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Permissions\Authority;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Status\Status;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFormatter;
use MediaWiki\User\User;

/**
 * This handler is used for page move and deletion. If the page is an event page, we update the registration:
 *  - Delete it in case of event page deletion
 *  - Make it point to the new page in case of page move
 */
class PageMoveAndDeleteHandler implements PageMoveCompleteHook, PageDeleteCompleteHook, TitleMoveHook {
	private PageEventLookup $pageEventLookup;
	private IEventStore $eventStore;
	private DeleteEventCommand $deleteEventCommand;
	private TitleFormatter $titleFormatter;
	private CampaignsPageFactory $campaignsPageFactory;

	/**
	 * @param PageEventLookup $pageEventLookup
	 * @param IEventStore $eventStore
	 * @param DeleteEventCommand $deleteEventCommand
	 * @param TitleFormatter $titleFormatter
	 * @param CampaignsPageFactory $campaignsPageFactory
	 */
	public function __construct(
		PageEventLookup $pageEventLookup,
		IEventStore $eventStore,
		DeleteEventCommand $deleteEventCommand,
		TitleFormatter $titleFormatter,
		CampaignsPageFactory $campaignsPageFactory
	) {
		$this->pageEventLookup = $pageEventLookup;
		$this->eventStore = $eventStore;
		$this->deleteEventCommand = $deleteEventCommand;
		$this->titleFormatter = $titleFormatter;
		$this->campaignsPageFactory = $campaignsPageFactory;
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
		$registration = $this->pageEventLookup->getRegistrationForLocalPage( $page, IDBAccessObject::READ_LATEST );
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
	public function onTitleMove( Title $old, Title $nt, User $user, $reason, Status &$status ) {
		$registration = $this->pageEventLookup->getRegistrationForLocalPage( $old );
		// Disallow moving event pages with registration enabled outside of the Event namespace, see T358704.
		// This will change if we decide to allow event registration outside of the namespace (T318179).
		if ( $registration && !$nt->inNamespace( NS_EVENT ) ) {
			$status->fatal( 'campaignevents-error-move-eventpage-namespace' );
			return false;
		}
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function onPageMoveComplete( $old, $new, $user, $pageid, $redirid, $reason, $revision ) {
		// This code runs in a DeferredUpdate, load the data from DB master (T302858#8354617)
		$registration = $this->pageEventLookup->getRegistrationForLocalPage( $old, IDBAccessObject::READ_LATEST );
		if ( !$registration ) {
			return;
		}

		$newEventPage = $this->campaignsPageFactory->newFromLocalMediaWikiPage( $new );
		$newRegistration = new ExistingEventRegistration(
			$registration->getID(),
			$this->titleFormatter->getText( $new ),
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
