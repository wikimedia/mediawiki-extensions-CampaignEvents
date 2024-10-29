<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\TrackingTool;

use Generator;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\TrackingTool\Tool\TrackingTool;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolAssociation;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolEventWatcher;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolRegistry;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolUpdater;
use MediaWiki\Utils\MWTimestamp;
use MediaWikiIntegrationTestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use StatusValue;

/**
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolEventWatcher
 * @covers ::__construct
 */
class TrackingToolEventWatcherTest extends MediaWikiIntegrationTestCase {
	private const FAKE_TIME = '123456789';

	protected function setUp(): void {
		MWTimestamp::setFakeTime( self::FAKE_TIME );
	}

	private function getWatcher(
		?TrackingToolRegistry $registry = null,
		?LoggerInterface $logger = null,
		?TrackingToolUpdater $updater = null
	): TrackingToolEventWatcher {
		return new TrackingToolEventWatcher(
			$registry ?? $this->createMock( TrackingToolRegistry::class ),
			$updater ?? $this->createMock( TrackingToolUpdater::class ),
			$logger ?? new NullLogger()
		);
	}

	private function getAssoc( int $toolID, string $toolEventID = 'foobar' ): TrackingToolAssociation {
		return new TrackingToolAssociation( $toolID, $toolEventID, TrackingToolAssociation::SYNC_STATUS_UNKNOWN, null );
	}

	private function getLoggerSpy( bool $expectsError ): LoggerInterface {
		$logger = $this->createMock( LoggerInterface::class );
		$logger->expects( $expectsError ? $this->once() : $this->never() )
			->method( 'error' )
			->with( $this->stringContains( 'Tracking tool update failed for' ) );
		return $logger;
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
	 * @covers ::onEventCreated
	 * @dataProvider provideOnEventCreated
	 */
	public function testOnEventCreated(
		?TrackingToolRegistry $registry,
		LoggerInterface $logger,
		EventRegistration $event,
		StatusValue $expected
	) {
		$this->assertEquals(
			$expected,
			$this->getWatcher( $registry, $logger )->onEventCreated( 1, $event, [] )
		);
	}

	public function provideOnEventCreated(): Generator {
		$eventWithoutTools = $this->createMock( EventRegistration::class );
		$eventWithoutTools->method( 'getTrackingTools' )->willReturn( [] );
		yield 'No tool' => [ null, $this->getLoggerSpy( false ), $eventWithoutTools, StatusValue::newGood( [] ) ];

		$toolID = 1;
		$eventWithTool = $this->createMock( EventRegistration::class );
		$toolAssoc = $this->getAssoc( $toolID );
		$eventWithTool->method( 'getTrackingTools' )->willReturn( [ $toolAssoc ] );

		$toolErrorStatus = StatusValue::newFatal( 'some-error' );
		$toolWithError = $this->createMock( TrackingTool::class );
		$toolWithError->expects( $this->atLeastOnce() )
			->method( 'addToNewEvent' )
			->willReturn( $toolErrorStatus );
		$toolWithErrorRegistry = $this->createMock( TrackingToolRegistry::class );
		$toolWithErrorRegistry->method( 'newFromDBID' )->with( $toolID )->willReturn( $toolWithError );
		$expectedErrorStatus = StatusValue::newGood( [] )->merge( $toolErrorStatus );
		yield 'Add tool, error' => [
			$toolWithErrorRegistry,
			$this->getLoggerSpy( true ),
			$eventWithTool,
			$expectedErrorStatus
		];

		$successfulTool = $this->createMock( TrackingTool::class );
		$successfulTool->expects( $this->atLeastOnce() )
			->method( 'addToNewEvent' )
			->willReturn( StatusValue::newGood() );
		$successfulToolRegistry = $this->createMock( TrackingToolRegistry::class );
		$successfulToolRegistry->method( 'newFromDBID' )->with( $toolID )->willReturn( $successfulTool );
		$expectedAssoc = $toolAssoc->asUpdatedWith(
			TrackingToolAssociation::SYNC_STATUS_SYNCED,
			self::FAKE_TIME
		);
		yield 'Add tool, successful' => [
			$successfulToolRegistry,
			$this->getLoggerSpy( false ),
			$eventWithTool,
			StatusValue::newGood( [ $expectedAssoc ] )
		];
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
	 * @covers ::onEventUpdated
	 * @covers ::splitToolsForEventUpdate
	 * @dataProvider provideOnEventUpdated
	 */
	public function testOnEventUpdated(
		?TrackingToolRegistry $registry,
		LoggerInterface $logger,
		ExistingEventRegistration $oldEvent,
		EventRegistration $newEvent,
		StatusValue $expected
	) {
		$this->assertEquals(
			$expected,
			$this->getWatcher( $registry, $logger )->onEventUpdated( $oldEvent, $newEvent, [] )
		);
	}

	public function provideOnEventUpdated(): Generator {
		$eventWithoutTools = $this->createMock( ExistingEventRegistration::class );
		$eventWithoutTools->method( 'getTrackingTools' )->willReturn( [] );
		yield 'No tool before, no tool after' => [
			null,
			$this->getLoggerSpy( false ),
			$eventWithoutTools,
			$eventWithoutTools,
			StatusValue::newGood( [] )
		];

		$tool1ID = 1;
		$eventWithTool1ToolEventID = 'something';
		$tool1Assoc = $this->getAssoc( $tool1ID, $eventWithTool1ToolEventID );
		$eventWithTool1 = $this->createMock( ExistingEventRegistration::class );
		$eventWithTool1->method( 'getTrackingTools' )
			->willReturn( [ $tool1Assoc ] );
		$tool2ID = 2;
		$tool2Assoc = $this->getAssoc( $tool2ID );
		$eventWithTool2 = $this->createMock( ExistingEventRegistration::class );
		$eventWithTool2->method( 'getTrackingTools' )->willReturn( [ $tool2Assoc ] );

		/**
		 * @param array<int,TrackingTool> $toolMap Map of tool ID to TrackingTool object to return for that ID.
		 */
		$getRegistryMock = function ( array $toolMap ): TrackingToolRegistry {
			$ret = $this->createMock( TrackingToolRegistry::class );
			$ret->method( 'newFromDBID' )->willReturnCallback( fn ( int $toolID ) => $toolMap[$toolID] );
			return $ret;
		};

		$getSyncedAssoc = static function ( TrackingToolAssociation $oldAssoc ): TrackingToolAssociation {
			return $oldAssoc->asUpdatedWith(
				TrackingToolAssociation::SYNC_STATUS_SYNCED,
				self::FAKE_TIME,
			);
		};

		$toolAdditionError = StatusValue::newFatal( 'some-error-for-tool-addition' );
		$toolWithErrorOnAddition = $this->createMock( TrackingTool::class );
		$toolWithErrorOnAddition->method( 'addToExistingEvent' )->willReturn( $toolAdditionError );

		$successfulAdditionStatus = StatusValue::newGood();
		$toolWithSuccessfulAddition = $this->createMock( TrackingTool::class );
		$toolWithSuccessfulAddition->method( 'addToExistingEvent' )->willReturn( $successfulAdditionStatus );

		yield 'Had no tools, adding one, error' => [
			$getRegistryMock( [ $tool1ID => $toolWithErrorOnAddition ] ),
			$this->getLoggerSpy( true ),
			$eventWithoutTools,
			$eventWithTool1,
			StatusValue::newGood( [] )->merge( $toolAdditionError )
		];
		yield 'Had no tools, adding one, success' => [
			$getRegistryMock( [ $tool1ID => $toolWithSuccessfulAddition ] ),
			$this->getLoggerSpy( false ),
			$eventWithoutTools,
			$eventWithTool1,
			StatusValue::newGood( [ $getSyncedAssoc( $tool1Assoc ) ] )
		];

		$toolRemovalError = StatusValue::newFatal( 'some-error-for-tool-addition' );
		$toolWithErrorOnRemoval = $this->createMock( TrackingTool::class );
		$toolWithErrorOnRemoval->method( 'removeFromEvent' )->willReturn( $toolRemovalError );

		$successfulRemovalStatus = StatusValue::newGood();
		$toolWithSuccessfulRemoval = $this->createMock( TrackingTool::class );
		$toolWithSuccessfulRemoval->method( 'removeFromEvent' )->willReturn( $successfulRemovalStatus );

		yield 'Had a tool, removing it without replacement, error' => [
			$getRegistryMock( [ $tool1ID => $toolWithErrorOnRemoval ] ),
			$this->getLoggerSpy( true ),
			$eventWithTool1,
			$eventWithoutTools,
			StatusValue::newGood( [ $tool1Assoc ] )->merge( $toolRemovalError )
		];
		yield 'Had a tool, removing it without replacement, success' => [
			$getRegistryMock( [ $tool1ID => $toolWithSuccessfulRemoval ] ),
			$this->getLoggerSpy( false ),
			$eventWithTool1,
			$eventWithoutTools,
			StatusValue::newGood( [] )
		];

		yield 'Replacing a tool with another, cannot remove, cannot add' => [
			$getRegistryMock( [ $tool1ID => $toolWithErrorOnRemoval, $tool2ID => $toolWithSuccessfulAddition ] ),
			$this->getLoggerSpy( true ),
			$eventWithTool1,
			$eventWithTool2,
			StatusValue::newGood( [ $tool1Assoc ] )->merge( $toolRemovalError )
		];
		yield 'Replacing a tool with another, cannot remove, can add' => [
			$getRegistryMock( [ $tool1ID => $toolWithErrorOnRemoval, $tool2ID => $toolWithSuccessfulAddition ] ),
			$this->getLoggerSpy( true ),
			$eventWithTool1,
			$eventWithTool2,
			// NOTE: This will also include `$getSyncedAssoc( $tool2Assoc )` once we support multiple tracking tools.
			StatusValue::newGood( [ $tool1Assoc ] )->merge( $toolRemovalError )
		];
		yield 'Replacing a tool with another, can remove but cannot add' => [
			$getRegistryMock( [ $tool1ID => $toolWithSuccessfulRemoval, $tool2ID => $toolWithErrorOnAddition ] ),
			$this->getLoggerSpy( true ),
			$eventWithTool1,
			$eventWithTool2,
			StatusValue::newGood( [] )->merge( $toolAdditionError )
		];
		yield 'Replacing a tool with another, success' => [
			$getRegistryMock( [ $tool1ID => $toolWithSuccessfulRemoval, $tool2ID => $toolWithSuccessfulAddition ] ),
			$this->getLoggerSpy( false ),
			$eventWithTool1,
			$eventWithTool2,
			StatusValue::newGood( [ $getSyncedAssoc( $tool2Assoc ) ] )
		];

		$tool1ID = 1;
		$tool1DifferentAssoc = $this->getAssoc( $tool1ID, $eventWithTool1ToolEventID . '-foo' );
		$eventWithTool1AndDifferentToolEventID = $this->createMock( ExistingEventRegistration::class );
		$eventWithTool1AndDifferentToolEventID->method( 'getTrackingTools' )
			->willReturn( [ $tool1DifferentAssoc ] );

		$toolWithSuccessfulRemovalAndErrorOnAddition = clone $toolWithSuccessfulRemoval;
		$toolWithSuccessfulRemovalAndErrorOnAddition->method( 'addToExistingEvent' )
			->willReturn( $toolAdditionError );

		$toolWithSuccessfulRemovalAndAddition = clone $toolWithSuccessfulRemoval;
		$toolWithSuccessfulRemovalAndAddition->method( 'addToExistingEvent' )
			->willReturn( $successfulAdditionStatus );

		yield 'Same tool, changing event in the tool, cannot remove, cannot add' => [
			$getRegistryMock( [ $tool1ID => $toolWithErrorOnRemoval ] ),
			$this->getLoggerSpy( true ),
			$eventWithTool1,
			$eventWithTool1AndDifferentToolEventID,
			StatusValue::newGood( [ $tool1Assoc ] )->merge( $toolRemovalError )
		];
		yield 'Same tool, changing event in the tool, cannot remove, can add' => [
			$getRegistryMock( [ $tool1ID => $toolWithErrorOnRemoval ] ),
			$this->getLoggerSpy( true ),
			$eventWithTool1,
			$eventWithTool1AndDifferentToolEventID,
			// NOTE: This will also include `$getSyncedAssoc( $tool1DifferentAssoc )` once we support
			// multiple tracking tools.
			StatusValue::newGood( [ $tool1Assoc ] )->merge( $toolRemovalError )
		];
		yield 'Same tool, changing event in the tool, can remove but cannot add' => [
			$getRegistryMock( [ $tool1ID => $toolWithSuccessfulRemovalAndErrorOnAddition ] ),
			$this->getLoggerSpy( true ),
			$eventWithTool1,
			$eventWithTool1AndDifferentToolEventID,
			StatusValue::newGood( [] )->merge( $toolAdditionError )
		];
		yield 'Same tool, changing event in the tool, success' => [
			$getRegistryMock( [ $tool1ID => $toolWithSuccessfulRemovalAndAddition ] ),
			$this->getLoggerSpy( false ),
			$eventWithTool1,
			$eventWithTool1AndDifferentToolEventID,
			StatusValue::newGood( [ $getSyncedAssoc( $tool1DifferentAssoc ) ] )
		];
		yield 'Same tool, same event in the tool (no change)' => [
			null,
			$this->getLoggerSpy( false ),
			$eventWithTool1,
			$eventWithTool1,
			StatusValue::newGood( [ $tool1Assoc ] )
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
	 * @covers ::onEventDeleted
	 * @dataProvider provideOnEventDeleted
	 */
	public function testOnEventDeleted(
		?TrackingToolRegistry $registry,
		LoggerInterface $logger,
		TrackingToolUpdater $updater,
		ExistingEventRegistration $event
	) {
		$this->getWatcher( $registry, $logger, $updater )->onEventDeleted( $event );
		// The test uses soft assertions
		$this->addToAssertionCount( 1 );
	}

	public function provideOnEventDeleted(): Generator {
		$eventWithoutTools = $this->createMock( ExistingEventRegistration::class );
		$eventWithoutTools->method( 'getTrackingTools' )->willReturn( [] );
		yield 'No tool' => [
			null,
			$this->getLoggerSpy( false ),
			$this->createNoOpMock( TrackingToolUpdater::class ),
			$eventWithoutTools
		];

		$toolID = 1;
		$eventWithTool = $this->createMock( ExistingEventRegistration::class );
		$eventWithTool->method( 'getTrackingTools' )->willReturn( [ $this->getAssoc( $toolID ) ] );

		$toolError = StatusValue::newFatal( 'some-error' );
		$toolWithError = $this->createMock( TrackingTool::class );
		$toolWithError->expects( $this->atLeastOnce() )
			->method( 'onEventDeleted' )
			->willReturn( $toolError );
		$toolWithErrorRegistry = $this->createMock( TrackingToolRegistry::class );
		$toolWithErrorRegistry->method( 'newFromDBID' )->with( $toolID )->willReturn( $toolWithError );
		yield 'Tool attached, error' => [
			$toolWithErrorRegistry,
			$this->getLoggerSpy( true ),
			$this->createNoOpMock( TrackingToolUpdater::class ),
			$eventWithTool
		];

		$toolSuccessStatus = StatusValue::newGood();
		$successfulTool = $this->createMock( TrackingTool::class );
		$successfulTool->expects( $this->atLeastOnce() )
			->method( 'onEventDeleted' )
			->willReturn( $toolSuccessStatus );
		$successfulToolRegistry = $this->createMock( TrackingToolRegistry::class );
		$successfulToolRegistry->method( 'newFromDBID' )->with( $toolID )->willReturn( $successfulTool );
		$successUpdater = $this->createMock( TrackingToolUpdater::class );
		$successUpdater->expects( $this->once() )
			->method( 'updateToolSyncStatus' )
			->with( $eventWithTool->getID(), $toolID, $this->anything(), TrackingToolAssociation::SYNC_STATUS_UNKNOWN );
		yield 'Tool attached, successful' => [
			$successfulToolRegistry,
			$this->getLoggerSpy( false ),
			$successUpdater,
			$eventWithTool
		];
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
	 * @covers ::onParticipantAdded
	 * @dataProvider provideOnParticipantAdded
	 */
	public function testOnParticipantAdded(
		?TrackingToolRegistry $registry,
		LoggerInterface $logger,
		?TrackingToolUpdater $updater,
		ExistingEventRegistration $event
	) {
		$this->getWatcher( $registry, $logger, $updater )->onParticipantAdded(
			$event,
			$this->createMock( CentralUser::class ),
			false
		);
		// The test uses soft assertions
		$this->addToAssertionCount( 1 );
	}

	public function provideOnParticipantAdded(): Generator {
		$eventWithoutTools = $this->createMock( ExistingEventRegistration::class );
		$eventWithoutTools->method( 'getTrackingTools' )->willReturn( [] );
		yield 'No tool' => [
			null,
			$this->getLoggerSpy( false ),
			$this->createNoOpMock( TrackingToolUpdater::class ),
			$eventWithoutTools
		];

		$toolID = 1;
		$eventWithTool = $this->createMock( ExistingEventRegistration::class );
		$eventWithTool->method( 'getTrackingTools' )->willReturn( [ $this->getAssoc( $toolID ) ] );

		$toolError = StatusValue::newFatal( 'some-error' );
		$toolWithError = $this->createMock( TrackingTool::class );
		$toolWithError->expects( $this->atLeastOnce() )
			->method( 'addParticipant' )
			->willReturn( $toolError );
		$toolWithErrorRegistry = $this->createMock( TrackingToolRegistry::class );
		$toolWithErrorRegistry->method( 'newFromDBID' )->with( $toolID )->willReturn( $toolWithError );
		$errorUpdater = $this->createMock( TrackingToolUpdater::class );
		$errorUpdater->expects( $this->once() )
			->method( 'updateToolSyncStatus' )
			->with( $eventWithTool->getID(), $toolID, $this->anything(), TrackingToolAssociation::SYNC_STATUS_FAILED );
		yield 'Tool attached, error' => [
			$toolWithErrorRegistry,
			$this->getLoggerSpy( true ),
			$errorUpdater,
			$eventWithTool
		];

		$toolSuccessStatus = StatusValue::newGood();
		$successfulTool = $this->createMock( TrackingTool::class );
		$successfulTool->expects( $this->atLeastOnce() )
			->method( 'addParticipant' )
			->willReturn( $toolSuccessStatus );
		$successfulToolRegistry = $this->createMock( TrackingToolRegistry::class );
		$successfulToolRegistry->method( 'newFromDBID' )->with( $toolID )->willReturn( $successfulTool );
		$successUpdater = $this->createMock( TrackingToolUpdater::class );
		$successUpdater->expects( $this->once() )
			->method( 'updateToolSyncStatus' )
			->with( $eventWithTool->getID(), $toolID, $this->anything(), TrackingToolAssociation::SYNC_STATUS_SYNCED );
		yield 'Tool attached, successful' => [
			$successfulToolRegistry,
			$this->getLoggerSpy( false ),
			$successUpdater,
			$eventWithTool
		];
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

	/**
	 * @covers ::onParticipantsRemoved
	 * @dataProvider provideOnParticipantsRemoved
	 */
	public function testOnParticipantsRemoved(
		?TrackingToolRegistry $registry,
		LoggerInterface $logger,
		?TrackingToolUpdater $updater,
		ExistingEventRegistration $event
	) {
		$this->getWatcher( $registry, $logger, $updater )->onParticipantsRemoved( $event, null, false );
		// The test uses soft assertions
		$this->addToAssertionCount( 1 );
	}

	public function provideOnParticipantsRemoved(): Generator {
		$eventWithoutTools = $this->createMock( ExistingEventRegistration::class );
		$eventWithoutTools->method( 'getTrackingTools' )->willReturn( [] );
		yield 'No tool' => [
			null,
			$this->getLoggerSpy( false ),
			$this->createNoOpMock( TrackingToolUpdater::class ),
			$eventWithoutTools
		];

		$toolID = 1;
		$eventWithTool = $this->createMock( ExistingEventRegistration::class );
		$eventWithTool->method( 'getTrackingTools' )->willReturn( [ $this->getAssoc( $toolID ) ] );

		$toolError = StatusValue::newFatal( 'some-error' );
		$toolWithError = $this->createMock( TrackingTool::class );
		$toolWithError->expects( $this->atLeastOnce() )
			->method( 'removeParticipants' )
			->willReturn( $toolError );
		$toolWithErrorRegistry = $this->createMock( TrackingToolRegistry::class );
		$toolWithErrorRegistry->method( 'newFromDBID' )->with( $toolID )->willReturn( $toolWithError );
		$errorUpdater = $this->createMock( TrackingToolUpdater::class );
		$errorUpdater->expects( $this->once() )
			->method( 'updateToolSyncStatus' )
			->with( $eventWithTool->getID(), $toolID, $this->anything(), TrackingToolAssociation::SYNC_STATUS_FAILED );
		yield 'Tool attached, error' => [
			$toolWithErrorRegistry,
			$this->getLoggerSpy( true ),
			$errorUpdater,
			$eventWithTool
		];

		$toolSuccessStatus = StatusValue::newGood();
		$successfulTool = $this->createMock( TrackingTool::class );
		$successfulTool->expects( $this->atLeastOnce() )
			->method( 'removeParticipants' )
			->willReturn( $toolSuccessStatus );
		$successfulToolRegistry = $this->createMock( TrackingToolRegistry::class );
		$successfulToolRegistry->method( 'newFromDBID' )->with( $toolID )->willReturn( $successfulTool );
		$successUpdater = $this->createMock( TrackingToolUpdater::class );
		$successUpdater->expects( $this->once() )
			->method( 'updateToolSyncStatus' )
			->with( $eventWithTool->getID(), $toolID, $this->anything(), TrackingToolAssociation::SYNC_STATUS_SYNCED );
		yield 'Tool attached, successful' => [
			$successfulToolRegistry,
			$this->getLoggerSpy( false ),
			$successUpdater,
			$eventWithTool
		];
	}
}
