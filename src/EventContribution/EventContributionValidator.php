<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\EventContribution;

use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\Rest\FailStatusUtilTrait;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\Permissions\Authority;
use MediaWiki\Rest\HttpException;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Revision\RevisionStoreFactory;
use Wikimedia\Message\MessageValue;

class EventContributionValidator {
	use FailStatusUtilTrait;

	public const SERVICE_NAME = 'CampaignEventsEventContributionValidator';

	public function __construct(
		private readonly CampaignsCentralUserLookup $centralUserLookup,
		private readonly JobQueueGroup $jobQueueGroup,
		private readonly RevisionStoreFactory $revisionStoreFactory,
		private readonly EventContributionStore $eventContributionStore,
		private readonly PermissionChecker $permissionChecker,
	) {
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

		// Verify that the edit was made by the user making this API request, or the event organiser
		$revisionAuthor = $revision->getUser();

		// Get the central user ID for the revision author
		$revisionAuthorCentralId = $this->centralUserLookup->newFromUserIdentity( $revisionAuthor )
			->getCentralID();

		// Verify that the edit was made by the user making this API request, or an organizer
		$userCanAddContribution = $this->permissionChecker->userCanAddContribution(
			$performer,
			$event,
			$revisionAuthorCentralId
		);
		if ( !$userCanAddContribution->isOK() ) {
			$this->exitWithStatus( $userCanAddContribution, 403 );
		}
		// Create job message and push to queue
		$jobParams = [
			'revisionId' => $revisionID,
			'wiki' => $wikiID,
			'eventId' => $event->getID(),
			'userId' => $revisionAuthorCentralId,
		];
		$associateEditJob = new EventContributionJob( $jobParams );
		$this->jobQueueGroup->push( $associateEditJob );
	}
}
