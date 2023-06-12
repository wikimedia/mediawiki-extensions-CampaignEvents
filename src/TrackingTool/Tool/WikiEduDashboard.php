<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\TrackingTool\Tool;

use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\Participants\Participant;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Http\HttpRequestFactory;
use Message;
use StatusValue;
use Wikimedia\Assert\Assert;

/**
 * This class implements the WikiEduDashboard software as a tracking tool.
 */
class WikiEduDashboard extends TrackingTool {
	/** @var HttpRequestFactory */
	private HttpRequestFactory $httpRequestFactory;
	/** @var CampaignsCentralUserLookup */
	private CampaignsCentralUserLookup $centralUserLookup;
	/** @var ParticipantsStore */
	private ParticipantsStore $participantsStore;

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
	 * @return StatusValue
	 */
	private function makeNewEventRequest(
		?int $eventID,
		array $organizers,
		string $toolEventID,
		bool $dryRun
	): StatusValue {
		Assert::precondition( $eventID !== null || $dryRun, 'Cannot sync tools with events without ID' );
		$organizerIDsMap = array_fill_keys(
			array_map( static fn( CentralUser $u ) => $u->getCentralID(), $organizers ),
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
		return $this->makePostRequest(
			'unsync_event',
			$event->getID(),
			$toolEventID,
			true
		);
	}

	/**
	 * @inheritDoc
	 */
	public function removeFromEvent( ExistingEventRegistration $event, string $toolEventID ): StatusValue {
		return $this->makePostRequest(
			'unsync_event',
			$event->getID(),
			$toolEventID,
			false
		);
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
		if ( $private ) {
			// Private participants are not synced, so don't bother making a request.
			return StatusValue::newGood();
		}
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
		if ( $private ) {
			// Private participants are not synced, so don't bother making a request.
			return StatusValue::newGood();
		}
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

	/**
	 * @param ExistingEventRegistration $event
	 * @param string $toolEventID
	 * @param bool $dryRun
	 * @return StatusValue
	 */
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
			ParticipantsStore::READ_LATEST
		);
		$participantIDsMap = array_fill_keys(
			array_map( static fn( Participant $p ) => $p->getUser()->getCentralID(), $latestParticipants ),
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
	 * @param array $extraParams
	 * @return StatusValue
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
			'postData' => json_encode( $postData )
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

	/**
	 * @inheritDoc
	 */
	public static function buildToolEventURL( string $baseURL, string $toolEventID ): string {
		return rtrim( $baseURL, '/' ) . '/courses/' . $toolEventID;
	}
}
