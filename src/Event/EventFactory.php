<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Event;

use DateTime;
use DateTimeZone;
use Exception;
use InvalidArgumentException;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFactory;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFormatter;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsPage;
use MediaWiki\Extension\CampaignEvents\MWEntity\InvalidTitleStringException;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageNotFoundException;
use MediaWiki\Extension\CampaignEvents\MWEntity\UnexpectedInterwikiException;
use MediaWiki\Extension\CampaignEvents\MWEntity\UnexpectedSectionAnchorException;
use MediaWiki\Extension\CampaignEvents\MWEntity\UnexpectedVirtualNamespaceException;
use MediaWiki\Extension\CampaignEvents\TrackingTool\ToolNotFoundException;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolRegistry;
use MWTimestamp;
use StatusValue;
use Wikimedia\RequestTimeout\TimeoutException;

class EventFactory {
	public const SERVICE_NAME = 'CampaignEventsEventFactory';
	public const VALIDATE_ALL = 0;
	public const VALIDATE_SKIP_DATES_PAST = 1 << 0;

	/** @var CampaignsPageFactory */
	private $campaignsPageFactory;
	/** @var CampaignsPageFormatter */
	private $campaignsPageFormatter;
	/** @var TrackingToolRegistry */
	private $trackingToolRegistry;

	/**
	 * @param CampaignsPageFactory $campaignsPageFactory
	 * @param CampaignsPageFormatter $campaignsPageFormatter
	 * @param TrackingToolRegistry $trackingToolRegistry
	 */
	public function __construct(
		CampaignsPageFactory $campaignsPageFactory,
		CampaignsPageFormatter $campaignsPageFormatter,
		TrackingToolRegistry $trackingToolRegistry
	) {
		$this->campaignsPageFactory = $campaignsPageFactory;
		$this->campaignsPageFormatter = $campaignsPageFormatter;
		$this->trackingToolRegistry = $trackingToolRegistry;
	}

	/**
	 * Creates a new event registration entity, making sure that the given data is valid and formatted as expected.
	 *
	 * @param int|null $id
	 * @param string $pageTitleStr
	 * @param string|null $chatURL
	 * @param string|null $trackingToolUserID User identifier of a tracking tool
	 * @param string|null $trackingToolEventID
	 * @param string $status
	 * @param string $timezone Can be in any format accepted by DateTimeZone
	 * @param string $startLocalTimestamp In the TS_MW format
	 * @param string $endLocalTimestamp In the TS_MW format
	 * @param string $type
	 * @param int $meetingType
	 * @param string|null $meetingURL
	 * @param string|null $meetingCountry
	 * @param string|null $meetingAddress
	 * @param string|null $creationTimestamp In the TS_MW format
	 * @param string|null $lastEditTimestamp In the TS_MW format
	 * @param string|null $deletionTimestamp In the TS_MW format
	 * @param int $validationFlags
	 * @return EventRegistration
	 * @throws InvalidEventDataException
	 */
	public function newEvent(
		?int $id,
		string $pageTitleStr,
		?string $chatURL,
		?string $trackingToolUserID,
		?string $trackingToolEventID,
		string $status,
		string $timezone,
		string $startLocalTimestamp,
		string $endLocalTimestamp,
		string $type,
		int $meetingType,
		?string $meetingURL,
		?string $meetingCountry,
		?string $meetingAddress,
		?string $creationTimestamp,
		?string $lastEditTimestamp,
		?string $deletionTimestamp,
		int $validationFlags = self::VALIDATE_ALL
	): EventRegistration {
		$res = StatusValue::newGood();

		if ( $id !== null && $id <= 0 ) {
			$res->error( 'campaignevents-error-invalid-id' );
		}

		$pageStatus = $this->validatePage( $pageTitleStr );
		$res->merge( $pageStatus );
		$campaignsPage = $pageStatus->getValue();

		if ( $chatURL !== null ) {
			$chatURL = trim( $chatURL );
			if ( !$this->isValidURL( $chatURL ) ) {
				$res->error( 'campaignevents-error-invalid-chat-url' );
			}
		}

		$trackingToolStatus = $this->validateTrackingTool( $trackingToolUserID, $trackingToolEventID );
		$res->merge( $trackingToolStatus );
		$trackingToolDBID = $trackingToolStatus->getValue();

		if ( !in_array( $status, EventRegistration::VALID_STATUSES, true ) ) {
			$res->error( 'campaignevents-error-invalid-status' );
		}

		$timezoneStatus = $this->validateTimezone( $timezone );
		$res->merge( $timezoneStatus );
		$timezoneObj = $timezoneStatus->isGood() ? $timezoneStatus->getValue() : null;
		if ( $timezoneObj ) {
			$datesStatus = $this->validateLocalDates(
				$validationFlags,
				$timezoneObj,
				$startLocalTimestamp,
				$endLocalTimestamp
			);
			$res->merge( $datesStatus );
		}

		if ( !in_array( $type, EventRegistration::VALID_TYPES, true ) ) {
			$res->error( 'campaignevents-error-invalid-type' );
		}

		$res->merge( $this->validateMeetingInfo( $meetingType, $meetingURL, $meetingCountry, $meetingAddress ) );

		$creationTSUnix = wfTimestampOrNull( TS_UNIX, $creationTimestamp );
		$lastEditTSUnix = wfTimestampOrNull( TS_UNIX, $lastEditTimestamp );
		$deletionTSUnix = wfTimestampOrNull( TS_UNIX, $deletionTimestamp );
		// Creation, last edit, and deletion timestamp don't need user-facing validation since it's not the
		// user setting them.
		$invalidTimestamps = array_filter(
			[ 'creation' => $creationTSUnix, 'lastedit' => $lastEditTSUnix, 'deletion' => $deletionTSUnix ],
			static function ( $ts ): bool {
				return $ts === false;
			}
		);
		if ( $invalidTimestamps ) {
			throw new InvalidArgumentException(
				"Invalid timestamps: " . implode( ', ', array_keys( $invalidTimestamps ) )
			);
		}

		if ( !$res->isGood() ) {
			throw new InvalidEventDataException( $res );
		}

		/** @var ICampaignsPage $campaignsPage */
		'@phan-var ICampaignsPage $campaignsPage';

		return new EventRegistration(
			$id,
			$this->campaignsPageFormatter->getText( $campaignsPage ),
			$campaignsPage,
			$chatURL,
			$trackingToolDBID,
			$trackingToolEventID,
			$status,
			$timezoneObj,
			$startLocalTimestamp,
			$endLocalTimestamp,
			$type,
			$meetingType,
			$meetingURL,
			$meetingCountry,
			$meetingAddress,
			$creationTSUnix,
			$lastEditTSUnix,
			$deletionTSUnix
		);
	}

	/**
	 * Validates the page title provided as a string.
	 *
	 * @param string $pageTitleStr
	 * @return StatusValue Fatal if invalid, good otherwise and with an ICampaignsPage as value.
	 */
	private function validatePage( string $pageTitleStr ): StatusValue {
		$pageTitleStr = trim( $pageTitleStr );
		if ( $pageTitleStr === '' ) {
			return StatusValue::newFatal( 'campaignevents-error-empty-title' );
		}

		try {
			$campaignsPage = $this->campaignsPageFactory->newLocalExistingPageFromString( $pageTitleStr );
		} catch ( InvalidTitleStringException $e ) {
			// TODO: Ideally we wouldn't need wfMessage here.
			return StatusValue::newFatal(
				'campaignevents-error-invalid-title',
				wfMessage( $e->getErrorMsgKey(), $e->getErrorMsgParams() )
			);
		} catch ( UnexpectedInterwikiException $_ ) {
			return StatusValue::newFatal( 'campaignevents-error-invalid-title-interwiki' );
		} catch ( UnexpectedVirtualNamespaceException $_ ) {
			return StatusValue::newFatal( 'campaignevents-error-page-not-event-namespace' );
		} catch ( UnexpectedSectionAnchorException $_ ) {
			return StatusValue::newFatal( 'campaignevents-error-page-with-section' );
		} catch ( PageNotFoundException $_ ) {
			return StatusValue::newFatal( 'campaignevents-error-page-not-found' );
		}

		if ( $campaignsPage->getNamespace() !== NS_EVENT ) {
			return StatusValue::newFatal( 'campaignevents-error-page-not-event-namespace' );
		}

		return StatusValue::newGood( $campaignsPage );
	}

	/**
	 * @param string|null $trackingToolUserID
	 * @param string|null $trackingToolEventID
	 * @return StatusValue If good, has the tracking tool DB ID as value, or null if no tool was specified.
	 */
	private function validateTrackingTool( ?string $trackingToolUserID, ?string $trackingToolEventID ): StatusValue {
		if ( $trackingToolUserID === null || $trackingToolEventID === null ) {
			if ( $trackingToolUserID !== null && $trackingToolEventID === null ) {
				return StatusValue::newFatal( 'campaignevents-error-trackingtool-without-eventid' );
			}
			if ( $trackingToolUserID === null && $trackingToolEventID !== null ) {
				return StatusValue::newFatal( 'campaignevents-error-trackingtool-eventid-without-toolid' );
			}
			return StatusValue::newGood( null );
		}

		try {
			return StatusValue::newGood(
				$this->trackingToolRegistry->newFromUserIdentifier( $trackingToolUserID )->getDBID()
			);
		} catch ( ToolNotFoundException $_ ) {
			return StatusValue::newFatal( 'campaignevents-error-invalid-trackingtool' );
		}
	}

	/**
	 * @param string $timezone
	 * @return StatusValue If good, has the corresponding DateTimeZone object as value.
	 */
	private function validateTimezone( string $timezone ): StatusValue {
		if ( preg_match( '/^[+-]/', $timezone ) ) {
			$matches = [];
			if ( !preg_match( '/^[+-](\d\d):(\d\d)$/', $timezone, $matches ) ) {
				// Work around bug in PHP: strings starting with + and - do not throw an exception in PHP < 8,
				// see https://3v4l.org/SE0oA. This also rejects offsets where the hours or the minutes have more
				// than 3 digits, which PHP accepts but then does not handle properly; the exact meaning of "not handle
				// properly" depends on the PHP version, see https://github.com/php/php-src/issues/9763#issue-1411450292
				return StatusValue::newFatal( 'campaignevents-error-invalid-timezone' );
			}
			// Work around another PHP bug: if the hours are < 100 but hours + 60 * miutes >= 100*60, it will truncate
			// the input and add a null byte that makes it unusable, see https://github.com/php/php-src/issues/9763
			if ( $matches[1] === '99' && (int)$matches[2] >= 60 ) {
				return StatusValue::newFatal( 'campaignevents-error-invalid-timezone' );
			}
		}
		try {
			return StatusValue::newGood( new DateTimeZone( $timezone ) );
		} catch ( TimeoutException $e ) {
			throw $e;
		} catch ( Exception $e ) {
			// PHP throws a generic Exception, but we don't want to catch excimer timeouts.
			// Again, thanks PHP for making error handling so convoluted here.
			// See https://github.com/php/php-src/issues/9784
			return StatusValue::newFatal( 'campaignevents-error-invalid-timezone' );
		}
	}

	/**
	 * @param int $validationFlags
	 * @param DateTimeZone $timezone
	 * @param string $start
	 * @param string $end
	 * @return StatusValue
	 */
	private function validateLocalDates(
		int $validationFlags,
		DateTimeZone $timezone,
		string $start,
		string $end
	): StatusValue {
		$res = StatusValue::newGood();

		$startTSUnix = null;
		$endTSUnix = null;
		$startAndEndValid = true;
		if ( $start === '' ) {
			$startAndEndValid = false;
			$res->error( 'campaignevents-error-empty-start' );
		} elseif ( MWTimestamp::convert( TS_MW, $start ) !== $start ) {
			// This accounts for both the timestamp being invalid and it not being TS_MW.
			$startAndEndValid = false;
			$res->error( 'campaignevents-error-invalid-start' );
		} else {
			$startTSUnix = ( new DateTime( $start, $timezone ) )->getTimestamp();
			if (
				!( $validationFlags & self::VALIDATE_SKIP_DATES_PAST ) && $startTSUnix < MWTimestamp::time()
			) {
				$res->error( 'campaignevents-error-start-past' );
			}
		}

		if ( $end === '' ) {
			$startAndEndValid = false;
			$res->error( 'campaignevents-error-empty-end' );
		} elseif ( MWTimestamp::convert( TS_MW, $end ) !== $end ) {
			// This accounts for both the timestamp being invalid and it not being TS_MW.
			$startAndEndValid = false;
			$res->error( 'campaignevents-error-invalid-end' );
		} else {
			$endTSUnix = ( new DateTime( $end, $timezone ) )->getTimestamp();
		}

		if ( $startAndEndValid && $startTSUnix > $endTSUnix ) {
			$res->error( 'campaignevents-error-start-after-end' );
		}

		return $res;
	}

	/**
	 * @param int $meetingType
	 * @param string|null &$meetingURL
	 * @param string|null &$meetingCountry
	 * @param string|null &$meetingAddress
	 * @return StatusValue
	 */
	private function validateMeetingInfo(
		int $meetingType,
		?string &$meetingURL,
		?string &$meetingCountry,
		?string &$meetingAddress
	): StatusValue {
		$res = StatusValue::newGood();

		if ( !in_array( $meetingType, EventRegistration::VALID_MEETING_TYPES, true ) ) {
			$res->error( 'campaignevents-error-no-meeting-type' );
			// Don't bother checking the rest.
			return $res;
		}

		if ( $meetingType & EventRegistration::MEETING_TYPE_ONLINE ) {
			if ( $meetingURL !== null ) {
				$meetingURL = trim( $meetingURL );
				if ( !$this->isValidURL( $meetingURL ) ) {
					$res->error( 'campaignevents-error-invalid-meeting-url' );
				}
			}
		} elseif ( $meetingURL !== null ) {
			$res->error( 'campaignevents-error-meeting-url-not-online' );
		}

		if ( $meetingType & EventRegistration::MEETING_TYPE_IN_PERSON ) {
			if ( $meetingCountry !== null ) {
				$meetingCountry = trim( $meetingCountry );
			}
			if ( $meetingAddress !== null ) {
				$meetingAddress = trim( $meetingAddress );
			}
			if ( $meetingCountry !== null && $meetingAddress !== null ) {
				$res->merge( $this->validateLocation( $meetingCountry, $meetingAddress ) );
			}
		} elseif ( $meetingCountry !== null || $meetingAddress !== null ) {
			$res->error( 'campaignevents-error-countryoraddress-not-in-person' );
		}
		return $res;
	}

	/**
	 * @param string $data
	 * @return bool
	 */
	private function isValidURL( string $data ): bool {
		// TODO There's a lot of space for improvement here, e.g., expand the list of allowed protocols, and
		// possibly avoid having to do all the normalization and checks ourselves.
		$allowedSchemes = [ 'http', 'https' ];
		if ( !preg_match( '/^((' . implode( '|', $allowedSchemes ) . '):)?\/\//i', $data ) ) {
			return false;
		}
		// Add the HTTPS protocol explicitly, since FILTER_VALIDATE_URL wants a scheme.
		$urlToCheck = str_starts_with( $data, '//' ) ? "https:$data" : $data;
		return filter_var( $urlToCheck, FILTER_VALIDATE_URL ) !== false;
	}

	/**
	 * @param string $country
	 * @param string $address
	 * @return StatusValue
	 */
	private function validateLocation( string $country, string $address ): StatusValue {
		$res = StatusValue::newGood();
		if ( $country === '' ) {
			$res->error( 'campaignevents-error-invalid-country' );
		}
		if ( $address === '' ) {
			$res->error( 'campaignevents-error-invalid-address' );
		}
		return $res;
	}

}
