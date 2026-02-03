<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\EventGoal;

use MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionStore;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionSummary;
use MediaWiki\Extension\CampaignEvents\EventGoal\EventGoal;
use MediaWiki\Extension\CampaignEvents\EventGoal\EventGoalCompletionCalculator;
use MediaWiki\Extension\CampaignEvents\EventGoal\EventGoalMetric;
use MediaWiki\Extension\CampaignEvents\EventGoal\EventGoalMetricType;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\CampaignEvents\EventGoal\EventGoalCompletionCalculator
 */
class EventGoalCompletionCalculatorTest extends MediaWikiUnitTestCase {

	private EventContributionStore $storeMock;
	private EventGoalCompletionCalculator $calculator;

	protected function setUp(): void {
		$this->storeMock = $this->createMock( EventContributionStore::class );
		$this->calculator = new EventGoalCompletionCalculator( $this->storeMock );
	}

	/**
	 * @dataProvider completionProvider
	 */
	public function testCalculateCompletion(
		EventContributionSummary $summary,
		EventGoal $goals,
		float $expectedCompletion
	): void {
		$this->storeMock->method( 'getEventSummaryData' )
			->willReturn( $summary );

		$this->assertSame(
			$expectedCompletion,
			$this->calculator->calculateCompletion( $goals, 1, null, true )
		);
	}

	public static function completionProvider(): iterable {
		yield 'AND operator averages metrics' => [
			self::summary( [
				'editCount' => 150,
				'bytesAdded' => 5000,
				'linksAdded' => 200,
			] ),
			new EventGoal(
				operator: EventGoal::OPERATOR_AND,
				metrics: [
					new EventGoalMetric( EventGoalMetricType::TotalEdits, 300 ),
					new EventGoalMetric( EventGoalMetricType::TotalBytesAdded, 10000 ),
					new EventGoalMetric( EventGoalMetricType::TotalLinksAdded, 400 ),
				]
			),
			0.5,
		];

		yield 'OR operator picks max metric' => [
			self::summary( [
				'editCount' => 30,
				'bytesAdded' => 3000,
				'linksAdded' => 50,
			] ),
			new EventGoal(
				operator: EventGoal::OPERATOR_OR,
				metrics: [
					new EventGoalMetric( EventGoalMetricType::TotalEdits, 100 ),
					new EventGoalMetric( EventGoalMetricType::TotalBytesAdded, 5000 ),
					new EventGoalMetric( EventGoalMetricType::TotalLinksAdded, 200 ),
				]
			),
			0.6,
		];

		yield 'completion is capped at one' => [
			self::summary( [
				'editCount' => 200,
				'bytesAdded' => 12000,
				'linksAdded' => 500,
			] ),
			new EventGoal(
				operator: EventGoal::OPERATOR_AND,
				metrics: [
					new EventGoalMetric( EventGoalMetricType::TotalBytesAdded, 10000 ),
					new EventGoalMetric( EventGoalMetricType::TotalLinksAdded, 400 ),
				]
			),
			1.0,
		];

		yield 'removed bytes and links are handled correctly' => [
			self::summary( [
				'bytesRemoved' => -500,
				'linksRemoved' => -20,
			] ),
			new EventGoal(
				operator: EventGoal::OPERATOR_AND,
				metrics: [
					new EventGoalMetric( EventGoalMetricType::TotalBytesRemoved, 1000 ),
					new EventGoalMetric( EventGoalMetricType::TotalLinksRemoved, 40 ),
				]
			),
			0.5,
		];
	}

	public function testFloatingPointPrecisionIsHandledCorrectly(): void {
		$this->storeMock->method( 'getEventSummaryData' )
			->willReturn(
				self::summary( [
					'editCount' => 1,
					'bytesAdded' => 1,
					'linksAdded' => 1,
				] )
			);

		$goals = new EventGoal(
			operator: EventGoal::OPERATOR_AND,
			metrics: [
				new EventGoalMetric( EventGoalMetricType::TotalEdits, 3 ),
				new EventGoalMetric( EventGoalMetricType::TotalBytesAdded, 3 ),
				new EventGoalMetric( EventGoalMetricType::TotalLinksAdded, 3 ),
			]
		);

		$completion = $this->calculator->calculateCompletion( $goals, 1, null, true );

		$this->assertEqualsWithDelta( 1 / 3, $completion, 0.000001 );
	}

	private static function summary( array $overrides = [] ): EventContributionSummary {
		$defaults = [
			'participantsCount' => 0,
			'wikisEditedCount' => 0,
			'articlesCreatedCount' => 0,
			'articlesEditedCount' => 0,
			'bytesAdded' => 0,
			'bytesRemoved' => 0,
			'linksAdded' => 0,
			'linksRemoved' => 0,
			'editCount' => 0,
		];

		$data = array_replace( $defaults, $overrides );

		return new EventContributionSummary(
			participantsCount: $data['participantsCount'],
			wikisEditedCount: $data['wikisEditedCount'],
			articlesCreatedCount: $data['articlesCreatedCount'],
			articlesEditedCount: $data['articlesEditedCount'],
			bytesAdded: $data['bytesAdded'],
			bytesRemoved: $data['bytesRemoved'],
			linksAdded: $data['linksAdded'],
			linksRemoved: $data['linksRemoved'],
			editCount: $data['editCount']
		);
	}
}
