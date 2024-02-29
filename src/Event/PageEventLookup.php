<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Event;

use IDBAccessObject;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventNotFoundException;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFactory;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsPage;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Page\PageIdentity;

/**
 * This class is responsible for finding the event registration associated with a given page. This includes
 * canonicalizing the page, in case the same event is associated with multiple pages.
 */
class PageEventLookup {
	public const SERVICE_NAME = 'CampaignEventsPageEventLookup';

	private IEventLookup $eventLookup;
	private CampaignsPageFactory $campaignsPageFactory;

	/**
	 * @param IEventLookup $eventLookup
	 * @param CampaignsPageFactory $campaignsPageFactory
	 */
	public function __construct(
		IEventLookup $eventLookup,
		CampaignsPageFactory $campaignsPageFactory
	) {
		$this->eventLookup = $eventLookup;
		$this->campaignsPageFactory = $campaignsPageFactory;
	}

	/**
	 * @param PageIdentity|LinkTarget $page
	 * @param int $readFlags One of the IDBAccessObject::READ_* constants
	 * @return ExistingEventRegistration|null
	 */
	public function getRegistrationForLocalPage(
		$page,
		int $readFlags = IDBAccessObject::READ_NORMAL
	): ?ExistingEventRegistration {
		if ( $page->getNamespace() !== NS_EVENT ) {
			return null;
		}

		$campaignsPage = $this->campaignsPageFactory->newFromLocalMediaWikiPage( $page );
		try {
			return $this->eventLookup->getEventByPage( $campaignsPage, $readFlags );
		} catch ( EventNotFoundException $_ ) {
			return null;
		}
	}

	public function getRegistrationForPage( ICampaignsPage $page ): ?ExistingEventRegistration {
		if ( $page->getNamespace() !== NS_EVENT ) {
			return null;
		}

		try {
			return $this->eventLookup->getEventByPage( $page );
		} catch ( EventNotFoundException $_ ) {
			return null;
		}
	}
}
