<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\EventContribution;

use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\Permissions\Authority;
use MediaWiki\Rest\HttpException;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Revision\RevisionStoreFactory;
use MediaWiki\Utils\MWTimestamp;
use Wikimedia\Message\MessageValue;

class EventContributionValidator {
	public const SERVICE_NAME = 'CampaignEventsEventContributionValidator';
	private const MAX_REVISION_AGE = 24 * 60 * 60;

	private CampaignsCentralUserLookup $centralUserLookup;
	private ParticipantsStore $participantsStore;
	private JobQueueGroup $jobQueueGroup;
	private RevisionStoreFactory $revisionStoreFactory;
	private EventContributionStore $eventContributionStore;

	public function __construct(
		CampaignsCentralUserLookup $centralUserLookup,
		ParticipantsStore $participantsStore,
		JobQueueGroup $jobQueueGroup,
		RevisionStoreFactory $revisionStoreFactory,
		EventContributionStore $eventContributionStore,
	) {
		$this->centralUserLookup = $centralUserLookup;
		$this->participantsStore = $participantsStore;
		$this->jobQueueGroup = $jobQueueGroup;
		$this->revisionStoreFactory = $revisionStoreFactory;
		$this->eventContributionStore = $eventContributionStore;
	}

	/**
	 * Validate and schedule event contribution
	 *
	 * @param ExistingEventRegistration $event
	 * @param int $revisionID
	 * @param string $wikiID
	 * @param Authority $performer
	 * @throws HttpException|LocalizedHttpException
	 */
	public function validateAndSchedule(
		ExistingEventRegistration $event,
		int $revisionID,
		string $wikiID,
		Authority $performer
	): void {
		// Get central user
		try {
			$centralUser = $this->centralUserLookup->newFromAuthority( $performer );
		} catch ( UserNotGlobalException ) {
			throw new LocalizedHttpException(
				MessageValue::new( 'campaignevents-event-contribution-user-not-global' ),
				403
			);
		}

		$previousEventID = $this->eventContributionStore->getEventIDForRevision( $wikiID, $revisionID );
		if ( $previousEventID === $event->getID() ) {
			// Already associated with this event, nothing to do, a successful response is still valid.
			return;
		} elseif ( $previousEventID !== null ) {
			throw new LocalizedHttpException(
				MessageValue::new( 'campaignevents-event-contribution-already-associated' ),
				400
			);
		}

		// Check if event has contribution tracking enabled
		if ( !$event->hasContributionTracking() ) {
			throw new LocalizedHttpException(
				MessageValue::new( 'campaignevents-event-contribution-tracking-disabled' ),
				400
			);
		}

		// Check if event is deleted
		if ( $event->getDeletionTimestamp() !== null ) {
			throw new LocalizedHttpException(
				MessageValue::new( 'campaignevents-event-contribution-event-deleted' ),
				404
			);
		}

		if ( !$event->isOngoing() ) {
			throw new LocalizedHttpException(
				MessageValue::new( 'campaignevents-event-contribution-event-not-active' ),
				400
			);
		}

		// Check is wiki is a target for this event
		$wikiIsTarget =
			( is_array( $event->getWikis() ) && in_array( $wikiID, $event->getWikis(), true ) )
			|| $event->getWikis() === EventRegistration::ALL_WIKIS;
		if ( !$wikiIsTarget ) {
			throw new LocalizedHttpException(
				MessageValue::new( 'campaignevents-event-contribution-not-target-wiki' ),
				400
			);
		}

		// Get revision store for the specific wiki
		$revisionStore = $this->revisionStoreFactory->getRevisionStore( $wikiID );

		// Get revision details
		$revision = $revisionStore->getRevisionById( $revisionID );
		if ( !$revision ) {
			throw new LocalizedHttpException(
				MessageValue::new( 'campaignevents-event-contribution-revision-not-found' ),
				404
			);
		}

		// Validate revision timestamp (within reasonable time window)
		$editTime = $revision->getTimestamp();

		// Convert timestamps to UNIX format for comparison
		$editTimeUnix = (int)wfTimestamp( TS_UNIX, $editTime );
		$currentTimeUnix = (int)MWTimestamp::now( TS_UNIX );

		if ( $editTimeUnix < ( $currentTimeUnix - self::MAX_REVISION_AGE ) ) {
			throw new LocalizedHttpException(
				MessageValue::new( 'campaignevents-event-contribution-timestamp-too-old' ),
				400
			);
		}

		// Verify that the edit was made by the user making this API request
		$revisionAuthor = $revision->getUser();

		// Get the central user ID for the revision author
		$revisionAuthorCentralId = $this->centralUserLookup->newFromUserIdentity( $revisionAuthor )
			->getCentralID();

		// Verify that the edit was made by the user making this API request
		if ( $revisionAuthorCentralId !== $centralUser->getCentralID() ) {
			throw new LocalizedHttpException(
				MessageValue::new( 'campaignevents-event-contribution-not-owner' ),
				403
			);
		}

		// Check if user participates in the event
		if ( !$this->participantsStore->userParticipatesInEvent( $event->getID(), $centralUser, true ) ) {
			throw new LocalizedHttpException(
				MessageValue::new( 'campaignevents-event-contribution-not-participant' ),
				403
			);
		}

		// Create job message and push to queue
		$jobParams = [
			'revisionId' => $revisionID,
			'wiki' => $wikiID,
			'eventId' => $event->getID(),
			'userId' => $centralUser->getCentralID(),
		];
		$associateEditJob = new EventContributionJob( $jobParams );
		$this->jobQueueGroup->push( $associateEditJob );
	}
}
