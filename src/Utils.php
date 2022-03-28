<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents;

use MediaWiki\DAO\WikiAwareEntity;
use WikiMap;

/**
 * Simple utility methods.
 */
class Utils {
	/**
	 * @param string|false $wikiID
	 * @return string
	 */
	public static function getWikiIDString( $wikiID ): string {
		return $wikiID !== WikiAwareEntity::LOCAL ? $wikiID : WikiMap::getCurrentWikiId();
	}
}
