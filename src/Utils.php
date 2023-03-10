<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents;

use DateTime;
use DateTimeZone;
use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\User\UserTimeCorrection;
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

	/**
	 * @internal
	 * Converts a DateTimeZone object into a UserTimeCorrection object.
	 * This logic could perhaps be moved to UserTimeCorrection in the future.
	 *
	 * @param DateTimeZone $tz
	 * @return UserTimeCorrection
	 */
	public static function timezoneToUserTimeCorrection( DateTimeZone $tz ): UserTimeCorrection {
		// Timezones in PHP can be either a geographical zone ("Europe/Rome"), an offset ("+01:00"), or
		// an abbreviation ("GMT"). PHP provides no way to tell which format a timezone object uses.
		// DateTimeZone seems to have an internal timezone_type property but it's set magically and inaccessible.
		// Also, 'UTC' is surprisingly categorized as a geographical zone, and getLocation() does not return false
		// for it, but rather an array with incomplete data. PHP, WTF?!
		$timezoneName = $tz->getName();
		if ( strpos( $timezoneName, '/' ) !== false ) {
			// Geographical format, convert to the format used by UserTimeCorrection.
			$minDiff = floor( $tz->getOffset( new DateTime() ) / 60 );
			return new UserTimeCorrection( "ZoneInfo|$minDiff|$timezoneName" );
		}
		if ( preg_match( '/^[+-]\d{2}:\d{2}$/', $timezoneName ) ) {
			// Offset, which UserTimeCorrection accepts directly.
			return new UserTimeCorrection( $timezoneName );
		}
		// Non-geographical named zone. Convert to offset because UserTimeCorrection only accepts
		// the other two types. In theory, this conversion shouldn't change the absolute time and it should
		// not depend on DST, because abbreviations already contain information about DST (e.g., "PST" vs "PDT").
		// TODO This assumption may be false for some abbreviations, see T316688#8336443.

		// Work around PHP bug: all versions of PHP up to 7.4.x, 8.0.20 and 8.1.7 do not parse DST correctly for
		// time zone abbreviations, and PHP assumes that *all* abbreviations correspond to time zones without DST.
		// So we can't use DateTimeZone::getOffset(), and the timezone must also be specified inside the time string,
		// and not as second argument to DateTime::__construct. See https://bugs.php.net/bug.php?id=74671
		$randomTime = '2022-10-20 18:00:00 ' . $timezoneName;
		$offset = ( new DateTime( $randomTime ) )->format( 'P' );
		return new UserTimeCorrection( $offset );
	}
}
