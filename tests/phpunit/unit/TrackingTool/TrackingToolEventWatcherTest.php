<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\TrackingTool;

use Generator;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\TrackingTool\Tool\TrackingTool;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolAssociation;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolEventWatcher;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolRegistry;
use MediaWikiUnitTestCase;
use StatusValue;

/**
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolEventWatcher
 * @covers ::__construct
 */
class TrackingToolEventWatcherTest extends MediaWikiUnitTestCase {
	private function getWatcher( TrackingToolRegistry $registry = null ): TrackingToolEventWatcher {
		return new TrackingToolEventWatcher(
			$registry ?? $this->createMock( TrackingToolRegistry::class )
		);
	}

	private function getAssoc( int $toolID, string $toolEventID = 'foobar' ): TrackingToolAssociation {
		return new TrackingToolAssociation( $toolID, $toolEventID, TrackingToolAssociation::SYNC_STATUS_UNKNOWN, null );
	}

	/**
	 * @covers ::validateEventCreation
	 * @dataProvider provideValidateEventCreation
	 */
	public function testValidateEventCreation(
		?TrackingToolRegistry $registry,
		EventRegistration $event,
		StatusValue $expected
	) {
		$this->assertEquals(
			$expected,
			$this->getWatcher( $registry )->validateEventCreation( $event, [] )
		);
	}

	public function provideValidateEventCreation(): Generator {
		$eventWithoutTools = $this->createMock( EventRegistration::class );
		$eventWithoutTools->method( 'getTrackingTools' )->willReturn( [] );
		yield 'No tool' => [ null, $eventWithoutTools, StatusValue::newGood() ];

		$toolID = 1;
		$eventWithTool = $this->createMock( EventRegistration::class );
		$eventWithTool->method( 'getTrackingTools' )->willReturn( [ $this->getAssoc( $toolID ) ] );

		$toolError = StatusValue::newFatal( 'some-error' );
		$toolWithError = $this->createMock( TrackingTool::class );
		$toolWithError->expects( $this->atLeastOnce() )
			->method( 'validateToolAddition' )
			->willReturn( $toolError );
		$toolWithErrorRegistry = $this->createMock( TrackingToolRegistry::class );
		$toolWithErrorRegistry->method( 'newFromDBID' )->with( $toolID )->willReturn( $toolWithError );
		yield 'Add tool, error' => [ $toolWithErrorRegistry, $eventWithTool, $toolError ];

		$toolSuccessStatus = StatusValue::newGood();
		$successfulTool = $this->createMock( TrackingTool::class );
		$successfulTool->expects( $this->atLeastOnce() )
			->method( 'validateToolAddition' )
			->willReturn( $toolSuccessStatus );
		$successfulToolRegistry = $this->createMock( TrackingToolRegistry::class );
		$successfulToolRegistry->method( 'newFromDBID' )->with( $toolID )->willReturn( $successfulTool );
		yield 'Add tool, successful' => [ $successfulToolRegistry, $eventWithTool, $toolSuccessStatus ];
	}

	/**
	 * @covers ::validateEventUpdate
	 * @covers ::splitToolsForEventUpdate
	 * @dataProvider provideValidateEventUpdate
	 */
	public function testValidateEventUpdate(
		?TrackingToolRegistry $registry,
		ExistingEventRegistration $oldEvent,
		EventRegistration $newEvent,
		StatusValue $expected
	) {
		$this->assertEquals(
			$expected,
			$this->getWatcher( $registry )->validateEventUpdate( $oldEvent, $newEvent, [] )
		);
	}

	public function provideValidateEventUpdate(): Generator {
		$eventWithoutTools = $this->createMock( ExistingEventRegistration::class );
		$eventWithoutTools->method( 'getTrackingTools' )->willReturn( [] );
		yield 'No tool before, no tool after' => [
			null,
			$eventWithoutTools,
			$eventWithoutTools,
			StatusValue::newGood()
		];

		$tool1ID = 1;
		$eventWithTool1ToolEventID = 'something';
		$eventWithTool1 = $this->createMock( ExistingEventRegistration::class );
		$eventWithTool1->method( 'getTrackingTools' )
			->willReturn( [ $this->getAssoc( $tool1ID, $eventWithTool1ToolEventID ) ] );
		$tool2ID = 2;
		$eventWithTool2 = $this->createMock( ExistingEventRegistration::class );
		$eventWithTool2->method( 'getTrackingTools' )->willReturn( [ $this->getAssoc( $tool2ID ) ] );

		/**
		 * @param array<int,TrackingTool> $toolMap Map of tool ID to TrackingTool object to return for that ID.
		 */
		$getRegistryMock = function ( array $toolMap ): TrackingToolRegistry {
			$ret = $this->createMock( TrackingToolRegistry::class );
			$ret->method( 'newFromDBID' )->willReturnCallback( fn ( int $toolID ) => $toolMap[$toolID] );
			return $ret;
		};

		$toolAdditionError = StatusValue::newFatal( 'some-error-for-tool-addition' );
		$toolWithErrorOnAddition = $this->createMock( TrackingTool::class );
		$toolWithErrorOnAddition->method( 'validateToolAddition' )->willReturn( $toolAdditionError );

		$successfulAdditionStatus = StatusValue::newGood();
		$toolWithSuccessfulAddition = $this->createMock( TrackingTool::class );
		$toolWithSuccessfulAddition->method( 'validateToolAddition' )->willReturn( $successfulAdditionStatus );

		yield 'Had no tools, adding one, error' => [
			$getRegistryMock( [ $tool1ID => $toolWithErrorOnAddition ] ),
			$eventWithoutTools,
			$eventWithTool1,
			$toolAdditionError
		];
		yield 'Had no tools, adding one, success' => [
			$getRegistryMock( [ $tool1ID => $toolWithSuccessfulAddition ] ),
			$eventWithoutTools,
			$eventWithTool1,
			$successfulAdditionStatus
		];

		$toolRemovalError = StatusValue::newFatal( 'some-error-for-tool-addition' );
		$toolWithErrorOnRemoval = $this->createMock( TrackingTool::class );
		$toolWithErrorOnRemoval->method( 'validateToolRemoval' )->willReturn( $toolRemovalError );

		$successfulRemovalStatus = StatusValue::newGood();
		$toolWithSuccessfulRemoval = $this->createMock( TrackingTool::class );
		$toolWithSuccessfulRemoval->method( 'validateToolRemoval' )->willReturn( $successfulRemovalStatus );

		yield 'Had a tool, removing it without replacement, error' => [
			$getRegistryMock( [ $tool1ID => $toolWithErrorOnRemoval ] ),
			$eventWithTool1,
			$eventWithoutTools,
			$toolRemovalError
		];
		yield 'Had a tool, removing it without replacement, success' => [
			$getRegistryMock( [ $tool1ID => $toolWithSuccessfulRemoval ] ),
			$eventWithTool1,
			$eventWithoutTools,
			$successfulRemovalStatus
		];

		yield 'Replacing a tool with another, cannot remove' => [
			$getRegistryMock( [ $tool1ID => $toolWithErrorOnRemoval, $tool2ID => $toolWithSuccessfulAddition ] ),
			$eventWithTool1,
			$eventWithTool2,
			$toolRemovalError
		];
		yield 'Replacing a tool with another, can remove but cannot add' => [
			$getRegistryMock( [ $tool1ID => $toolWithSuccessfulRemoval, $tool2ID => $toolWithErrorOnAddition ] ),
			$eventWithTool1,
			$eventWithTool2,
			$toolAdditionError
		];
		yield 'Replacing a tool with another, success' => [
			$getRegistryMock( [ $tool1ID => $toolWithSuccessfulRemoval, $tool2ID => $toolWithSuccessfulAddition ] ),
			$eventWithTool1,
			$eventWithTool2,
			$successfulAdditionStatus
		];

		$tool1ID = 1;
		$eventWithTool1AndDifferentToolEventID = $this->createMock( ExistingEventRegistration::class );
		$eventWithTool1AndDifferentToolEventID->method( 'getTrackingTools' )
			->willReturn( [ $this->getAssoc( $tool1ID, $eventWithTool1ToolEventID . '-foo' ) ] );

		$toolWithSuccessfulRemovalAndErrorOnAddition = clone $toolWithSuccessfulRemoval;
		$toolWithSuccessfulRemovalAndErrorOnAddition->method( 'validateToolAddition' )
			->willReturn( $toolAdditionError );

		$toolWithSuccessfulRemovalAndAddition = clone $toolWithSuccessfulRemoval;
		$toolWithSuccessfulRemovalAndAddition->method( 'validateToolAddition' )
			->willReturn( $successfulAdditionStatus );

		yield 'Same tool, changing event in the tool, cannot remove' => [
			$getRegistryMock( [ $tool1ID => $toolWithErrorOnRemoval ] ),
			$eventWithTool1,
			$eventWithTool1AndDifferentToolEventID,
			$toolRemovalError
		];
		yield 'Same tool, changing event in the tool, can remove but cannot add' => [
			$getRegistryMock( [ $tool1ID => $toolWithSuccessfulRemovalAndErrorOnAddition ] ),
			$eventWithTool1,
			$eventWithTool1AndDifferentToolEventID,
			$toolAdditionError
		];
		yield 'Same tool, changing event in the tool, success' => [
			$getRegistryMock( [ $tool1ID => $toolWithSuccessfulRemovalAndAddition ] ),
			$eventWithTool1,
			$eventWithTool1AndDifferentToolEventID,
			$successfulAdditionStatus
		];
		yield 'Same tool, same event in the tool' => [
			null,
			$eventWithTool1,
			$eventWithTool1,
			StatusValue::newGood()
		];
	}

	/**
	 * @covers ::validateEventDeletion
	 * @dataProvider provideValidateEventDeletion
	 */
	public function testValidateEventDeletion(
		?TrackingToolRegistry $registry,
		ExistingEventRegistration $event,
		StatusValue $expected
	) {
		$this->assertEquals(
			$expected,
			$this->getWatcher( $registry )->validateEventDeletion( $event )
		);
	}

	public function provideValidateEventDeletion(): Generator {
		$eventWithoutTools = $this->createMock( ExistingEventRegistration::class );
		$eventWithoutTools->method( 'getTrackingTools' )->willReturn( [] );
		yield 'No tool' => [ null, $eventWithoutTools, StatusValue::newGood() ];

		$toolID = 1;
		$eventWithTool = $this->createMock( ExistingEventRegistration::class );
		$eventWithTool->method( 'getTrackingTools' )->willReturn( [ $this->getAssoc( $toolID ) ] );

		$toolError = StatusValue::newFatal( 'some-error' );
		$toolWithError = $this->createMock( TrackingTool::class );
		$toolWithError->expects( $this->atLeastOnce() )
			->method( 'validateEventDeletion' )
			->willReturn( $toolError );
		$toolWithErrorRegistry = $this->createMock( TrackingToolRegistry::class );
		$toolWithErrorRegistry->method( 'newFromDBID' )->with( $toolID )->willReturn( $toolWithError );
		yield 'Tool attached, error' => [ $toolWithErrorRegistry, $eventWithTool, $toolError ];

		$toolSuccessStatus = StatusValue::newGood();
		$successfulTool = $this->createMock( TrackingTool::class );
		$successfulTool->expects( $this->atLeastOnce() )
			->method( 'validateEventDeletion' )
			->willReturn( $toolSuccessStatus );
		$successfulToolRegistry = $this->createMock( TrackingToolRegistry::class );
		$successfulToolRegistry->method( 'newFromDBID' )->with( $toolID )->willReturn( $successfulTool );
		yield 'Tool attached, successful' => [ $successfulToolRegistry, $eventWithTool, $toolSuccessStatus ];
	}

	/**
	 * @covers ::validateParticipantAdded
	 * @dataProvider provideValidateParticipantAdded
	 */
	public function testValidateParticipantAdded(
		?TrackingToolRegistry $registry,
		ExistingEventRegistration $event,
		StatusValue $expected
	) {
		$actual = $this->getWatcher( $registry )->validateParticipantAdded(
			$event,
			$this->createMock( CentralUser::class ),
			false
		);
		$this->assertEquals( $expected, $actual );
	}

	public function provideValidateParticipantAdded(): Generator {
		$eventWithoutTools = $this->createMock( ExistingEventRegistration::class );
		$eventWithoutTools->method( 'getTrackingTools' )->willReturn( [] );
		yield 'No tool' => [ null, $eventWithoutTools, StatusValue::newGood() ];

		$toolID = 1;
		$eventWithTool = $this->createMock( ExistingEventRegistration::class );
		$eventWithTool->method( 'getTrackingTools' )->willReturn( [ $this->getAssoc( $toolID ) ] );

		$toolError = StatusValue::newFatal( 'some-error' );
		$toolWithError = $this->createMock( TrackingTool::class );
		$toolWithError->expects( $this->atLeastOnce() )
			->method( 'validateParticipantAdded' )
			->willReturn( $toolError );
		$toolWithErrorRegistry = $this->createMock( TrackingToolRegistry::class );
		$toolWithErrorRegistry->method( 'newFromDBID' )->with( $toolID )->willReturn( $toolWithError );
		yield 'Tool attached, error' => [ $toolWithErrorRegistry, $eventWithTool, $toolError ];

		$toolSuccessStatus = StatusValue::newGood();
		$successfulTool = $this->createMock( TrackingTool::class );
		$successfulTool->expects( $this->atLeastOnce() )
			->method( 'validateParticipantAdded' )
			->willReturn( $toolSuccessStatus );
		$successfulToolRegistry = $this->createMock( TrackingToolRegistry::class );
		$successfulToolRegistry->method( 'newFromDBID' )->with( $toolID )->willReturn( $successfulTool );
		yield 'Tool attached, successful' => [ $successfulToolRegistry, $eventWithTool, $toolSuccessStatus ];
	}

	/**
	 * @covers ::validateParticipantsRemoved
	 * @dataProvider provideValidateParticipantsRemoved
	 */
	public function testValidateParticipantsRemoved(
		?TrackingToolRegistry $registry,
		ExistingEventRegistration $event,
		StatusValue $expected
	) {
		$this->assertEquals(
			$expected,
			$this->getWatcher( $registry )->validateParticipantsRemoved( $event, null, false )
		);
	}

	public function provideValidateParticipantsRemoved(): Generator {
		$eventWithoutTools = $this->createMock( ExistingEventRegistration::class );
		$eventWithoutTools->method( 'getTrackingTools' )->willReturn( [] );
		yield 'No tool' => [ null, $eventWithoutTools, StatusValue::newGood() ];

		$toolID = 1;
		$eventWithTool = $this->createMock( ExistingEventRegistration::class );
		$eventWithTool->method( 'getTrackingTools' )->willReturn( [ $this->getAssoc( $toolID ) ] );

		$toolError = StatusValue::newFatal( 'some-error' );
		$toolWithError = $this->createMock( TrackingTool::class );
		$toolWithError->expects( $this->atLeastOnce() )
			->method( 'validateParticipantsRemoved' )
			->willReturn( $toolError );
		$toolWithErrorRegistry = $this->createMock( TrackingToolRegistry::class );
		$toolWithErrorRegistry->method( 'newFromDBID' )->with( $toolID )->willReturn( $toolWithError );
		yield 'Tool attached, error' => [ $toolWithErrorRegistry, $eventWithTool, $toolError ];

		$toolSuccessStatus = StatusValue::newGood();
		$successfulTool = $this->createMock( TrackingTool::class );
		$successfulTool->expects( $this->atLeastOnce() )
			->method( 'validateParticipantsRemoved' )
			->willReturn( $toolSuccessStatus );
		$successfulToolRegistry = $this->createMock( TrackingToolRegistry::class );
		$successfulToolRegistry->method( 'newFromDBID' )->with( $toolID )->willReturn( $successfulTool );
		yield 'Tool attached, successful' => [ $successfulToolRegistry, $eventWithTool, $toolSuccessStatus ];
	}
}
