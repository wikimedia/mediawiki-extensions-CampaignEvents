<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\EventGoal;

use Wikimedia\Assert\Assert;

/**
 * Value object representing the complete goal structure for an event.
 * Matches the JSON format stored in ce_event_goals.ceeg_goals:
 * {
 *   "operator": "AND",
 *   "metrics": [
 *     { "metric": "total_edits", "target": 5000 },
 *     { "metric": "total_bytes_added", "target": 10000 }
 *   ]
 * }
 */
class EventGoal {
	public const OPERATOR_AND = 'AND';
	public const OPERATOR_OR = 'OR';
	public const VALID_OPERATORS = [ self::OPERATOR_AND, self::OPERATOR_OR ];

	/**
	 * @param string $operator How multiple metrics are combined ("AND" or "OR")
	 * @param non-empty-list<EventGoalMetric> $metrics Array of goal metrics (at least one required)
	 */
	public function __construct(
		private readonly string $operator,
		private readonly array $metrics
	) {
		Assert::parameter(
			in_array( $operator, self::VALID_OPERATORS, true ),
			'$operator',
			'Operator must be one of: ' . implode( ', ', self::VALID_OPERATORS )
		);
		Assert::parameter( $metrics !== [], '$metrics', 'At least one metric is required' );
		foreach ( $metrics as $metric ) {
			Assert::parameter(
				$metric instanceof EventGoalMetric,
				'$metrics',
				'All metrics must be EventGoalMetric instances'
			);
		}
	}

	public function getOperator(): string {
		return $this->operator;
	}

	/**
	 * @return non-empty-list<EventGoalMetric>
	 */
	public function getMetrics(): array {
		return $this->metrics;
	}

	/**
	 * Create an EventGoal instance from an array (e.g., from JSON deserialization).
	 *
	 * @param array $data
	 * @phan-param array{operator:string,metrics:non-empty-list<array{metric:string,target:positive-int}>} $data
	 */
	public static function newFromArray( array $data ): self {
		Assert::parameter(
			isset( $data['operator'] ) && is_string( $data['operator'] ),
			'$data',
			'Must contain a string "operator" key'
		);
		Assert::parameter(
			isset( $data['metrics'] ) && is_array( $data['metrics'] ) && $data['metrics'] !== [],
			'$data',
			'Must contain a non-empty array "metrics" key'
		);

		$metrics = [];
		foreach ( $data['metrics'] as $metricData ) {
			$metrics[] = EventGoalMetric::newFromArray( $metricData );
		}

		return new self( $data['operator'], $metrics );
	}

	/**
	 * Convert to array format (for JSON serialization).
	 *
	 * @return array{operator: string, metrics: non-empty-list<array{metric: string, target: int}>}
	 */
	public function toArray(): array {
		return [
			'operator' => $this->operator,
			'metrics' => array_map(
				/** @return array{metric: string, target: int} */
				static fn ( EventGoalMetric $metric ): array => $metric->toArray(),
				$this->metrics
			),
		];
	}
}
