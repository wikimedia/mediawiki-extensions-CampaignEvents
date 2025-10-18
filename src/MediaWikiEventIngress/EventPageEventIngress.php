<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MediaWikiEventIngress;

use MediaWiki\DomainEvent\DomainEventIngress;
use MediaWiki\Extension\CampaignEvents\Event\DeleteEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\PageEventLookup;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventStore;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFactory;
use MediaWiki\Page\Event\PageDeletedEvent;
use MediaWiki\Page\Event\PageDeletedListener;
use MediaWiki\Page\Event\PageMovedEvent;
use MediaWiki\Page\Event\PageMovedListener;
use MediaWiki\Title\TitleFormatter;
use Wikimedia\Rdbms\IDBAccessObject;

class EventPageEventIngress extends DomainEventIngress implements
	PageDeletedListener,
	PageMovedListener
{
	public function __construct(
		private readonly CampaignsPageFactory $campaignsPageFactory,
		private readonly DeleteEventCommand $deleteEventCommand,
		private readonly IEventStore $eventStore,
		private readonly PageEventLookup $pageEventLookup,
		private readonly TitleFormatter $titleFormatter,
	) {
	}

	public function handlePageMovedEvent( PageMovedEvent $event ): void {
		$registration = $this->pageEventLookup->getRegistrationForLocalPage(
			$event->getPageRecordBefore(),
			PageEventLookup::GET_DIRECT,
			IDBAccessObject::READ_LATEST
		);
		if ( !$registration ) {
			return;
		}
		$newEventPage = $this->campaignsPageFactory->newFromLocalMediaWikiPage( $event->getPageRecordAfter() );
		$newRegistration = new ExistingEventRegistration(
			$registration->getID(),
			$this->titleFormatter->getText( $event->getPageRecordAfter() ),
			$newEventPage,
			$registration->getStatus(),
			$registration->getTimezone(),
			$registration->getStartLocalTimestamp(),
			$registration->getEndLocalTimestamp(),
			$registration->getTypes(),
			$registration->getWikis(),
			$registration->getTopics(),
			$registration->getParticipationOptions(),
			$registration->getMeetingURL(),
			$registration->getAddress(),
			$registration->hasContributionTracking(),
			$registration->getTrackingTools(),
			$registration->getChatURL(),
			$registration->getIsTestEvent(),
			$registration->getParticipantQuestions(),
			$registration->getCreationTimestamp(),
			$registration->getLastEditTimestamp(),
			$registration->getDeletionTimestamp()
		);

		$this->eventStore->saveRegistration( $newRegistration );
	}

	public function handlePageDeletedEvent( PageDeletedEvent $event ): void {
		$registration = $this->pageEventLookup->getRegistrationForLocalPage(
			$event->getDeletedPage(),
			PageEventLookup::GET_DIRECT,
			IDBAccessObject::READ_LATEST
		);
		if ( !$registration || $registration->getDeletionTimestamp() !== null ) {
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
}
