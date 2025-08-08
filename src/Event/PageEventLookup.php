<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Event;

use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventNotFoundException;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFactory;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWPageProxy;
use MediaWiki\Extension\Translate\PageTranslation\TranslatablePage;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Title\TitleFactory;
use Wikimedia\Rdbms\IDBAccessObject;

/**
 * This class is responsible for finding the event registration associated with a given page. This includes
 * canonicalizing the page, in case the same event is associated with multiple pages.
 */
class PageEventLookup {
	public const SERVICE_NAME = 'CampaignEventsPageEventLookup';

	public const GET_CANONICALIZE = 'canonicalize';
	public const GET_DIRECT = 'direct';

	private IEventLookup $eventLookup;
	private CampaignsPageFactory $campaignsPageFactory;
	private TitleFactory $titleFactory;
	private bool $isTranslateExtensionInstalled;

	public function __construct(
		IEventLookup $eventLookup,
		CampaignsPageFactory $campaignsPageFactory,
		TitleFactory $titleFactory,
		bool $isTranslateExtensionInstalled
	) {
		$this->eventLookup = $eventLookup;
		$this->campaignsPageFactory = $campaignsPageFactory;
		$this->titleFactory = $titleFactory;
		$this->isTranslateExtensionInstalled = $isTranslateExtensionInstalled;
	}

	/**
	 * @param PageIdentity|LinkTarget $page
	 * @param string $canonicalize self::GET_CANONICALIZE to canonicalize the given page, or self::GET_DIRECT to
	 * avoid canonicalization.
	 * @param int $readFlags One of the IDBAccessObject::READ_* constants
	 */
	public function getRegistrationForLocalPage(
		LinkTarget|PageIdentity $page,
		string $canonicalize = self::GET_CANONICALIZE,
		int $readFlags = IDBAccessObject::READ_NORMAL
	): ?ExistingEventRegistration {
		if ( $canonicalize === self::GET_CANONICALIZE ) {
			$page = $this->getCanonicalPage( $page );
		}

		$campaignsPage = $this->campaignsPageFactory->newFromLocalMediaWikiPage( $page );
		try {
			return $this->eventLookup->getEventByPage( $campaignsPage, $readFlags );
		} catch ( EventNotFoundException $_ ) {
			return null;
		}
	}

	/**
	 * @param MWPageProxy $page
	 * @param string $canonicalize self::GET_CANONICALIZE to canonicalize the given page, or self::GET_DIRECT to
	 * avoid canonicalization.
	 */
	public function getRegistrationForPage(
		MWPageProxy $page,
		string $canonicalize = self::GET_CANONICALIZE
	): ?ExistingEventRegistration {
		if ( $canonicalize === self::GET_CANONICALIZE ) {
			$pageIdentity = $page->getPageIdentity();
			$canonicalPageIdentity = $this->getCanonicalPage( $pageIdentity );
			if ( $canonicalPageIdentity !== $pageIdentity ) {
				$page = $this->campaignsPageFactory->newFromLocalMediaWikiPage( $canonicalPageIdentity );
			}
		}

		try {
			return $this->eventLookup->getEventByPage( $page );
		} catch ( EventNotFoundException $_ ) {
			return null;
		}
	}

	private function getCanonicalPage( LinkTarget|PageIdentity $page ): PageIdentity {
		// XXX: Can't canonicalize foreign pages.
		if ( $this->isTranslateExtensionInstalled && $page->getWikiId() === WikiAwareEntity::LOCAL ) {
			$title = $page instanceof PageIdentity
				? $this->titleFactory->newFromPageIdentity( $page )
				: $this->titleFactory->newFromLinkTarget( $page );
			$transPage = TranslatablePage::isTranslationPage( $title );
			if ( $transPage ) {
				// If this is a translation subpage, look up the source page instead (T357716)
				return $transPage->getPageIdentity();
			}
		}
		return $page;
	}
}
