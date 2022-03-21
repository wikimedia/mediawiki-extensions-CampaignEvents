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

	/**
	 * Guesses the direction of a string, e.g. an address, for use in the "dir" attribute.
	 *
	 * @param string $address
	 * @return string Either 'ltr' or 'rtl'
	 */
	public static function guessStringDirection( string $address ): string {
		// Taken from https://stackoverflow.com/a/48918886/7369689
		// TODO: There should really be a nicer way to do this.
		$rtlRe = '/[\x{0590}-\x{083F}]|[\x{08A0}-\x{08FF}]|[\x{FB1D}-\x{FDFF}]|[\x{FE70}-\x{FEFF}]/u';
		return preg_match( $rtlRe, $address ) ? 'rtl' : 'ltr';
	}
}
