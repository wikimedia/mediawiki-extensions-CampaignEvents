<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\TrackingTool\Tool;

use LogicException;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Http\HttpRequestFactory;
use Message;
use StatusValue;

/**
 * This class implements the WikiEduDashboard software as a tracking tool.
 */
class WikiEduDashboard extends TrackingTool {
	/** @var HttpRequestFactory */
	private HttpRequestFactory $httpRequestFactory;
	/** @var CampaignsCentralUserLookup */
	private CampaignsCentralUserLookup $centralUserLookup;

	/** @var string */
	private $apiSecret;
	/** @var string|null */
	private ?string $apiProxy;

	/**
	 * @inheritDoc
	 */
	public function __construct(
		HttpRequestFactory $httpRequestFactory,
		CampaignsCentralUserLookup $centralUserLookup,
		int $dbID,
		string $baseURL,
		array $extra
	) {
		parent::__construct( $dbID, $baseURL, $extra );
		$this->httpRequestFactory = $httpRequestFactory;
		$this->centralUserLookup = $centralUserLookup;
		$this->apiSecret = $extra['secret'];
		$this->apiProxy = $extra['proxy'];
	}

	/**
	 * @inheritDoc
	 */
	public function validateToolAddition(
		EventRegistration $event,
		array $organizers,
		string $toolEventID
	): StatusValue {
		$organizerIDsMap = array_fill_keys(
			array_map( static fn( CentralUser $u ) => $u->getCentralID(), $organizers ),
			null
		);
		$organizerNames = array_values( $this->centralUserLookup->getNames( $organizerIDsMap ) );
		$eventID = $event->getID();
		if ( $eventID === null ) {
			throw new LogicException( "Cannot sync tools with events without ID" );
		}
		return $this->makePostRequest(
			'confirm_event_sync',
			$eventID,
			$toolEventID,
			true,
			[
				'organizer_usernames' => $organizerNames,
			]
		);
	}

	/**
	 * @inheritDoc
	 */
	public function addToEvent( EventRegistration $event, array $organizers, string $toolEventID ): StatusValue {
		return StatusValue::newGood();
	}

	/**
	 * @inheritDoc
	 */
	public function validateToolRemoval( ExistingEventRegistration $event, string $toolEventID ): StatusValue {
		return StatusValue::newGood();
	}

	/**
	 * @inheritDoc
	 */
	public function removeFromEvent( ExistingEventRegistration $event, string $toolEventID ): StatusValue {
		return StatusValue::newGood();
	}

	/**
	 * @inheritDoc
	 */
	public function validateEventDeletion( ExistingEventRegistration $event, string $toolEventID ): StatusValue {
		return StatusValue::newGood();
	}

	/**
	 * @inheritDoc
	 */
	public function onEventDeleted( ExistingEventRegistration $event, string $toolEventID ): StatusValue {
		return StatusValue::newGood();
	}

	/**
	 * @inheritDoc
	 */
	public function validateParticipantAdded(
		ExistingEventRegistration $event,
		string $toolEventID,
		CentralUser $participant,
		bool $private
	): StatusValue {
		return StatusValue::newGood();
	}

	/**
	 * @inheritDoc
	 */
	public function addParticipant(
		ExistingEventRegistration $event,
		string $toolEventID,
		CentralUser $participant,
		bool $private
	): StatusValue {
		return StatusValue::newGood();
	}

	/**
	 * @inheritDoc
	 */
	public function validateParticipantsRemoved(
		ExistingEventRegistration $event,
		string $toolEventID,
		?array $participants,
		bool $invertSelection
	): StatusValue {
		return StatusValue::newGood();
	}

	/**
	 * @inheritDoc
	 */
	public function removeParticipants(
		ExistingEventRegistration $event,
		string $toolEventID,
		?array $participants,
		bool $invertSelection
	): StatusValue {
		return StatusValue::newGood();
	}

	/**
	 * @param string $endpoint
	 * @param int $eventID
	 * @param string $courseID
	 * @param bool $dryRun
	 * @param array $extraParams
	 * @return StatusValue
	 */
	private function makePostRequest(
		string $endpoint,
		int $eventID,
		string $courseID,
		bool $dryRun,
		array $extraParams = []
	): StatusValue {
		$options = [
			'method' => 'POST',
			'timeout' => 5,
			'postData' => json_encode( $extraParams + [
				'course_slug' => $courseID,
				'event_id' => $eventID,
				'secret' => $this->apiSecret,
				'dry_run' => $dryRun
			] )
		];
		if ( $this->apiProxy ) {
			$options['proxy'] = $this->apiProxy;
		}
		$req = $this->httpRequestFactory->create(
			$this->baseURL . 'wikimedia_event_center/' . $endpoint,
			$options,
			__METHOD__
		);
		$req->setHeader( 'Content-Type', 'application/json' );

		$status = $req->execute();
		$contentTypeHeader = $req->getResponseHeader( 'Content-Type' );
		$contentType = strtolower( explode( ';', $contentTypeHeader )[0] );
		if ( $contentType !== 'application/json' ) {
			return StatusValue::newFatal( 'campaignevents-tracking-tool-http-error' );
		}
		if ( $status->isGood() ) {
			return StatusValue::newGood();
		}
		$respObj = json_decode( $req->getContent(), true );
		return $this->makeErrorStatus( $respObj, $courseID );
	}

	/**
	 * @param array $response
	 * @param string $courseID
	 * @return StatusValue
	 */
	private function makeErrorStatus( array $response, string $courseID ): StatusValue {
		if ( !isset( $response['error_code'] ) ) {
			return StatusValue::newFatal( 'campaignevents-tracking-tool-http-error' );
		}

		switch ( $response['error_code'] ) {
			case 'invalid_secret':
				$msg = 'campaignevents-tracking-tool-wikiedu-config-error';
				$params = [ new Message( 'campaignevents-tracking-tool-p&e-dashboard-name' ) ];
				break;
			case 'course_not_found':
				$msg = 'campaignevents-tracking-tool-wikiedu-course-not-found-error';
				$params = [
					$courseID,
					new Message( 'campaignevents-tracking-tool-p&e-dashboard-name' )
				];
				break;
			case 'not_organizer':
				$msg = 'campaignevents-tracking-tool-wikiedu-not-organizer-error';
				$params = [ $courseID ];
				break;
			case 'already_in_use':
				$msg = 'campaignevents-tracking-tool-wikiedu-already-in-use-error';
				$params = [ $courseID ];
				break;
			case 'sync_already_enabled':
				$msg = 'campaignevents-tracking-tool-wikiedu-already-connected-error';
				$params = [ $courseID ];
				break;
			case 'sync_not_enabled':
				$msg = 'campaignevents-tracking-tool-wikiedu-not-connected-error';
				$params = [ $courseID ];
				break;
			default:
				$msg = 'campaignevents-tracking-tool-http-error';
				$params = [];
				break;
		}
		return StatusValue::newFatal( $msg, ...$params );
	}
}
