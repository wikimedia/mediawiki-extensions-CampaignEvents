<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

use MediaWiki\Config\SiteConfiguration;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
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

	/**
	 * @return array<string,string> That maps localized names to wiki IDs, suitable for use
	 * in multiselect widgets.
	 */
	public function getListForSelect(): array {
		return $this->cache->getWithSetCallback(
			$this->cache->makeGlobalKey( 'CampaignEvents-WikiList' ),
			WANObjectCache::TTL_HOUR,
			fn () => $this->computeListForSelect()
		);
	}

	private function computeListForSelect(): array {
		return array_flip( $this->getLocalizedNames( $this->getAllWikis() ) );
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

	/**
	 * This code will not behave as expected outside of production, if we require something more
	 * robust, we can steal the sitematrix implementation which uses values from $wgConf
	 * @param string[]|true $wikiIDs
	 * @return string
	 */
	public function getWikiIcon( $wikiIDs ): string {
		$defaultIcon = 'logoWikimedia';
		if ( $wikiIDs === EventRegistration::ALL_WIKIS ) {
			return $defaultIcon;
		}
		// The 'wiki' is order sensitive as it is a final fall-through case
		$wikiIcons = [
			'commonswiki' => 'logoWikimediaCommons',
			'metawiki' => 'logoMetaWiki',
			'wikibooks' => 'logoWikibooks',
			'wikidatawiki' => 'logoWikidata',
			'wikifunctionswiki' => 'logoWikifunctions',
			'wikinews' => 'logoWikinews',
			'wikiquote' => 'logoWikiquote',
			'wikisource' => 'logoWikisource',
			'specieswiki' => 'logoWikispecies',
			'wikiversity' => 'logoWikiversity',
			'wikivoyage' => 'logoWikivoyage',
			'wiktionary' => 'logoWiktionary',
			'officewiki' => 'logoWikimedia',
			'mediawikiwiki' => 'logoMediaWiki',
			'wiki' => 'logoWikipedia',
		];
		$matchedSuffixes = [];
		foreach ( $wikiIDs as $dbname ) {
			foreach ( array_keys( $wikiIcons ) as $suffix ) {
				if ( str_ends_with( $dbname, $suffix ) ) {
					$matchedSuffixes[$suffix] = true;
					break;
				}
			}
		}
		if ( count( $matchedSuffixes ) !== 1 ) {
			return $defaultIcon;
		}
		$foundSuffix = key( $matchedSuffixes );
		return $wikiIcons[$foundSuffix];
	}
}