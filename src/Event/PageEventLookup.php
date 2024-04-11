<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Event;

use IDBAccessObject;
use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventNotFoundException;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFactory;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsPage;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWPageProxy;
use MediaWiki\Extension\Translate\PageTranslation\TranslatablePage;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Title\TitleFactory;
use RuntimeException;

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

	/**
	 * @param IEventLookup $eventLookup
	 * @param CampaignsPageFactory $campaignsPageFactory
	 * @param TitleFactory $titleFactory
	 * @param bool $isTranslateExtensionInstalled
	 */
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
	 * @return ExistingEventRegistration|null
	 */
	public function getRegistrationForLocalPage(
		$page,
		string $canonicalize = self::GET_CANONICALIZE,
		int $readFlags = IDBAccessObject::READ_NORMAL
	): ?ExistingEventRegistration {
		if ( $canonicalize === self::GET_CANONICALIZE ) {
			$page = $this->getCanonicalPage( $page );
		}

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

	/**
	 * @param ICampaignsPage $page
	 * @param string $canonicalize self::GET_CANONICALIZE to canonicalize the given page, or self::GET_DIRECT to
	 * avoid canonicalization.
	 * @return ExistingEventRegistration|null
	 */
	public function getRegistrationForPage(
		ICampaignsPage $page,
		string $canonicalize = self::GET_CANONICALIZE
	): ?ExistingEventRegistration {
		if ( !$page instanceof MWPageProxy ) {
			throw new RuntimeException( 'Unexpected ICampaignsPage implementation.' );
		}

		if ( $canonicalize === self::GET_CANONICALIZE ) {
			$pageIdentity = $page->getPageIdentity();
			$canonicalPageIdentity = $this->getCanonicalPage( $pageIdentity );
			if ( $canonicalPageIdentity !== $pageIdentity ) {
				$page = $this->campaignsPageFactory->newFromLocalMediaWikiPage( $canonicalPageIdentity );
			}
		}

		if ( $page->getNamespace() !== NS_EVENT ) {
			return null;
		}

		try {
			return $this->eventLookup->getEventByPage( $page );
		} catch ( EventNotFoundException $_ ) {
			return null;
		}
	}

	/**
	 * @param PageIdentity|LinkTarget $page
	 * @return PageIdentity
	 */
	private function getCanonicalPage( $page ): PageIdentity {
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
