<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Hooks\Handlers;

use MediaWiki\Extension\CampaignEvents\Event\DeleteEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventStore;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWEventLookupFromPage;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWPageProxy;
use MediaWiki\Hook\PageMoveCompleteHook;
use MediaWiki\Page\Hook\PageDeleteHook;
use MediaWiki\Page\PageStore;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Permissions\Authority;
use StatusValue;
use TitleFormatter;

/**
 * This handler is used for page move and deletion. If the page is an event page, we update the registration:
 *  - Delete it in case of event page deletion
 *  - Make it point to the new page in case of page move
 */
class PageMoveAndDeleteHandler implements PageMoveCompleteHook, PageDeleteHook {
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
	 */
	public function onPageDelete(
		ProperPageIdentity $page,
		Authority $deleter,
		string $reason,
		StatusValue $status,
		bool $suppress
	) {
		$registration = $this->eventLookupFromPage->getRegistrationForPage( $page );
		if ( !$registration ) {
			return true;
		}
		$res = $this->deleteEventCommand->deleteUnsafe( $registration );
		if ( !$res->isGood() ) {
			$status->merge( $res );
			return false;
		}
		return true;
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
			$registration->getCreationTimestamp(),
			$registration->getLastEditTimestamp(),
			$registration->getDeletionTimestamp()
		);

		$this->eventStore->saveRegistration( $newRegistration );
	}
}
