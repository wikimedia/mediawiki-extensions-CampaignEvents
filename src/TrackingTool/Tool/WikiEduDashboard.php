<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\TrackingTool\Tool;

use JsonException;
use LogicException;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\Participants\Participant;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\TrackingTool\InvalidToolURLException;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Logger\LoggerFactory;
use MWHttpRequest;
use StatusValue;
use Wikimedia\Assert\Assert;
use Wikimedia\Message\MessageValue;
use Wikimedia\Rdbms\IDBAccessObject;

/**
 * This class implements the WikiEduDashboard software as a tracking tool.
 */
class WikiEduDashboard extends TrackingTool {
	private HttpRequestFactory $httpRequestFactory;
	private CampaignsCentralUserLookup $centralUserLookup;
	private ParticipantsStore $participantsStore;

	private string $apiSecret;
	private ?string $apiProxy;

	/** @phan-param array<string,mixed> $extra */
	public function __construct(
		HttpRequestFactory $httpRequestFactory,
		CampaignsCentralUserLookup $centralUserLookup,
		ParticipantsStore $participantsStore,
		int $dbID,
		string $baseURL,
		array $extra
	) {
		parent::__construct( $dbID, $baseURL, $extra );
		$this->httpRequestFactory = $httpRequestFactory;
		$this->centralUserLookup = $centralUserLookup;
		$this->participantsStore = $participantsStore;
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
		return $this->makeNewEventRequest( null, $organizers, $toolEventID, true );
	}

	/**
	 * @inheritDoc
	 */
	public function addToNewEvent(
		int $eventID,
		EventRegistration $event,
		array $organizers,
		string $toolEventID
	): StatusValue {
		return $this->makeNewEventRequest( $eventID, $organizers, $toolEventID, false );
	}

	/**
	 * @inheritDoc
	 */
	public function addToExistingEvent(
		ExistingEventRegistration $event,
		array $organizers,
		string $toolEventID
	): StatusValue {
		$addToolStatus = $this->makeNewEventRequest( $event->getID(), $organizers, $toolEventID, false );
		if ( !$addToolStatus->isGood() ) {
			return $addToolStatus;
		}
		// Also sync the participants, as the dashboard won't do that automatically when syncing an event.
		// This is particularly important when the tool is added to an event that already has participants, as
		// is potentially the case for existing events.
		return $this->syncParticipants( $event, $toolEventID, false );
	}

	/**
	 * @param int|null $eventID May only be null when $dryRun is true
	 * @param CentralUser[] $organizers
	 * @param string $toolEventID
	 * @param bool $dryRun
	 */
	private function makeNewEventRequest(
		?int $eventID,
		array $organizers,
		string $toolEventID,
		bool $dryRun
	): StatusValue {
		Assert::precondition( $eventID !== null || $dryRun, 'Cannot sync tools with events without ID' );
		$organizerIDsMap = array_fill_keys(
			array_map( static fn ( CentralUser $u ): int => $u->getCentralID(), $organizers ),
			null
		);
		$organizerNames = array_values( $this->centralUserLookup->getNames( $organizerIDsMap ) );
		return $this->makePostRequest(
			'confirm_event_sync',
			$eventID,
			$toolEventID,
			$dryRun,
			[
				'organizer_usernames' => $organizerNames,
			]
		);
	}

	/**
	 * @inheritDoc
	 */
	public function validateToolRemoval( ExistingEventRegistration $event, string $toolEventID ): StatusValue {
		$status = $this->makePostRequest(
			'unsync_event',
			$event->getID(),
			$toolEventID,
			true
		);
		if (
			$status->hasMessage( 'campaignevents-tracking-tool-wikiedu-course-not-found-error' ) ||
			$status->hasMessage( 'campaignevents-tracking-tool-wikiedu-not-connected-error' )
		) {
			// T358732 - Do not fail if the course no longer exists in the Dashboard
			// T363187 - Do not fail if the course has been unsynced somehow
			return StatusValue::newGood();
		}
		return $status;
	}

	/**
	 * @inheritDoc
	 */
	public function removeFromEvent( ExistingEventRegistration $event, string $toolEventID ): StatusValue {
		$status = $this->makePostRequest(
			'unsync_event',
			$event->getID(),
			$toolEventID,
			false
		);
		if (
			$status->hasMessage( 'campaignevents-tracking-tool-wikiedu-course-not-found-error' ) ||
			$status->hasMessage( 'campaignevents-tracking-tool-wikiedu-not-connected-error' )
		) {
			// T358732 - Do not fail if the course no longer exists in the Dashboard
			// T363187 - Do not fail if the course has been unsynced somehow
			return StatusValue::newGood();
		}
		return $status;
	}

	/**
	 * @inheritDoc
	 */
	public function validateEventDeletion( ExistingEventRegistration $event, string $toolEventID ): StatusValue {
		return $this->validateToolRemoval( $event, $toolEventID );
	}

	/**
	 * @inheritDoc
	 */
	public function onEventDeleted( ExistingEventRegistration $event, string $toolEventID ): StatusValue {
		return $this->removeFromEvent( $event, $toolEventID );
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
		return $this->syncParticipants( $event, $toolEventID, true );
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
		// Note, even if private participants aren't synced, this method can also be called when a previously-public
		// participant switches to private, so we must sync participant all the same.
		return $this->syncParticipants( $event, $toolEventID, false );
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
		return $this->syncParticipants( $event, $toolEventID, true );
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
		return $this->syncParticipants( $event, $toolEventID, false );
	}

	private function syncParticipants(
		ExistingEventRegistration $event,
		string $toolEventID,
		bool $dryRun
	): StatusValue {
		$eventID = $event->getID();
		$latestParticipants = $this->participantsStore->getEventParticipants(
			$eventID,
			null,
			null,
			null,
			null,
			false,
			null,
			IDBAccessObject::READ_LATEST
		);
		$participantIDsMap = array_fill_keys(
			array_map( static fn ( Participant $p ): int => $p->getUser()->getCentralID(), $latestParticipants ),
			null
		);
		$participantNames = array_values( $this->centralUserLookup->getNames( $participantIDsMap ) );
		return $this->makePostRequest(
			'update_event_participants',
			$eventID,
			$toolEventID,
			$dryRun,
			[
				'participant_usernames' => $participantNames
			]
		);
	}

	/**
	 * @param string $endpoint
	 * @param int|null $eventID
	 * @param string $courseID
	 * @param bool $dryRun
	 * @param array<string,mixed> $extraParams
	 */
	private function makePostRequest(
		string $endpoint,
		?int $eventID,
		string $courseID,
		bool $dryRun,
		array $extraParams = []
	): StatusValue {
		$postData = $extraParams + [
			'course_slug' => $courseID,
			'secret' => $this->apiSecret,
			'dry_run' => $dryRun,
		];
		if ( $eventID !== null ) {
			$postData['event_id'] = $eventID;
		}
		$options = [
			'method' => 'POST',
			'timeout' => 5,
			'postData' => json_encode( $postData ),
			'logger' => LoggerFactory::getInstance( 'CampaignEvents' )
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
		$respObj = $this->parseResponseJSON( $req );
		if ( $respObj === null ) {
			return StatusValue::newFatal( 'campaignevents-tracking-tool-http-error' );
		}
		if ( $status->isGood() ) {
			return StatusValue::newGood();
		}
		return $this->makeErrorStatus( $respObj, $courseID );
	}

	/** @return array<string,mixed>|null */
	private function parseResponseJSON( MWHttpRequest $request ): ?array {
		$contentTypeHeader = $request->getResponseHeader( 'Content-Type' );
		if ( !$contentTypeHeader ) {
			return null;
		}
		$contentType = strtolower( explode( ';', $contentTypeHeader )[0] );
		if ( $contentType !== 'application/json' ) {
			return null;
		}

		try {
			$parsedResponse = json_decode( $request->getContent(), true, 512, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			return null;
		}

		return is_array( $parsedResponse ) ? $parsedResponse : null;
	}

	/**
	 * @param array<string,mixed> $response
	 * @param string $courseID
	 */
	private function makeErrorStatus( array $response, string $courseID ): StatusValue {
		if ( !isset( $response['error_code'] ) ) {
			return StatusValue::newFatal( 'campaignevents-tracking-tool-http-error' );
		}

		switch ( $response['error_code'] ) {
			case 'invalid_secret':
				$msg = 'campaignevents-tracking-tool-wikiedu-config-error';
				$params = [ new MessageValue( 'campaignevents-tracking-tool-p&e-dashboard-name' ) ];
				break;
			case 'course_not_found':
				$msg = 'campaignevents-tracking-tool-wikiedu-course-not-found-error';
				$params = [
					$courseID,
					new MessageValue( 'campaignevents-tracking-tool-p&e-dashboard-name' )
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
			case 'missing_event_id':
				// This should never happen.
				throw new LogicException( 'Made request to the Dashboard without an event ID.' );
			default:
				$msg = 'campaignevents-tracking-tool-http-error';
				$params = [];
				break;
		}
		return StatusValue::newFatal( $msg, ...$params );
	}

	/**
	 * @inheritDoc
	 */
	public static function buildToolEventURL( string $baseURL, string $toolEventID ): string {
		return rtrim( $baseURL, '/' ) . '/courses/' . $toolEventID;
	}

	/**
	 * @inheritDoc
	 */
	public static function extractEventIDFromURL( string $baseURL, string $url ): string {
		if ( str_starts_with( $url, '//' ) ) {
			// Protocol-relative, assume HTTPS
			$urlBits = parse_url( "https:$url" );
		} else {
			$urlBits = parse_url( $url );
			if ( !isset( $urlBits['scheme'] ) ) {
				// Missing protocol, assume HTTPS
				$urlBits = parse_url( "https://$url" );
			}
		}
		if ( $urlBits === false ) {
			throw new InvalidToolURLException( $baseURL, 'Badly malformed URL: ' . $url );
		}
		if ( !isset( $urlBits['scheme'] ) ) {
			// Probably shouldn't happen given the fixes above, but just to be sure...
			throw new InvalidToolURLException( $baseURL, 'No scheme: ' . $url );
		}
		if ( !isset( $urlBits['host'] ) ) {
			throw new InvalidToolURLException( $baseURL, 'No host: ' . $url );
		}
		$scheme = strtolower( $urlBits['scheme'] );
		if ( $scheme !== 'https' && $scheme !== 'http' ) {
			throw new InvalidToolURLException( $baseURL, 'Bad scheme: ' . $url );
		}
		$expectedHost = parse_url( $baseURL, PHP_URL_HOST );
		if ( strtolower( $urlBits['host'] ) !== $expectedHost ) {
			throw new InvalidToolURLException( $baseURL, 'Bad host: ' . $url );
		}
		if ( !isset( $urlBits['path'] ) ) {
			throw new InvalidToolURLException( $baseURL, 'No path: ' . $url );
		}

		$pathBits = explode( '/', trim( $urlBits['path'], '/' ) );
		if ( count( $pathBits ) !== 3 || $pathBits[0] !== 'courses' ) {
			throw new InvalidToolURLException( $baseURL, 'Invalid path: ' . $url );
		}

		return urldecode( $pathBits[1] ) . '/' . urldecode( $pathBits[2] );
	}
}
