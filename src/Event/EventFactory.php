<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Event;

use InvalidArgumentException;
use MalformedTitleException;
use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFactory;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsPage;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageNotFoundException;
use MediaWiki\Interwiki\InterwikiLookup;
use Message;
use MWTimestamp;
use StatusValue;
use TitleParser;

class EventFactory {
	public const SERVICE_NAME = 'CampaignEventsEventFactory';

	private const MAX_NAME_LENGTH = 255;

	/** @var TitleParser */
	private $titleParser;
	/** @var InterwikiLookup */
	private $interwikiLookup;
	/** @var CampaignsPageFactory */
	private $campaignsPageFactory;

	/**
	 * @param TitleParser $titleParser
	 * @param InterwikiLookup $interwikiLookup
	 * @param CampaignsPageFactory $campaignsPageFactory
	 */
	public function __construct(
		TitleParser $titleParser,
		InterwikiLookup $interwikiLookup,
		CampaignsPageFactory $campaignsPageFactory
	) {
		$this->titleParser = $titleParser;
		$this->interwikiLookup = $interwikiLookup;
		$this->campaignsPageFactory = $campaignsPageFactory;
	}

	/**
	 * Creates a new event registration entity, making sure that the given data is valid and formatted as expected.
	 *
	 * @param int|null $id
	 * @param string $name
	 * @param string $pageTitleStr
	 * @param string|null $chatURL
	 * @param string|null $trackingToolName
	 * @param string|null $trackingToolURL
	 * @param string $status
	 * @param string $startTimestamp In the TS_MW format
	 * @param string $endTimestamp In the TS_MW format
	 * @param string $type
	 * @param int $meetingType
	 * @param string|null $meetingURL
	 * @param string|null $meetingCountry
	 * @param string|null $meetingAddress
	 * @param string|null $creationTimestamp In the TS_MW format
	 * @param string|null $lastEditTimestamp In the TS_MW format
	 * @param string|null $deletionTimestamp In the TS_MW format
	 * @return EventRegistration
	 * @throws InvalidEventDataException
	 */
	public function newEvent(
		?int $id,
		string $name,
		string $pageTitleStr,
		?string $chatURL,
		?string $trackingToolName,
		?string $trackingToolURL,
		string $status,
		string $startTimestamp,
		string $endTimestamp,
		string $type,
		int $meetingType,
		?string $meetingURL,
		?string $meetingCountry,
		?string $meetingAddress,
		?string $creationTimestamp,
		?string $lastEditTimestamp,
		?string $deletionTimestamp
	): EventRegistration {
		$res = StatusValue::newGood();

		if ( $id !== null && $id <= 0 ) {
			$res->error( 'campaignevents-error-invalid-id' );
		}

		$name = trim( $name );
		if ( $name === '' ) {
			$res->error( 'campaignevents-error-empty-name' );
		} elseif ( strlen( $name ) > self::MAX_NAME_LENGTH ) {
			$res->error( 'campaignevents-error-name-too-long', Message::numParam( self::MAX_NAME_LENGTH ) );
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

		if ( $trackingToolName !== null && $trackingToolURL === null ) {
			$res->error( 'campaignevents-error-tracking-tool-name-without-url' );
		} elseif ( $trackingToolName === null && $trackingToolURL !== null ) {
			$res->error( 'campaignevents-error-tracking-tool-url-without-name' );
		} elseif ( $trackingToolName !== null && $trackingToolURL !== null ) {
			$trackingToolName = trim( $trackingToolName );
			$trackingToolURL = trim( $trackingToolURL );
			$res->merge( $this->validateTrackingTool( $trackingToolName, $trackingToolURL ) );
		}

		if ( !in_array( $status, EventRegistration::VALID_STATUSES, true ) ) {
			$res->error( 'campaignevents-error-invalid-status' );
		}

		$datesStatus = $this->validateDates( $startTimestamp, $endTimestamp );
		$res->merge( $datesStatus );
		[ $startTSUnix, $endTSUnix ] = $datesStatus->getValue();

		if ( !in_array( $type, EventRegistration::VALID_TYPES, true ) ) {
			$res->error( 'campaignevents-error-invalid-type' );
		}

		if ( !in_array( $meetingType, EventRegistration::VALID_MEETING_TYPES, true ) ) {
			$res->error( 'campaignevents-error-no-meeting-type' );
		}

		if ( $meetingType & EventRegistration::MEETING_TYPE_ONLINE ) {
			if ( $meetingURL === null ) {
				$res->error( 'campaignevents-error-online-no-meeting-url' );
			} else {
				$meetingURL = trim( $meetingURL );
				if ( !$this->isValidURL( $meetingURL ) ) {
					$res->error( 'campaignevents-error-invalid-meeting-url' );
				}
			}
		}

		if ( $meetingType & EventRegistration::MEETING_TYPE_PHYSICAL ) {
			if ( $meetingCountry === null ) {
				$res->error( 'campaignevents-error-physical-no-country' );
			} else {
				$meetingCountry = trim( $meetingCountry );
			}
			if ( $meetingAddress === null ) {
				$res->error( 'campaignevents-error-physical-no-address' );
			} else {
				$meetingAddress = trim( $meetingAddress );
			}
			if ( $meetingCountry !== null && $meetingAddress !== null ) {
				$res->merge( $this->validateLocation( $meetingCountry, $meetingAddress ) );
			}
		}

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
			$name,
			$campaignsPage,
			$chatURL,
			$trackingToolName,
			$trackingToolURL,
			$status,
			$startTSUnix,
			$endTSUnix,
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
			$pageTitle = $this->titleParser->parseTitle( $pageTitleStr );
		} catch ( MalformedTitleException $e ) {
			return StatusValue::newFatal( 'campaignevents-error-invalid-title', $e->getMessageObject() );
		}

		if ( $pageTitle->getInterwiki() !== '' ) {
			$interwiki = $this->interwikiLookup->fetch( $pageTitle->getInterwiki() );
			if ( !$interwiki ) {
				return StatusValue::newFatal( 'campaignevents-error-invalid-title-interwiki' );
			}
			$wikiID = $interwiki->getWikiID();
		} else {
			$wikiID = WikiAwareEntity::LOCAL;
		}

		try {
			$campaignsPage = $this->campaignsPageFactory->newExistingPage(
				$pageTitle->getNamespace(),
				$pageTitle->getDBkey(),
				$wikiID
			);
		} catch ( PageNotFoundException $_ ) {
			return StatusValue::newFatal( 'campaignevents-error-page-not-found' );
		}

		return StatusValue::newGood( $campaignsPage );
	}

	/**
	 * @param string $start
	 * @param string $end
	 * @return StatusValue Whose result is [ start_unix, end_unix ]
	 */
	private function validateDates( string $start, string $end ): StatusValue {
		$res = StatusValue::newGood();

		$startTSUnix = null;
		$endTSUnix = null;
		$startAndEndValid = true;
		if ( $start === '' ) {
			$startAndEndValid = false;
			$res->error( 'campaignevents-error-empty-start' );
		} else {
			$startTSUnix = wfTimestamp( TS_UNIX, $start );
			if ( $startTSUnix === false ) {
				$startAndEndValid = false;
				$res->error( 'campaignevents-error-invalid-start' );
			} elseif ( (int)$startTSUnix < MWTimestamp::time() ) {
				$res->error( 'campaignevents-error-start-past' );
			}
		}

		if ( $end === '' ) {
			$startAndEndValid = false;
			$res->error( 'campaignevents-error-empty-end' );
		} else {
			$endTSUnix = wfTimestamp( TS_UNIX, $end );
			if ( $endTSUnix === false ) {
				$startAndEndValid = false;
				$res->error( 'campaignevents-error-invalid-end' );
			}
		}

		if ( $startAndEndValid && $startTSUnix > $endTSUnix ) {
			$res->error( 'campaignevents-error-start-after-end' );
		}
		$res->setResult( true, [ $startTSUnix, $endTSUnix ] );
		return $res;
	}

	/**
	 * @param string $data
	 * @return bool
	 */
	private function isValidURL( string $data ): bool {
		return filter_var( $data, FILTER_VALIDATE_URL ) !== false;
	}

	/**
	 * @param string $name
	 * @param string $url
	 * @return StatusValue
	 */
	private function validateTrackingTool( string $name, string $url ): StatusValue {
		$res = StatusValue::newGood();
		if ( !$this->isValidURL( $url ) ) {
			$res->error( 'campaignevents-error-invalid-trackingtool-url' );
		}
		return $res;
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
