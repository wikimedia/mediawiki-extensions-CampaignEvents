<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents;

use DateTime;
use DateTimeZone;
use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Participants\Participant;
use MediaWiki\Extension\CampaignEvents\Questions\EventAggregatedAnswersStore;
use MediaWiki\Permissions\Authority;
use MediaWiki\User\UserTimeCorrection;
use MediaWiki\Utils\MWTimestamp;
use MediaWiki\WikiMap\WikiMap;

/**
 * Simple utility methods.
 */
class Utils {
	public const VIRTUAL_DB_DOMAIN = 'virtual-campaignevents';

	/**
	 * @param string|false $wikiID
	 */
	public static function getWikiIDString( string|bool $wikiID ): string {
		return $wikiID !== WikiAwareEntity::LOCAL ? $wikiID : WikiMap::getCurrentWikiId();
	}

	/**
	 * Guesses the direction of a string, e.g. an address, for use in the "dir" attribute.
	 *
	 * @return string Either 'ltr' or 'rtl'
	 */
	public static function guessStringDirection( string $address ): string {
		// Taken from https://stackoverflow.com/a/48918886/7369689
		$rtlRe = '/[\x{0590}-\x{083F}]|[\x{08A0}-\x{08FF}]|[\x{FB1D}-\x{FDFF}]|[\x{FE70}-\x{FEFF}]/u';
		return preg_match( $rtlRe, $address ) ? 'rtl' : 'ltr';
	}

	/**
	 * @internal
	 * Converts a DateTimeZone object into a UserTimeCorrection object.
	 * This logic could perhaps be moved to UserTimeCorrection in the future.
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

	/**
	 * Given a participant and an event registration, returns the timestamp when the answers of this participant should
	 * be aggregated. This method may return a timestamp in the past (e.g., if it's recent enough that we still haven't
	 * been able to aggregate the answers), but it will return null if the participant never answered any questions, or
	 * if their answers have already been aggregated. In particular, this means that if the answers have already been
	 * aggregated, the aggregation timestamp is ignored. This is motivated by the current UI, where participant whose
	 * answers have been aggregated are treated the same as those who never answered any question.
	 *
	 * @return string|null Timestamp in TS_UNIX format
	 */
	public static function getAnswerAggregationTimestamp(
		Participant $participant,
		ExistingEventRegistration $event
	): ?string {
		$firstAnswerTime = $participant->getFirstAnswerTimestamp();
		if ( $firstAnswerTime === null || $participant->getAggregationTimestamp() !== null ) {
			return null;
		}
		$participantAggregationTS = (int)$firstAnswerTime + EventAggregatedAnswersStore::ANSWERS_TTL_SEC;
		$eventAggregationTS = (int)MWTimestamp::convert( TS_UNIX, $event->getEndUTCTimestamp() );
		return (string)min( $participantAggregationTS, $eventAggregationTS );
	}

	/**
	 * Shortcut to check if the given performer is blocked and the block is sitewide. Could be inlined with a
	 * nullsafe call once we drop support for PHP 7.4.
	 */
	public static function isSitewideBlocked( Authority $performer ): bool {
		$block = $performer->getBlock();
		return $block && $block->isSitewide();
	}
}
