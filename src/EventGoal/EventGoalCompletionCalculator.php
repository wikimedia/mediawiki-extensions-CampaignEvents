<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\EventGoal;

use InvalidArgumentException;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionStore;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionSummary;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;

class EventGoalCompletionCalculator {

	public const SERVICE_NAME = 'CampaignEventsEventGoalCompletionCalculator';

	public function __construct(
		private EventContributionStore $contribStore,
	) {
	}

	/**
	 * Compute the completion percentage for an event's goals.
	 *
	 * @param EventGoal $goal
	 * @param int $eventId
	 * @param CentralUser|null $currentUser
	 * @param bool $includePrivateParticipants Whether to include other users' private contributions
	 *
	 * @return float Value between 0.0 and 1.0
	 */
	public function calculateCompletion(
		EventGoal $goal,
		int $eventId,
		?CentralUser $currentUser,
		bool $includePrivateParticipants
	): float {
		$summary = $this->contribStore->getEventSummaryData(
			$eventId,
			$currentUser,
			$includePrivateParticipants
		);

		$metrics = $goal->getMetrics();
		$completions = [];
		foreach ( $metrics as $metric ) {
			$actual = $this->mapMetricToValue( $metric, $summary );
			$target = $metric->getTarget();
			$completions[] = min( $actual / $target, 1.0 );
		}

		return match ( $goal->getOperator() ) {
			EventGoal::OPERATOR_AND => array_sum( $completions ) / count( $completions ),
			EventGoal::OPERATOR_OR => max( $completions ),
			default => throw new InvalidArgumentException( 'Unknown goal operator' ),
		};
	}

	private function mapMetricToValue( EventGoalMetric $metric, EventContributionSummary $summary ): int {
			return match ( $metric->getMetric() ) {
				EventGoalMetricType::TotalEdits => $summary->getEditCount(),
				EventGoalMetricType::TotalBytesAdded => $summary->getBytesAdded(),
				EventGoalMetricType::TotalLinksAdded => $summary->getLinksAdded(),
				EventGoalMetricType::TotalBytesRemoved => abs( $summary->getBytesRemoved() ),
				EventGoalMetricType::TotalLinksRemoved => abs( $summary->getLinksRemoved() ),
				EventGoalMetricType::TotalArticlesCreated => $summary->getArticlesCreatedCount(),
				EventGoalMetricType::TotalArticlesEdited => $summary->getArticlesEditedCount(),
			};
	}
}
