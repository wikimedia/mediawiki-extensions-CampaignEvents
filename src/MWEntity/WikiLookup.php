<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

use MediaWiki\Config\SiteConfiguration;
use MessageLocalizer;
use Wikimedia\ObjectCache\WANObjectCache;

/**
 * This service can be used to obtain a list of valid wiki IDs in the current wiki family, and localized names
 * for them when available.
 */
class WikiLookup {
	public const SERVICE_NAME = 'CampaignEventsWikiLookup';

	private SiteConfiguration $siteConfig;
	private WANObjectCache $cache;
	private MessageLocalizer $messageLocalizer;

	public function __construct(
		SiteConfiguration $siteConfig,
		WANObjectCache $cache,
		MessageLocalizer $messageLocalizer
	) {
		$this->siteConfig = $siteConfig;
		$this->cache = $cache;
		$this->messageLocalizer = $messageLocalizer;
	}

	public function getAllWikis(): array {
		return array_values( array_unique( $this->siteConfig->getLocalDatabases() ) );
	}

	public function getListForSelect(): array {
		return $this->cache->getWithSetCallback(
			$this->cache->makeGlobalKey( 'CampaignEvents-WikiList' ),
			WANObjectCache::TTL_HOUR,
			fn () => $this->computeListForSelect()
		);
	}

	private function computeListForSelect(): array {
		return $this->getLocalizedNames( $this->getAllWikis() );
	}

	public function getLocalizedNames( array $wikiIDs ): array {
		$ret = [];
		foreach ( $wikiIDs as $dbname ) {
			// Do not check the database, or this would become really expensive when done for many wikis.
			// The `project-localized-name-*` messages are defined in the WikimediaMessages extension for WMF wikis.
			$localizedNameMsg = $this->messageLocalizer->msg( 'project-localized-name-' . $dbname )
				->useDatabase( false );
			$ret[$dbname] = $localizedNameMsg->exists() ? $localizedNameMsg->text() : $dbname;
		}
		return $ret;
	}
}
