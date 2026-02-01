<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\EventGoal;

use MediaWiki\Extension\CampaignEvents\EventGoal\EventGoalMetric;
use MediaWiki\Extension\CampaignEvents\EventGoal\EventGoalMetricType;
use MediaWikiUnitTestCase;
use ValueError;
use Wikimedia\Assert\ParameterAssertionException;

/**
 * @covers \MediaWiki\Extension\CampaignEvents\EventGoal\EventGoalMetric
 */
class EventGoalMetricTest extends MediaWikiUnitTestCase {

	public function testNewFromArrayAndToArray() {
		$data = [
			'metric' => EventGoalMetricType::TotalEdits->value,
			'target' => 100,
		];

		$metric = EventGoalMetric::newFromArray( $data );

		$this->assertSame( EventGoalMetricType::TotalEdits, $metric->getMetric() );
		$this->assertSame( 100, $metric->getTarget() );
		$this->assertSame( $data, $metric->toArray() );
	}

	public function testNewFromArrayInvalidMetricTypeThrows() {
		$this->expectException( ValueError::class );

		EventGoalMetric::newFromArray( [
			'metric' => 'not-a-valid-metric',
			'target' => 10,
		] );
	}

	/**
	 * @dataProvider provideInvalidMetricData
	 */
	public function testNewFromArrayMissingTargetThrows(
		array $data,
		string $expectedMessagePart
	) {
		$this->expectException( ParameterAssertionException::class );
		$this->expectExceptionMessage( $expectedMessagePart );

		EventGoalMetric::newFromArray( $data );
	}

	public static function provideInvalidMetricData(): array {
		return [
			'missing target key' => [
				[
					'metric' => EventGoalMetricType::TotalEdits->value,
					// 'target' missing
				],
				'"target" key',
			],
		];
	}
}
