<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\EventGoal;

class EventGoalCompletionResult {

	public function __construct(
		private readonly float $completionRatio,
		private readonly EventGoalMetricType $primaryMetricType,
		private readonly int $primaryMetricCurrent,
	) {
	}

	public function getCompletionRatio(): float {
		return $this->completionRatio;
	}

	public function getPrimaryMetricType(): EventGoalMetricType {
		return $this->primaryMetricType;
	}

	public function getPrimaryMetricCurrent(): int {
		return $this->primaryMetricCurrent;
	}
}
