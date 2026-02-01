<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\EventGoal;

use Wikimedia\Assert\Assert;

/**
 * Value object representing a single metric within event goals.
 * Each metric has a type (from EventGoalMetricType) and a target value (as an integer).
 */
class EventGoalMetric {
	private readonly EventGoalMetricType $metric;

	/**
	 * @param EventGoalMetricType $metric The metric type
	 * @param positive-int $target The target value (positive integer)
	 */
	public function __construct(
		EventGoalMetricType $metric,
		private readonly int $target
	) {
		Assert::parameter( $target > 0, '$target', 'Target must be a positive integer' );
		$this->metric = $metric;
	}

	public function getMetric(): EventGoalMetricType {
		return $this->metric;
	}

	/**
	 * @return positive-int
	 */
	public function getTarget(): int {
		return $this->target;
	}

	/**
	 * Create an EventGoalMetric from an array (e.g., from JSON deserialization).
	 * The "metric" key must be a valid EventGoalMetricType backing value (string).
	 *
	 * @param array{metric: string, target: positive-int} $data Must contain 'metric' and 'target' keys
	 */
	public static function newFromArray( array $data ): self {
		Assert::parameter(
			isset( $data['metric'] ) && is_string( $data['metric'] ),
			'$data',
			'Must contain a string "metric" key'
		);
		$targetValid = isset( $data['target'] ) && ( is_int( $data['target'] ) );
		Assert::parameter(
			$targetValid,
			'$data',
			'Must contain an integer "target" key'
		);
		$metric = EventGoalMetricType::from( $data['metric'] );
		$target = (int)$data['target'];
		Assert::parameter( $target > 0, '$data', 'Target must be positive integer' );
		// Phan does not narrow int to positive-int after Assert::parameter; runtime guarantees target > 0
		/** @phan-suppress-next-line PhanTypeMismatchArgument */
		return new self( $metric, $target );
	}

	/**
	 * Convert to array format (for JSON serialization).
	 *
	 * @return array{metric: string, target: positive-int}
	 */
	public function toArray(): array {
		return [
			'metric' => $this->metric->value,
			'target' => $this->target,
		];
	}
}
