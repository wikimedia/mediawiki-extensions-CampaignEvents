<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Event;

use InvalidArgumentException;
use MalformedTitleException;
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

		$pageTitleStr = trim( $pageTitleStr );
		if ( $pageTitleStr !== '' ) {
			try {
				$pageTitle = $this->titleParser->parseTitle( $pageTitleStr );
			} catch ( MalformedTitleException $e ) {
				$res->error( 'campaignevents-error-invalid-title', $e->getMessageObject() );
				$pageTitle = null;
			}
		} else {
			$res->error( 'campaignevents-error-empty-title' );
			$pageTitle = null;
		}

		if ( $pageTitle ) {
			$interwiki = $this->interwikiLookup->fetch( $pageTitle->getInterwiki() );
			if ( $interwiki ) {
				try {
					$campaignsPage = $this->campaignsPageFactory->newExistingPage(
						$pageTitle->getNamespace(),
						$pageTitle->getDBkey(),
						$interwiki->getWikiID()
					);
				} catch ( PageNotFoundException $_ ) {
					$res->error( 'campaignevents-error-page-not-found' );
					$campaignsPage = null;
				}
			} else {
				$res->error( 'campaignevents-error-invalid-title-interwiki' );
				$campaignsPage = null;
			}
		} else {
			$campaignsPage = null;
		}

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

		$startAndEndValid = true;
		$startTSUnix = wfTimestamp( TS_UNIX, $startTimestamp );
		if ( $startTSUnix === false ) {
			$startAndEndValid = false;
			$res->error( 'campaignevents-error-invalid-start' );
		} elseif ( (int)$startTSUnix < MWTimestamp::time() ) {
			$res->error( 'campaignevents-error-start-past' );
		}
		$endTSUnix = wfTimestamp( TS_UNIX, $endTimestamp );
		if ( $endTSUnix === false ) {
			$startAndEndValid = false;
			$res->error( 'campaignevents-error-invalid-end' );
		}
		if ( $startAndEndValid && $startTSUnix > $endTSUnix ) {
			$res->error( 'campaignevents-error-start-after-end' );
		}

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

		$creationTSUnix = wfTimestamp( TS_UNIX, $creationTimestamp );
		$lastEditTSUnix = wfTimestamp( TS_UNIX, $lastEditTimestamp );
		$deletionTSUnix = wfTimestamp( TS_UNIX, $deletionTimestamp );
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
