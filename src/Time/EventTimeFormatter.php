<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Time;

use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Utils;
use MediaWiki\Language\Language;
use MediaWiki\User\Options\UserOptionsLookup;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserTimeCorrection;
use OOUI\HtmlSnippet;
use OOUI\Tag;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * This service formats the time of an event according to the event type, in a given language and for a given user.
 * The timezone used for the return value can be obtained separately.
 */
class EventTimeFormatter {
	public const SERVICE_NAME = 'CampaignEventsEventTimeFormatter';

	private UserOptionsLookup $userOptionsLookup;

	private const FORMAT_START = 'start';
	private const FORMAT_END = 'end';

	/**
	 * @param UserOptionsLookup $userOptionsLookup
	 */
	public function __construct( UserOptionsLookup $userOptionsLookup ) {
		$this->userOptionsLookup = $userOptionsLookup;
	}

	/**
	 * @param EventRegistration $event
	 * @param Language $language
	 * @param UserIdentity $user
	 * @return FormattedTime
	 */
	public function formatStart(
		EventRegistration $event,
		Language $language,
		UserIdentity $user
	): FormattedTime {
		return $this->formatTimeInternal( self::FORMAT_START, $event, $language, $user );
	}

	/**
	 * @param EventRegistration $event
	 * @param Language $language
	 * @param UserIdentity $user
	 * @return FormattedTime
	 */
	public function formatEnd(
		EventRegistration $event,
		Language $language,
		UserIdentity $user
	): FormattedTime {
		return $this->formatTimeInternal( self::FORMAT_END, $event, $language, $user );
	}

	/**
	 * @param string $type self::FORMAT_START or self::FORMAT_END
	 * @param EventRegistration $event
	 * @param Language $language
	 * @param UserIdentity $user
	 * @return FormattedTime
	 */
	private function formatTimeInternal(
		string $type,
		EventRegistration $event,
		Language $language,
		UserIdentity $user
	): FormattedTime {
		$formatOptions = [ 'timecorrection' => $this->getTimeCorrection( $event, $user )->toString() ];
		$utcTimestamp = $type === self::FORMAT_START ? $event->getStartUTCTimestamp() : $event->getEndUTCTimestamp();
		return new FormattedTime(
			$language->userTime( $utcTimestamp, $user, $formatOptions ),
			$language->userDate( $utcTimestamp, $user, $formatOptions ),
			$language->userTimeAndDate( $utcTimestamp, $user, $formatOptions )
		);
	}

	/**
	 * @param EventRegistration $eventRegistration
	 * @param UserIdentity $user
	 * @return string
	 */
	public function formatTimezone( EventRegistration $eventRegistration, UserIdentity $user ): string {
		$userTimeCorrection = $this->getTimeCorrection( $eventRegistration, $user );
		$tzObj = $userTimeCorrection->getTimeZone();
		if ( $tzObj ) {
			return $tzObj->getName();
		}
		return UserTimeCorrection::formatTimezoneOffset( $userTimeCorrection->getTimeOffset() );
	}

	/**
	 * Returns the time correction that should be used when formatting time, date, and timezone.
	 * This uses the event timezone for in-person events, and the user preference for online and hybrid events,
	 * see T316688.
	 *
	 * @param EventRegistration $event
	 * @param UserIdentity $user
	 * @return UserTimeCorrection
	 */
	private function getTimeCorrection( EventRegistration $event, UserIdentity $user ): UserTimeCorrection {
		if ( $event->getMeetingType() === EventRegistration::MEETING_TYPE_IN_PERSON ) {
			return Utils::timezoneToUserTimeCorrection( $event->getTimezone() );
		}
		$timeCorrectionPref = $this->userOptionsLookup->getOption( $user, 'timecorrection' ) ?? '';
		return new UserTimeCorrection( $timeCorrectionPref );
	}

	/**
	 * Wrap a time range in an HTML structure that can be read by the TimeZoneConverter JavaScript utility.
	 * The timezone must also be wrapped, using {@see self::wrapTimeZoneForConversion}.
	 */
	public static function wrapRangeForConversion( EventRegistration $event, string $range ): Tag {
		return ( new Tag( 'span' ) )
			->addClasses( [ 'ext-campaignevents-time-range' ] )
			->setAttributes( [
				'data-mw-start' => ConvertibleTimestamp::convert( TS_ISO_8601, $event->getStartUTCTimestamp() ),
				'data-mw-end' => ConvertibleTimestamp::convert( TS_ISO_8601, $event->getEndUTCTimestamp() ),
			] )
			->appendContent( $range );
	}

	/**
	 * Wrap a timezone name in an HTML structure that can be read by the TimeZoneConverter JavaScript utility.
	 * The time range must also be wrapped, using {@see self::wrapRangeForConversion}.
	 */
	public static function wrapTimeZoneForConversion( string $timezone ): Tag {
		return ( new Tag( 'span' ) )
			->addClasses( [ 'ext-campaignevents-timezone' ] )
			->appendContent( new HtmlSnippet( $timezone ) );
	}
}
