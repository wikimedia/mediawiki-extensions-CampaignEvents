<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\EventGoal;

use MediaWiki\Extension\CampaignEvents\EventGoal\EventGoal;
use MediaWiki\Extension\CampaignEvents\EventGoal\EventGoalMetricType;
use MediaWikiUnitTestCase;
use Wikimedia\Assert\ParameterAssertionException;

/**
 * @covers \MediaWiki\Extension\CampaignEvents\EventGoal\EventGoal
 */
class EventGoalTest extends MediaWikiUnitTestCase {

	public function testNewFromArrayAndToArray() {
		$metricData = [
			'metric' => EventGoalMetricType::TotalEdits->value,
			'target' => 50,
		];
		$data = [
			'operator' => EventGoal::OPERATOR_AND,
			'metrics' => [ $metricData ],
		];

		$goal = EventGoal::newFromArray( $data );

		$this->assertSame( EventGoal::OPERATOR_AND, $goal->getOperator() );
		$metrics = $goal->getMetrics();
		$this->assertCount( 1, $metrics );
		$this->assertSame( EventGoalMetricType::TotalEdits, $metrics[0]->getMetric() );
		$this->assertSame( $metricData['target'], $metrics[0]->getTarget() );
		$this->assertSame( $data, $goal->toArray() );
	}

	public function testNewFromArrayInvalidOperatorThrows() {
		$this->expectException( ParameterAssertionException::class );
		$this->expectExceptionMessage( 'Operator must be one of' );

		EventGoal::newFromArray( [
			'operator' => 'NOT_A_VALID_OPERATOR',
			'metrics' => [ [ 'metric' => EventGoalMetricType::TotalEdits->value, 'target' => 1 ] ],
		] );
	}

	public function testNewFromArrayEmptyMetricsThrows() {
		$this->expectException( ParameterAssertionException::class );
		$this->expectExceptionMessage( 'non-empty' );

		EventGoal::newFromArray( [
			'operator' => EventGoal::OPERATOR_AND,
			'metrics' => [],
		] );
	}
}
