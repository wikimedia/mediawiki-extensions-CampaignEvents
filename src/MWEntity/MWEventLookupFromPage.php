<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

use IDBAccessObject;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventNotFoundException;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Page\PageIdentity;

/**
 * This class is a MediaWiki-specific registration lookup that works on wikipage objects and simplifies the interaction
 * between MW-specific code (e.g., hook handlers) and IEventLookup.
 */
class MWEventLookupFromPage {
	public const SERVICE_NAME = 'CampaignEventsMWEventLookupFromPage';

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
}
