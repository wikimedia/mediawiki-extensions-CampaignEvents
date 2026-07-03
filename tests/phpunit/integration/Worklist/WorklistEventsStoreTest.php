<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Worklist;

use Generator;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\CampaignEvents\Worklist\WorklistEventsStore
 * @group Database
 */
class WorklistEventsStoreTest extends MediaWikiIntegrationTestCase {

	public function addDBData() {
		$rows = [];
		foreach ( self::initialPairs() as $i => $pair ) {
			$rows[] = [ 'cewe_id' => $i + 1 ] + $pair;
		}
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'ce_worklist_events' )
			->rows( $rows )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * @return array<array{cewe_cew_id:int,cewe_event_id:int}>
	 */
	private static function initialPairs(): array {
		return [
			[ 'cewe_cew_id' => 10, 'cewe_event_id' => 100 ],
			[ 'cewe_cew_id' => 20, 'cewe_event_id' => 200 ],
		];
	}

	/**
	 * @param array<array{cewe_cew_id:int,cewe_event_id:int}> $expectedPairs
	 */
	private function assertStoredPairs( array $expectedPairs ): void {
		$res = $this->getDb()->newSelectQueryBuilder()
			->select( [ 'cewe_cew_id', 'cewe_event_id' ] )
			->from( 'ce_worklist_events' )
			->orderBy( 'cewe_id' )
			->caller( __METHOD__ )
			->fetchResultSet();
		$actualPairs = [];
		foreach ( $res as $row ) {
			$actualPairs[] = [
				'cewe_cew_id' => (int)$row->cewe_cew_id,
				'cewe_event_id' => (int)$row->cewe_event_id,
			];
		}
		$this->assertEquals( $expectedPairs, $actualPairs );
	}

	public function testAssociateEventWithWorklist(): void {
		$store = CampaignEventsServices::getWorklistEventsStore();
		$store->associateEventWithWorklist( 300, 30 );

		$this->assertStoredPairs( [
			...self::initialPairs(),
			[ 'cewe_cew_id' => 30, 'cewe_event_id' => 300 ],
		] );
	}

	public function testAssociateEventWithWorklist__isIdempotent(): void {
		$store = CampaignEventsServices::getWorklistEventsStore();
		// Calling twice for an existing (worklist, event) pair must not throw (INSERT IGNORE) and
		// must not create a duplicate row.
		$store->associateEventWithWorklist( 100, 10 );
		$store->associateEventWithWorklist( 100, 10 );

		$this->assertStoredPairs( self::initialPairs() );
	}

	/**
	 * @dataProvider provideGetWorklistIDForEvent
	 */
	public function testGetWorklistIDForEvent( int $eventID, ?int $expected ): void {
		$store = CampaignEventsServices::getWorklistEventsStore();
		$this->assertSame( $expected, $store->getWorklistIDForEvent( $eventID ) );
	}

	public static function provideGetWorklistIDForEvent(): Generator {
		yield 'Exists' => [ 100, 10 ];
		yield 'Does not exist' => [ 999, null ];
	}

	public function testRemoveWorklistAssociation(): void {
		$store = CampaignEventsServices::getWorklistEventsStore();
		$store->removeWorklistAssociation( 10, 100 );

		$this->assertStoredPairs( [
			[ 'cewe_cew_id' => 20, 'cewe_event_id' => 200 ],
		] );
	}

	public function testRemoveWorklistAssociation__nonMatchingPairIsNoOp(): void {
		$store = CampaignEventsServices::getWorklistEventsStore();
		// The worklist exists but not paired with this event: nothing should be removed.
		$store->removeWorklistAssociation( 10, 999 );

		$this->assertStoredPairs( self::initialPairs() );
	}
}
