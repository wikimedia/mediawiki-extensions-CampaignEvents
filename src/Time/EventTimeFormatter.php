<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Time;

use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Utils;
use MediaWiki\Language\Language;
use MediaWiki\User\Options\UserOptionsLookup;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserTimeCorrection;

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
}
