<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Event;

use DateTime;
use DateTimeZone;
use Exception;
use InvalidArgumentException;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFactory;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFormatter;
use MediaWiki\Extension\CampaignEvents\MWEntity\InvalidTitleStringException;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWPageProxy;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageNotFoundException;
use MediaWiki\Extension\CampaignEvents\MWEntity\UnexpectedInterwikiException;
use MediaWiki\Extension\CampaignEvents\MWEntity\UnexpectedSectionAnchorException;
use MediaWiki\Extension\CampaignEvents\MWEntity\UnexpectedVirtualNamespaceException;
use MediaWiki\Extension\CampaignEvents\MWEntity\WikiLookup;
use MediaWiki\Extension\CampaignEvents\Questions\EventQuestionsRegistry;
use MediaWiki\Extension\CampaignEvents\Questions\UnknownQuestionException;
use MediaWiki\Extension\CampaignEvents\Topics\ITopicRegistry;
use MediaWiki\Extension\CampaignEvents\TrackingTool\ToolNotFoundException;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolAssociation;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolRegistry;
use MediaWiki\Message\Message;
use MediaWiki\Utils\MWTimestamp;
use StatusValue;
use Wikimedia\Message\ListType;
use Wikimedia\Message\MessageValue;
use Wikimedia\RequestTimeout\TimeoutException;

class EventFactory {
	public const SERVICE_NAME = 'CampaignEventsEventFactory';
	public const VALIDATE_ALL = 0;
	public const VALIDATE_SKIP_DATES_PAST = 1 << 0;
	/**
	 * @var int Skips validation of the event page namespace, as long as the requested event page matches the
	 * $previousPage passed to self::newEvent.
	 */
	public const VALIDATE_SKIP_UNCHANGED_EVENT_PAGE_NAMESPACE = 1 << 1;

	public const MAX_TYPES = 2;

	public const MAX_WIKIS = 100;

	/**
	 * Constants for limiting the length of country and address. These are intentionally quite high and shouldn't be
	 * hit under normal circumstances. So, we just truncate the given strings instead of displaying an error. For the
	 * same reason, we use bytes and not more sensible units like characters or graphemes.
	 */
	public const COUNTRY_MAXLENGTH_BYTES = 255;
	public const ADDRESS_MAXLENGTH_BYTES = 8192;
	public const MAX_TOPICS = 5;

	private CampaignsPageFactory $campaignsPageFactory;
	private CampaignsPageFormatter $campaignsPageFormatter;
	private TrackingToolRegistry $trackingToolRegistry;
	private EventQuestionsRegistry $eventQuestionsRegistry;
	private WikiLookup $wikiLookup;
	private ITopicRegistry $topicRegistry;
	private EventTypesRegistry $eventTypesRegistry;
	/** @var list<int> */
	private array $allowedEventNamespaces;

	/**
	 * @phan-param list<int> $allowedEventNamespaces
	 */
	public function __construct(
		CampaignsPageFactory $campaignsPageFactory,
		CampaignsPageFormatter $campaignsPageFormatter,
		TrackingToolRegistry $trackingToolRegistry,
		EventQuestionsRegistry $eventQuestionsRegistry,
		WikiLookup $wikiLookup,
		ITopicRegistry $topicRegistry,
		EventTypesRegistry $eventTypesRegistry,
		array $allowedEventNamespaces
	) {
		$this->campaignsPageFactory = $campaignsPageFactory;
		$this->campaignsPageFormatter = $campaignsPageFormatter;
		$this->trackingToolRegistry = $trackingToolRegistry;
		$this->eventQuestionsRegistry = $eventQuestionsRegistry;
		$this->wikiLookup = $wikiLookup;
		$this->topicRegistry = $topicRegistry;
		$this->eventTypesRegistry = $eventTypesRegistry;
		$this->allowedEventNamespaces = $allowedEventNamespaces;
	}

	/**
	 * Creates a new event registration entity, making sure that the given data is valid and formatted as expected.
	 *
	 * @param int|null $id
	 * @param string $pageTitleStr
	 * @param string $status
	 * @param string $timezone Can be in any format accepted by DateTimeZone
	 * @param string $startLocalTimestamp In the TS_MW format
	 * @param string $endLocalTimestamp In the TS_MW format
	 * @param list<string> $types
	 * @param string[]|true $wikis List of wiki IDs, or {@see EventRegistration::ALL_WIKIS}
	 * @param string[] $topics
	 * @param string|null $trackingToolUserID User identifier of a tracking tool
	 * @param string|null $trackingToolEventID
	 * @param int $participationOptions
	 * @param string|null $meetingURL
	 * @param string|null $meetingCountry
	 * @param string|null $meetingAddress
	 * @param string|null $chatURL
	 * @param bool $isTestEvent
	 * @param string[] $participantQuestionNames
	 * @param string|null $creationTimestamp In the TS_MW format
	 * @param string|null $lastEditTimestamp In the TS_MW format
	 * @param string|null $deletionTimestamp In the TS_MW format
	 * @param int $validationFlags
	 * @param MWPageProxy|null $previousPage Used together with the validation flag
	 *   {@link self::VALIDATE_SKIP_UNCHANGED_EVENT_PAGE_NAMESPACE}. If the requested event page is the same as
	 *   this page, validation of the namespace is skipped.
	 *
	 * @return EventRegistration
	 * @throws InvalidEventDataException
	 */
	public function newEvent(
		?int $id,
		string $pageTitleStr,
		string $status,
		string $timezone,
		string $startLocalTimestamp,
		string $endLocalTimestamp,
		array $types,
		$wikis,
		array $topics,
		?string $trackingToolUserID,
		?string $trackingToolEventID,
		int $participationOptions,
		?string $meetingURL,
		?string $meetingCountry,
		?string $meetingAddress,
		?string $chatURL,
		bool $isTestEvent,
		array $participantQuestionNames,
		?string $creationTimestamp,
		?string $lastEditTimestamp,
		?string $deletionTimestamp,
		int $validationFlags = self::VALIDATE_ALL,
		?MWPageProxy $previousPage = null
	): EventRegistration {
		$res = StatusValue::newGood();

		if ( $id !== null && $id <= 0 ) {
			$res->error( 'campaignevents-error-invalid-id' );
		}

		$pageStatus = $this->validatePage( $pageTitleStr, $validationFlags, $previousPage );
		$res->merge( $pageStatus );
		$campaignsPage = $pageStatus->getValue();

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

		$typesStatus = $this->validateTypes( $types );
		$res->merge( $typesStatus );
		$types = $typesStatus->getValue();

		$wikisStatus = $this->validateWikis( $wikis );
		$res->merge( $wikisStatus );
		$wikis = $wikisStatus->getValue();

		$topicsStatus = $this->validateTopics( $topics );
		$res->merge( $topicsStatus );
		$topics = $topicsStatus->getValue();

		$trackingToolStatus = $this->validateTrackingTool( $trackingToolUserID, $trackingToolEventID );
		$res->merge( $trackingToolStatus );
		$trackingToolDBID = $trackingToolStatus->getValue();
		if ( $trackingToolDBID !== null ) {
			$trackingTools = [
				new TrackingToolAssociation(
					$trackingToolDBID,
					$trackingToolEventID,
					TrackingToolAssociation::SYNC_STATUS_UNKNOWN,
					null
				)
			];
		} else {
			$trackingTools = [];
		}

		$res->merge(
			$this->validateMeetingInfo( $participationOptions, $meetingURL, $meetingCountry, $meetingAddress )
		);

		if ( $chatURL !== null ) {
			$chatURL = trim( $chatURL );
			if ( !$this->isValidURL( $chatURL ) ) {
				$res->error( 'campaignevents-error-invalid-chat-url' );
			}
		}

		$questionsStatus = $this->validateParticipantQuestions( $participantQuestionNames );
		$res->merge( $questionsStatus );
		$questionIDs = $questionsStatus->getValue();

		$creationTSUnix = wfTimestampOrNull( TS_UNIX, $creationTimestamp );
		$lastEditTSUnix = wfTimestampOrNull( TS_UNIX, $lastEditTimestamp );
		$deletionTSUnix = wfTimestampOrNull( TS_UNIX, $deletionTimestamp );
		// Creation, last edit, and deletion timestamp don't need user-facing validation since it's not the
		// user setting them.
		$invalidTimestamps = array_filter(
			[ 'creation' => $creationTSUnix, 'lastedit' => $lastEditTSUnix, 'deletion' => $deletionTSUnix ],
			/** @param string|false|null $ts */
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

		/** @var MWPageProxy $campaignsPage */
		'@phan-var MWPageProxy $campaignsPage';

		return new EventRegistration(
			$id,
			$this->campaignsPageFormatter->getText( $campaignsPage ),
			$campaignsPage,
			$status,
			$timezoneObj,
			$startLocalTimestamp,
			$endLocalTimestamp,
			$types,
			$wikis,
			$topics,
			$trackingTools,
			$participationOptions,
			$meetingURL,
			$meetingCountry,
			$meetingAddress,
			$chatURL,
			$isTestEvent,
			$questionIDs,
			$creationTSUnix,
			$lastEditTSUnix,
			$deletionTSUnix
		);
	}

	/**
	 * Validates the page title provided as a string.
	 *
	 * @param string $pageTitleStr
	 * @param int $validationFlags Combination of self::VALIDATE_* constants.
	 * @param MWPageProxy|null $previousPage
	 * @return StatusValue Fatal if invalid, good otherwise and with an MWPageProxy as value.
	 */
	private function validatePage(
		string $pageTitleStr,
		int $validationFlags,
		?MWPageProxy $previousPage
	): StatusValue {
		$pageTitleStr = trim( $pageTitleStr );
		if ( $pageTitleStr === '' ) {
			return StatusValue::newFatal( 'campaignevents-error-empty-title' );
		}

		try {
			$campaignsPage = $this->campaignsPageFactory->newLocalExistingPageFromString( $pageTitleStr );
		} catch ( InvalidTitleStringException $e ) {
			return StatusValue::newFatal(
				'campaignevents-error-invalid-title',
				new MessageValue( $e->getErrorMsgKey(), $e->getErrorMsgParams() )
			);
		} catch ( UnexpectedInterwikiException $_ ) {
			return StatusValue::newFatal( 'campaignevents-error-invalid-title-interwiki' );
		} catch ( UnexpectedVirtualNamespaceException $_ ) {
			return StatusValue::newFatal( 'campaignevents-error-page-namespace-not-allowed' );
		} catch ( UnexpectedSectionAnchorException $_ ) {
			return StatusValue::newFatal( 'campaignevents-error-page-with-section' );
		} catch ( PageNotFoundException $_ ) {
			return StatusValue::newFatal( 'campaignevents-error-page-not-found' );
		}

		$skipSamePageNsValidation = ( $validationFlags & self::VALIDATE_SKIP_UNCHANGED_EVENT_PAGE_NAMESPACE ) !== 0;
		if (
			!( $skipSamePageNsValidation && $previousPage && $campaignsPage->equals( $previousPage ) ) &&
			!in_array( $campaignsPage->getNamespace(), $this->allowedEventNamespaces, true )
		) {
			return StatusValue::newFatal( 'campaignevents-error-page-namespace-not-allowed' );
		}

		return StatusValue::newGood( $campaignsPage );
	}

	/** @param list<string> $types */
	private function validateTypes( array $types ): StatusValue {
		$types = array_unique( $types );
		$ret = StatusValue::newGood( $types );

		if ( !$types ) {
			$ret->error( 'campaignevents-error-no-types' );
		}

		if ( count( $types ) > self::MAX_TYPES ) {
			$ret->error( 'campaignevents-error-too-many-types', Message::numParam( self::MAX_TYPES ) );
		}

		$invalidTypes = array_diff( $types, $this->eventTypesRegistry->getAllTypes() );
		if ( $invalidTypes ) {
			$ret->error(
				'campaignevents-error-invalid-types',
				Message::listParam( $invalidTypes, ListType::COMMA )
			);
		}

		if ( count( $types ) > 1 && in_array( EventTypesRegistry::EVENT_TYPE_OTHER, $types, true ) ) {
			$ret->error( 'campaignevents-error-invalid-other-selection' );
		}

		return $ret;
	}

	/**
	 * @param string[]|true $wikis
	 * @return StatusValue Having a canonicalized list of wiki IDs as value.
	 */
	private function validateWikis( $wikis ): StatusValue {
		if ( $wikis === EventRegistration::ALL_WIKIS ) {
			return StatusValue::newGood( $wikis );
		}

		$wikis = array_unique( $wikis );
		$ret = StatusValue::newGood( $wikis );
		if ( count( $wikis ) > self::MAX_WIKIS ) {
			$ret->error( 'campaignevents-error-too-many-wikis', Message::numParam( self::MAX_WIKIS ) );
		}

		$invalidWikis = array_diff( $wikis, $this->wikiLookup->getAllWikis() );
		if ( $invalidWikis ) {
			$ret->error(
				'campaignevents-error-invalid-wikis',
				Message::listParam( $invalidWikis, ListType::COMMA )
			);
		}

		return $ret;
	}

	/**
	 * @param string[] $topics
	 *
	 * @return StatusValue Having a canonicalized list of topics IDs as value.
	 */
	private function validateTopics( array $topics ): StatusValue {
		$topics = array_unique( $topics );
		$ret = StatusValue::newGood( $topics );
		if ( count( $topics ) > self::MAX_TOPICS ) {
			$ret->error( 'campaignevents-error-too-many-topics', Message::numParam( self::MAX_TOPICS ) );
		}

		$invalidTopics = array_diff( $topics, $this->topicRegistry->getAllTopics() );
		if ( $invalidTopics ) {
			$ret->error(
				'campaignevents-error-invalid-topics',
				Message::listParam( $invalidTopics, ListType::COMMA )
			);
		}

		return $ret;
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

	private function validateMeetingInfo(
		int $participationOptions,
		?string &$meetingURL,
		?string &$meetingCountry,
		?string &$meetingAddress
	): StatusValue {
		$res = StatusValue::newGood();

		if ( !in_array( $participationOptions, EventRegistration::VALID_PARTICIPATION_OPTIONS, true ) ) {
			$res->error( 'campaignevents-error-no-meeting-type' );
			// Don't bother checking the rest.
			return $res;
		}

		if ( $participationOptions & EventRegistration::PARTICIPATION_OPTION_ONLINE ) {
			if ( $meetingURL !== null ) {
				$meetingURL = trim( $meetingURL );
				if ( !$this->isValidURL( $meetingURL ) ) {
					$res->error( 'campaignevents-error-invalid-meeting-url' );
				}
			}
		} elseif ( $meetingURL !== null ) {
			$res->error( 'campaignevents-error-meeting-url-not-online' );
		}

		if ( $participationOptions & EventRegistration::PARTICIPATION_OPTION_IN_PERSON ) {
			if ( $meetingCountry !== null ) {
				$meetingCountry = mb_strcut( trim( $meetingCountry ), 0, self::COUNTRY_MAXLENGTH_BYTES );
			}
			if ( $meetingAddress !== null ) {
				$meetingAddress = mb_strcut( trim( $meetingAddress ), 0, self::ADDRESS_MAXLENGTH_BYTES );
			}
			if ( $meetingCountry !== null && $meetingAddress !== null ) {
				$res->merge( $this->validateLocation( $meetingCountry, $meetingAddress ) );
			}
		} elseif ( $meetingCountry !== null || $meetingAddress !== null ) {
			$res->error( 'campaignevents-error-countryoraddress-not-in-person' );
		}
		return $res;
	}

	private function isValidURL( string $data ): bool {
		// TODO There's a lot of space for improvement here, e.g., expand the list of allowed protocols, and
		// possibly avoid having to do all the normalization and checks ourselves.
		$allowedSchemes = [ 'http', 'https' ];

		// Add the HTTPS protocol explicitly, since FILTER_VALIDATE_URL wants a scheme.
		$urlToCheck = preg_match( '/^\/\/.*/', $data ) ? "https:$data" : $data;
		$urlParts = parse_url( $urlToCheck );

		// Validate scheme, host presence, and allowed schemes
		if (
			$urlParts === false || !isset( $urlParts[ 'scheme' ] ) ||
			!isset( $urlParts[ 'host' ] ) ||
			!in_array( strtolower( $urlParts[ 'scheme' ] ), $allowedSchemes, true )
		) {
			return false;
		}

		// Convert URL host from IDN to ASCII (Punycode)
		$hostASCII = idn_to_ascii( $urlParts[ 'host' ], IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
		if ( $hostASCII === false ) {
			return false;
		}

		// Rebuild the URL with the ASCII host for validation
		$urlToCheckASCII = $urlParts[ 'scheme' ] . "://" . $hostASCII;
		if ( isset( $urlParts[ 'path' ] ) ) {
			$urlToCheckASCII .= '/' . urlencode( $urlParts[ 'path' ] );
		}
		if ( isset( $urlParts[ 'query' ] ) ) {
			$urlToCheckASCII .= '?' . urlencode( $urlParts[ 'query' ] );
		}
		if ( isset( $urlParts[ 'fragment' ] ) ) {
			$urlToCheckASCII .= '#' . urlencode( $urlParts[ 'fragment' ] );
		}

		return filter_var( $urlToCheckASCII, FILTER_VALIDATE_URL ) !== false;
	}

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

	/**
	 * @param string[] $questionNames
	 * @return StatusValue Whose value is an array of the corresponding question DB IDs.
	 */
	private function validateParticipantQuestions( array $questionNames ): StatusValue {
		$questionIDs = [];
		$invalidNames = [];
		foreach ( $questionNames as $name ) {
			try {
				$questionIDs[] = $this->eventQuestionsRegistry->nameToDBID( $name );
			} catch ( UnknownQuestionException $_ ) {
				$invalidNames[] = $name;
			}
		}
		$ret = StatusValue::newGood( $questionIDs );
		if ( $invalidNames ) {
			$ret->fatal( 'campaignevents-error-invalid-question names', Message::listParam( $invalidNames ) );
		}
		return $ret;
	}

}
