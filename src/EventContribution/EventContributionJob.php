<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\EventContribution;

use InvalidArgumentException;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\JobQueue\GenericParameterJob;
use MediaWiki\JobQueue\Job;

/**
 * Job to associate an edit with an event and calculate metrics
 */
class EventContributionJob extends Job implements GenericParameterJob {
	private int $eventID;
	private int $revisionID;
	private int $userID;
	private string $wiki;

	/**
	 * @inheritDoc
	 * @phan-param array{eventId:int,revisionId:int,userId:int,wiki:string} $params
	 * @param array $params
	 */
	public function __construct( array $params ) {
		parent::__construct( 'CampaignEventsComputeEventContribution', $params );
		$missingParams = array_diff(
			[ 'eventId', 'revisionId', 'userId', 'wiki' ],
			array_keys( $params )
		);
		if ( $missingParams ) {
			throw new InvalidArgumentException( "Missing parameters: " . implode( ', ', $missingParams ) );
		}
		$this->eventID = $params['eventId'];
		$this->revisionID = $params['revisionId'];
		$this->userID = $params['userId'];
		$this->wiki = $params['wiki'];
	}

	/**
	 * @inheritDoc
	 */
	public function run(): bool {
		$computeMetrics = CampaignEventsServices::getEventContributionComputeMetrics();
		$store = CampaignEventsServices::getEventContributionStore();

		$contribution = $computeMetrics->computeEventContribution(
			$this->revisionID,
			$this->eventID,
			$this->userID,
			$this->wiki
		);

		$store->saveEventContribution( $contribution );

		return true;
	}
}
