<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\EventGoals;

use Generator;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\EventGoal\EventGoal;
use MediaWiki\Extension\CampaignEvents\EventGoal\EventGoalMetric;
use MediaWiki\Extension\CampaignEvents\EventGoal\EventGoalMetricType;
use MediaWiki\Extension\CampaignEvents\EventGoal\EventGoalStore;
use MediaWikiIntegrationTestCase;

/**
 * @group Test
 * @group Database
 * @covers \MediaWiki\Extension\CampaignEvents\EventGoal\EventGoalStore
 */
class EventGoalStoreTest extends MediaWikiIntegrationTestCase {

	private function newStore( bool $eventGoalFeatureEnabled ): EventGoalStore {
		return new EventGoalStore(
			CampaignEventsServices::getDatabaseHelper(),
			$eventGoalFeatureEnabled
		);
	}

	private function newEventGoal(): EventGoal {
		return new EventGoal(
			EventGoal::OPERATOR_AND,
			[
				new EventGoalMetric( EventGoalMetricType::TotalEdits, 100 ),
				new EventGoalMetric( EventGoalMetricType::TotalBytesAdded, 5000 ),
			]
		);
	}

	/**
	 * @dataProvider provideSetEventGoals
	 */
	public function testSetEventGoals(
		bool $initialHasGoals,
		bool $setNull,
		bool $expectedNull
	): void {
		$store = $this->newStore( true );
		$eventID = 42;

		if ( $initialHasGoals ) {
			$store->replaceEventGoal( $eventID, $this->newEventGoal() );
		}

		$valueToSet = $setNull ? null : $this->newEventGoal();
		$store->replaceEventGoal( $eventID, $valueToSet );

		$loaded = $store->getGoal( $eventID );
		if ( $expectedNull ) {
			$this->assertNull( $loaded );
		} else {
			$this->assertInstanceOf( EventGoal::class, $loaded );
			$this->assertSame( EventGoal::OPERATOR_AND, $loaded->getOperator() );
			$this->assertSameSize( $this->newEventGoal()->getMetrics(), $loaded->getMetrics() );
		}
	}

	public static function provideSetEventGoals(): Generator {
		yield 'set goals when none exist' => [
			'initialHasGoals' => false,
			'setNull' => false,
			'expectedNull' => false,
		];

		yield 'overwrite existing goals with new goals' => [
			'initialHasGoals' => true,
			'setNull' => false,
			'expectedNull' => false,
		];

		yield 'no goals remain unset when setting null' => [
			'initialHasGoals' => false,
			'setNull' => true,
			'expectedNull' => true,
		];

		yield 'clear existing goals by setting null' => [
			'initialHasGoals' => true,
			'setNull' => true,
			'expectedNull' => true,
		];
	}

	public function testGetEventGoalsMultiReturnsGoalsOrNullPerEvent(): void {
		$store = $this->newStore( true );
		$eventIDs = [ 1, 2, 3 ];

		$goal1 = $this->newEventGoal();
		$store->replaceEventGoal( 1, $goal1 );

		// Event 2 has null goal explicitly.
		$store->replaceEventGoal( 2, null );

		$result = $store->getGoalsMulti( $eventIDs );

		$this->assertArrayHasKey( 1, $result );
		$this->assertArrayHasKey( 2, $result );
		$this->assertArrayHasKey( 3, $result );

		$this->assertInstanceOf( EventGoal::class, $result[1] );
		$this->assertNull( $result[2] );
		$this->assertNull( $result[3] );
	}

	public function testStoreDoesNotReadOrWriteWhenGoalsFeatureDisabled(): void {
		$store = $this->newStore( false );
		$eventID = 100;

		// Writes are no-op.
		$store->replaceEventGoal( $eventID, $this->newEventGoal() );

		// Reads always return null / nulls.
		$this->assertNull( $store->getGoal( $eventID ) );

		$resultMulti = $store->getGoalsMulti( [ $eventID ] );
		$this->assertArrayHasKey( $eventID, $resultMulti );
		$this->assertNull( $resultMulti[$eventID] );
	}
}
