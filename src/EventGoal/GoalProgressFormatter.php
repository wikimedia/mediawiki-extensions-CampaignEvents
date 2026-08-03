<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\EventGoal;

use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Permissions\Authority;
use Wikimedia\Message\IMessageFormatterFactory;
use Wikimedia\Message\MessageValue;

class GoalProgressFormatter {

	public const SERVICE_NAME = 'CampaignEventsGoalProgressFormatter';

	public function __construct(
		private readonly CampaignsCentralUserLookup $centralUserLookup,
		private readonly PermissionChecker $permissionChecker,
		private readonly EventGoalCompletionCalculator $goalCompletionCalculator,
		private readonly IMessageFormatterFactory $messageFormatterFactory,
	) {
	}

	/**
	 * @return array{heading:string,description:string,percentComplete:int,numericText:string}|null
	 */
	public function getProgressData(
		ExistingEventRegistration $event,
		Authority $authority,
		string $languageCode
	): ?array {
		$goal = $event->getGoal();
		if ( $goal === null ) {
			return null;
		}

		$eventId = $event->getID();
		try {
			$centralUser = $this->centralUserLookup->newFromAuthority( $authority );
			$includePrivateParticipants = $this->permissionChecker->userCanViewPrivateParticipants(
				$authority,
				$event
			);
		} catch ( UserNotGlobalException ) {
			$centralUser = null;
			$includePrivateParticipants = false;
		}

		$result = $this->goalCompletionCalculator->calculateCompletion(
			$goal,
			$eventId,
			$centralUser,
			$includePrivateParticipants
		);
		$goalPercentComplete = (int)floor( $result->getCompletionRatio() * 100 );

		$primaryMetric = $goal->getMetrics()[0];
		$goalTarget = $primaryMetric->getTarget();
		$goalCurrent = $result->getPrimaryMetricCurrent();

		$metricLabelKey = match ( $result->getPrimaryMetricType() ) {
			EventGoalMetricType::TotalArticlesCreated => 'campaignevents-goal-progress-metric-total_articles_created',
			EventGoalMetricType::TotalArticlesEdited => 'campaignevents-goal-progress-metric-total_articles_edited',
			EventGoalMetricType::TotalEdits => 'campaignevents-goal-progress-metric-total_edits',
			EventGoalMetricType::TotalBytesAdded => 'campaignevents-goal-progress-metric-total_bytes_added',
			EventGoalMetricType::TotalBytesRemoved => 'campaignevents-goal-progress-metric-total_bytes_removed',
			EventGoalMetricType::TotalLinksAdded => 'campaignevents-goal-progress-metric-total_links_added',
			EventGoalMetricType::TotalLinksRemoved => 'campaignevents-goal-progress-metric-total_links_removed',
		};

		$msgFormatter = $this->messageFormatterFactory->getTextFormatter( $languageCode );

		$goalMetricLabel = $msgFormatter->format( MessageValue::new( $metricLabelKey )->numParams( $goalTarget ) );

		return [
			'heading' => $msgFormatter->format(
				MessageValue::new( 'campaignevents-goal-progress-heading' )
			),
			'description' => $msgFormatter->format(
				MessageValue::new( 'campaignevents-goal-progress-description' )->params( $goalMetricLabel )
			),
			'percentComplete' => $goalPercentComplete,
			'numericText' => $msgFormatter->format(
				MessageValue::new( 'campaignevents-goal-progress-numeric' )
					->numParams( $goalCurrent, $goalTarget )
			),
		];
	}
}
