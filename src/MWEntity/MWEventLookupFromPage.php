<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventNotFoundException;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Page\PageLookup;
use TitleFormatter;

/**
 * This class is a MediaWiki-specific registration lookup that works on wikipage objects and simplifies the interaction
 * between MW-specific code (e.g., hook handlers) and IEventLookup.
 */
class MWEventLookupFromPage {
	public const SERVICE_NAME = 'CampaignEventsMWEventLookupFromPage';

	/** @var IEventLookup */
	private $eventLookup;
	/** @var PageLookup */
	private $pageLookup;
	/** @var TitleFormatter */
	private $titleFormatter;

	/**
	 * @param IEventLookup $eventLookup
	 * @param PageLookup $pageLookup
	 * @param TitleFormatter $titleFormatter
	 */
	public function __construct( IEventLookup $eventLookup, PageLookup $pageLookup, TitleFormatter $titleFormatter ) {
		$this->eventLookup = $eventLookup;
		$this->pageLookup = $pageLookup;
		$this->titleFormatter = $titleFormatter;
	}

	/**
	 * @param PageIdentity|LinkTarget $page
	 * @return ExistingEventRegistration|null
	 */
	public function getRegistrationForPage( $page ): ?ExistingEventRegistration {
		if ( $page->getNamespace() !== NS_EVENT ) {
			return null;
		}

		if ( $page instanceof LinkTarget ) {
			$pageIdentity = $this->pageLookup->getPageForLink( $page );
		} else {
			$pageIdentity = $page;
		}

		$campaignsPage = new MWPageProxy( $pageIdentity, $this->titleFormatter->getPrefixedText( $page ) );
		try {
			return $this->eventLookup->getEventByPage( $campaignsPage );
		} catch ( EventNotFoundException $_ ) {
			return null;
		}
	}
}
