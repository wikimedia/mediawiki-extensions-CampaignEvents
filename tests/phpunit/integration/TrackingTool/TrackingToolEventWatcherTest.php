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

	private static function getAssoc( int $toolID, string $toolEventID = 'foobar' ): TrackingToolAssociation {
		return new TrackingToolAssociation( $toolID, $toolEventID, TrackingToolAssociation::SYNC_STATUS_UNKNOWN, null );
	}

	private function getLoggerSpy( bool $expectsError ): LoggerInterface {
		$logger = $this->createMock( LoggerInterface::class );
		$logger->expects( $expectsError ? $this->once() : $this->never() )
			->method( 'error' )
			->with( $this->stringContains( 'Tracking tool update failed for' ) );
		return $logger;
	}

	/** @covers ::validateEventCreation */
	public function testValidateEventCreation__noTool() {
		$eventWithoutTools = $this->createMock( EventRegistration::class );
		$eventWithoutTools->method( 'getTrackingTools' )->willReturn( [] );
		$status = $this->getWatcher()->validateEventCreation( $eventWithoutTools, [] );
		$this->assertStatusGood( $status );
	}

	/**
	 * @covers ::validateEventCreation
	 * @dataProvider provideValidateEventCreation
	 */
	public function testValidateEventCreation( bool $shouldFail ) {
		$toolID = 1;
		$event = $this->createMock( EventRegistration::class );
		$event->method( 'getTrackingTools' )->willReturn( [ self::getAssoc( $toolID ) ] );

		$tool = $this->createMock( TrackingTool::class );
		$registry = $this->createMock( TrackingToolRegistry::class );
		$registry->method( 'newFromDBID' )->with( $toolID )->willReturn( $tool );
		if ( $shouldFail ) {
			$toolError = 'some-error';
			$tool->expects( $this->atLeastOnce() )
				->method( 'validateToolAddition' )
				->willReturn( StatusValue::newFatal( $toolError ) );
			$status = $this->getWatcher( $registry )->validateEventCreation( $event, [] );
			$this->assertStatusError( $toolError, $status );
		} else {
			$tool->expects( $this->atLeastOnce() )
				->method( 'validateToolAddition' )
				->willReturn( StatusValue::newGood() );
			$status = $this->getWatcher( $registry )->validateEventCreation( $event, [] );
			$this->assertStatusGood( $status );
		}
	}

	public static function provideValidateEventCreation(): Generator {
		yield 'Add tool, error' => [ true ];
		yield 'Add tool, successful' => [ false ];
	}

	/** @covers ::onEventCreated */
	public function testOnEventCreated__noTool() {
		$eventWithoutTools = $this->createMock( EventRegistration::class );
		$eventWithoutTools->method( 'getTrackingTools' )->willReturn( [] );
		$status = $this->getWatcher( null, $this->getLoggerSpy( false ) )
			->onEventCreated( 1, $eventWithoutTools, [] );
		$this->assertStatusGood( $status );
		$this->assertStatusValue( [], $status );
	}

	/**
	 * @covers ::onEventCreated
	 * @dataProvider provideOnEventCreated
	 */
	public function testOnEventCreated( bool $shouldFail ) {
		$toolID = 1;
		$event = $this->createMock( EventRegistration::class );
		$toolAssoc = self::getAssoc( $toolID );
		$event->method( 'getTrackingTools' )->willReturn( [ $toolAssoc ] );

		$logger = $this->getLoggerSpy( $shouldFail );
		$tool = $this->createMock( TrackingTool::class );
		$registry = $this->createMock( TrackingToolRegistry::class );
		$registry->method( 'newFromDBID' )->with( $toolID )->willReturn( $tool );
		if ( $shouldFail ) {
			$toolError = 'some-error';
			$tool->expects( $this->atLeastOnce() )
				->method( 'addToNewEvent' )
				->willReturn( StatusValue::newFatal( $toolError ) );
			$status = $this->getWatcher( $registry, $logger )->onEventCreated( 1, $event, [] );
			$this->assertStatusValue( [], $status );
			$this->assertStatusError( $toolError, $status );
		} else {
			$tool->expects( $this->atLeastOnce() )
				->method( 'addToNewEvent' )
				->willReturn( StatusValue::newGood() );
			$expectedAssoc = $toolAssoc->asUpdatedWith(
				TrackingToolAssociation::SYNC_STATUS_SYNCED,
				self::FAKE_TIME
			);
			$status = $this->getWatcher( $registry, $logger )->onEventCreated( 1, $event, [] );
			$this->assertStatusGood( $status );
			$this->assertStatusValue( [ $expectedAssoc ], $status );
		}
	}

	public static function provideOnEventCreated(): Generator {
		yield 'Add tool, error' => [ true ];
		yield 'Add tool, successful' => [ false ];
	}

	/**
	 * @param array<int,array<string,?string>> $toolErrorMap Map of tool ID to an array with optional keys "add" and
	 * "remove", whose values can be null for success, or the error to be returned when validating addition/removal.
	 * @covers ::validateEventUpdate
	 * @covers ::splitToolsForEventUpdate
	 * @dataProvider provideValidateEventUpdate
	 */
	public function testValidateEventUpdate(
		array $toolErrorMap,
		array $oldEventAssociations,
		array $newEventAssociations,
		?string $expectedError
	) {
		$registry = $this->createMock( TrackingToolRegistry::class );
		$registry->method( 'newFromDBID' )->willReturnCallback( function ( int $toolID ) use ( $toolErrorMap ) {
			$tool = $this->createMock( TrackingTool::class );

			$wantedAddError = $toolErrorMap[$toolID]['add'] ?? null;
			if ( $wantedAddError !== null ) {
				$tool->method( 'validateToolAddition' )->willReturn( StatusValue::newFatal( $wantedAddError ) );
			} else {
				$tool->method( 'validateToolAddition' )->willReturn( StatusValue::newGood() );
			}

			$wantedRemoveError = $toolErrorMap[$toolID]['remove'] ?? null;
			if ( $wantedRemoveError !== null ) {
				$tool->method( 'validateToolRemoval' )->willReturn( StatusValue::newFatal( $wantedRemoveError ) );
			} else {
				$tool->method( 'validateToolRemoval' )->willReturn( StatusValue::newGood() );
			}
			return $tool;
		} );

		$oldEvent = $this->createMock( ExistingEventRegistration::class );
		$oldEvent->method( 'getTrackingTools' )->willReturn( $oldEventAssociations );
		$newEvent = $this->createMock( ExistingEventRegistration::class );
		$newEvent->method( 'getTrackingTools' )->willReturn( $newEventAssociations );

		$status = $this->getWatcher( $registry )->validateEventUpdate( $oldEvent, $newEvent, [] );
		if ( $expectedError === null ) {
			$this->assertStatusGood( $status );
		} else {
			$this->assertStatusError( $expectedError, $status );
		}
	}

	public static function provideValidateEventUpdate(): Generator {
		yield 'No tool before, no tool after' => [
			[],
			[],
			[],
			null
		];

		$tool1ID = 1;
		$eventWithTool1ToolEventID = 'something';
		$eventWithTool1 = [ self::getAssoc( $tool1ID, $eventWithTool1ToolEventID ) ];

		$tool2ID = 2;
		$eventWithTool2 = [ self::getAssoc( $tool2ID ) ];

		$toolAdditionError = 'some-error-for-tool-addition';
		$toolWithErrorOnAddition = [ 'add' => $toolAdditionError ];
		$toolWithSuccessfulAddition = [ 'add' => null ];

		yield 'Had no tools, adding one, error' => [
			[ $tool1ID => $toolWithErrorOnAddition ],
			[],
			$eventWithTool1,
			$toolAdditionError
		];
		yield 'Had no tools, adding one, success' => [
			[ $tool1ID => $toolWithSuccessfulAddition ],
			[],
			$eventWithTool1,
			null
		];

		$toolRemovalError = 'some-error-for-tool-removal';
		$toolWithErrorOnRemoval = [ 'remove' => $toolRemovalError ];
		$toolWithSuccessfulRemoval = [ 'remove' => null ];

		yield 'Had a tool, removing it without replacement, error' => [
			[ $tool1ID => $toolWithErrorOnRemoval ],
			$eventWithTool1,
			[],
			$toolRemovalError
		];
		yield 'Had a tool, removing it without replacement, success' => [
			[ $tool1ID => $toolWithSuccessfulRemoval ],
			$eventWithTool1,
			[],
			null
		];

		yield 'Replacing a tool with another, cannot remove' => [
			[ $tool1ID => $toolWithErrorOnRemoval, $tool2ID => $toolWithSuccessfulAddition ],
			$eventWithTool1,
			$eventWithTool2,
			$toolRemovalError
		];
		yield 'Replacing a tool with another, can remove but cannot add' => [
			[ $tool1ID => $toolWithSuccessfulRemoval, $tool2ID => $toolWithErrorOnAddition ],
			$eventWithTool1,
			$eventWithTool2,
			$toolAdditionError
		];
		yield 'Replacing a tool with another, success' => [
			[ $tool1ID => $toolWithSuccessfulRemoval, $tool2ID => $toolWithSuccessfulAddition ],
			$eventWithTool1,
			$eventWithTool2,
			null
		];

		$eventWithTool1AndDifferentToolEventID = [ self::getAssoc( $tool1ID, $eventWithTool1ToolEventID . '-foo' ) ];
		$toolWithSuccessfulRemovalAndErrorOnAddition = $toolWithSuccessfulRemoval + [ 'add' => $toolAdditionError ];
		$toolWithSuccessfulRemovalAndAddition = $toolWithSuccessfulRemoval + [ 'add' => null ];

		yield 'Same tool, changing event in the tool, cannot remove' => [
			[ $tool1ID => $toolWithErrorOnRemoval ],
			$eventWithTool1,
			$eventWithTool1AndDifferentToolEventID,
			$toolRemovalError
		];
		yield 'Same tool, changing event in the tool, can remove but cannot add' => [
			[ $tool1ID => $toolWithSuccessfulRemovalAndErrorOnAddition ],
			$eventWithTool1,
			$eventWithTool1AndDifferentToolEventID,
			$toolAdditionError
		];
		yield 'Same tool, changing event in the tool, success' => [
			[ $tool1ID => $toolWithSuccessfulRemovalAndAddition ],
			$eventWithTool1,
			$eventWithTool1AndDifferentToolEventID,
			null
		];
		yield 'Same tool, same event in the tool' => [
			[],
			$eventWithTool1,
			$eventWithTool1,
			null
		];
	}

	/**
	 * @param array<int,array<string,?string>> $toolErrorMap Map of tool ID to an array with optional keys "add" and
	 * "remove", whose values can be null for success, or the error to be returned when adding/removing the tool.
	 * @covers ::onEventUpdated
	 * @covers ::splitToolsForEventUpdate
	 * @dataProvider provideOnEventUpdated
	 */
	public function testOnEventUpdated(
		array $toolErrorMap,
		bool $expectsError,
		array $oldEventAssociations,
		array $newEventAssociations,
		?string $expectedError,
		?array $expectedStatusValue
	) {
		$registry = $this->createMock( TrackingToolRegistry::class );
		$registry->method( 'newFromDBID' )->willReturnCallback( function ( int $toolID ) use ( $toolErrorMap ) {
			$tool = $this->createMock( TrackingTool::class );

			$wantedAddError = $toolErrorMap[$toolID]['add'] ?? null;
			if ( $wantedAddError !== null ) {
				$tool->method( 'addToExistingEvent' )->willReturn( StatusValue::newFatal( $wantedAddError ) );
			} else {
				$tool->method( 'addToExistingEvent' )->willReturn( StatusValue::newGood() );
			}

			$wantedRemoveError = $toolErrorMap[$toolID]['remove'] ?? null;
			if ( $wantedRemoveError !== null ) {
				$tool->method( 'removeFromEvent' )->willReturn( StatusValue::newFatal( $wantedRemoveError ) );
			} else {
				$tool->method( 'removeFromEvent' )->willReturn( StatusValue::newGood() );
			}
			return $tool;
		} );

		$oldEvent = $this->createMock( ExistingEventRegistration::class );
		$oldEvent->method( 'getTrackingTools' )->willReturn( $oldEventAssociations );
		$newEvent = $this->createMock( ExistingEventRegistration::class );
		$newEvent->method( 'getTrackingTools' )->willReturn( $newEventAssociations );

		$logger = $this->getLoggerSpy( $expectsError );

		$status = $this->getWatcher( $registry, $logger )->onEventUpdated( $oldEvent, $newEvent, [] );
		if ( $expectedError === null ) {
			$this->assertStatusGood( $status );
		} else {
			$this->assertStatusError( $expectedError, $status );
		}
		$this->assertStatusValue( $expectedStatusValue, $status );
	}

	public static function provideOnEventUpdated(): Generator {
		yield 'No tool before, no tool after' => [
			[],
			false,
			[],
			[],
			null,
			[]
		];

		$tool1ID = 1;
		$eventWithTool1ToolEventID = 'something';
		$tool1Assoc = self::getAssoc( $tool1ID, $eventWithTool1ToolEventID );
		$eventWithTool1 = [ $tool1Assoc ];

		$tool2ID = 2;
		$tool2Assoc = self::getAssoc( $tool2ID );
		$eventWithTool2 = [ $tool2Assoc ];

		$getSyncedAssoc = static function ( TrackingToolAssociation $oldAssoc ): TrackingToolAssociation {
			return $oldAssoc->asUpdatedWith(
				TrackingToolAssociation::SYNC_STATUS_SYNCED,
				self::FAKE_TIME,
			);
		};

		$toolAdditionError = 'some-error-for-tool-addition';
		$toolWithErrorOnAddition = [ 'add' => $toolAdditionError ];
		$toolWithSuccessfulAddition = [ 'add' => null ];

		yield 'Had no tools, adding one, error' => [
			[ $tool1ID => $toolWithErrorOnAddition ],
			true,
			[],
			$eventWithTool1,
			$toolAdditionError,
			[]
		];
		yield 'Had no tools, adding one, success' => [
			[ $tool1ID => $toolWithSuccessfulAddition ],
			false,
			[],
			$eventWithTool1,
			null,
			[ $getSyncedAssoc( $tool1Assoc ) ]
		];

		$toolRemovalError = 'some-error-for-tool-removal';
		$toolWithErrorOnRemoval = [ 'remove' => $toolRemovalError ];
		$toolWithSuccessfulRemoval = [ 'remove' => null ];

		yield 'Had a tool, removing it without replacement, error' => [
			[ $tool1ID => $toolWithErrorOnRemoval ],
			true,
			$eventWithTool1,
			[],
			$toolRemovalError,
			$eventWithTool1
		];
		yield 'Had a tool, removing it without replacement, success' => [
			[ $tool1ID => $toolWithSuccessfulRemoval ],
			false,
			$eventWithTool1,
			[],
			null,
			[]
		];

		yield 'Replacing a tool with another, cannot remove, cannot add' => [
			[ $tool1ID => $toolWithErrorOnRemoval, $tool2ID => $toolWithSuccessfulAddition ],
			true,
			$eventWithTool1,
			$eventWithTool2,
			$toolRemovalError,
			$eventWithTool1
		];
		yield 'Replacing a tool with another, cannot remove, can add' => [
			[ $tool1ID => $toolWithErrorOnRemoval, $tool2ID => $toolWithSuccessfulAddition ],
			true,
			$eventWithTool1,
			$eventWithTool2,
			$toolRemovalError,
			// NOTE: This will also include `$getSyncedAssoc( $tool2Assoc )` once we support multiple tracking tools.
			$eventWithTool1
		];
		yield 'Replacing a tool with another, can remove but cannot add' => [
			[ $tool1ID => $toolWithSuccessfulRemoval, $tool2ID => $toolWithErrorOnAddition ],
			true,
			$eventWithTool1,
			$eventWithTool2,
			$toolAdditionError,
			[]
		];
		yield 'Replacing a tool with another, success' => [
			[ $tool1ID => $toolWithSuccessfulRemoval, $tool2ID => $toolWithSuccessfulAddition ],
			false,
			$eventWithTool1,
			$eventWithTool2,
			null,
			[ $getSyncedAssoc( $tool2Assoc ) ]
		];

		$tool1DifferentAssoc = self::getAssoc( $tool1ID, $eventWithTool1ToolEventID . '-foo' );
		$eventWithTool1AndDifferentToolEventID = [ $tool1DifferentAssoc ];

		$toolWithSuccessfulRemovalAndErrorOnAddition = $toolWithSuccessfulRemoval + [ 'add' => $toolAdditionError ];
		$toolWithSuccessfulRemovalAndAddition = $toolWithSuccessfulRemoval + [ 'add' => null ];

		yield 'Same tool, changing event in the tool, cannot remove, cannot add' => [
			[ $tool1ID => $toolWithErrorOnRemoval ],
			true,
			$eventWithTool1,
			$eventWithTool1AndDifferentToolEventID,
			$toolRemovalError,
			$eventWithTool1
		];
		yield 'Same tool, changing event in the tool, cannot remove, can add' => [
			[ $tool1ID => $toolWithErrorOnRemoval ],
			true,
			$eventWithTool1,
			$eventWithTool1AndDifferentToolEventID,
			$toolRemovalError,
			// NOTE: This will also include `$getSyncedAssoc( $tool1DifferentAssoc )` once we support
			// multiple tracking tools.
			$eventWithTool1
		];
		yield 'Same tool, changing event in the tool, can remove but cannot add' => [
			[ $tool1ID => $toolWithSuccessfulRemovalAndErrorOnAddition ],
			true,
			$eventWithTool1,
			$eventWithTool1AndDifferentToolEventID,
			$toolAdditionError,
			[]
		];
		yield 'Same tool, changing event in the tool, success' => [
			[ $tool1ID => $toolWithSuccessfulRemovalAndAddition ],
			false,
			$eventWithTool1,
			$eventWithTool1AndDifferentToolEventID,
			null,
			[ $getSyncedAssoc( $tool1DifferentAssoc ) ]
		];
		yield 'Same tool, same event in the tool (no change)' => [
			[],
			false,
			$eventWithTool1,
			$eventWithTool1,
			null,
			$eventWithTool1
		];
	}

	/** @covers ::validateEventDeletion */
	public function testValidateEventDeletion__noTool() {
		$eventWithoutTools = $this->createMock( ExistingEventRegistration::class );
		$eventWithoutTools->method( 'getTrackingTools' )->willReturn( [] );
		$status = $this->getWatcher()->validateEventDeletion( $eventWithoutTools );
		$this->assertStatusGood( $status );
	}

	/**
	 * @covers ::validateEventDeletion
	 * @dataProvider provideValidateEventDeletion
	 */
	public function testValidateEventDeletion( bool $shouldFail ) {
		$toolID = 1;
		$event = $this->createMock( ExistingEventRegistration::class );
		$event->method( 'getTrackingTools' )->willReturn( [ self::getAssoc( $toolID ) ] );

		$tool = $this->createMock( TrackingTool::class );
		$registry = $this->createMock( TrackingToolRegistry::class );
		$registry->method( 'newFromDBID' )->with( $toolID )->willReturn( $tool );
		if ( $shouldFail ) {
			$toolError = 'some-error';
			$tool->expects( $this->atLeastOnce() )
				->method( 'validateEventDeletion' )
				->willReturn( StatusValue::newFatal( $toolError ) );
			$status = $this->getWatcher( $registry )->validateEventDeletion( $event );
			$this->assertStatusError( $toolError, $status );
		} else {
			$tool->expects( $this->atLeastOnce() )
				->method( 'validateEventDeletion' )
				->willReturn( StatusValue::newGood() );
			$status = $this->getWatcher( $registry )->validateEventDeletion( $event );
			$this->assertStatusGood( $status );
		}
	}

	public static function provideValidateEventDeletion(): Generator {
		yield 'Tool attached, error' => [ true ];
		yield 'Tool attached, successful' => [ false ];
	}

	/** @covers ::onEventDeleted */
	public function testOnEventDeleted__noTool() {
		$eventWithoutTools = $this->createMock( ExistingEventRegistration::class );
		$eventWithoutTools->method( 'getTrackingTools' )->willReturn( [] );
		$updater = $this->createNoOpMock( TrackingToolUpdater::class );
		$this->getWatcher( null, $this->getLoggerSpy( false ), $updater )->onEventDeleted( $eventWithoutTools );
		// The test uses soft assertions
		$this->addToAssertionCount( 1 );
	}

	/**
	 * @covers ::onEventDeleted
	 * @dataProvider provideOnEventDeleted
	 */
	public function testOnEventDeleted( bool $shouldFail ) {
		$toolID = 1;
		$event = $this->createMock( ExistingEventRegistration::class );
		$event->method( 'getTrackingTools' )->willReturn( [ self::getAssoc( $toolID ) ] );

		$tool = $this->createMock( TrackingTool::class );
		$registry = $this->createMock( TrackingToolRegistry::class );
		$registry->method( 'newFromDBID' )->with( $toolID )->willReturn( $tool );
		if ( $shouldFail ) {
			$tool->expects( $this->atLeastOnce() )
				->method( 'onEventDeleted' )
				->willReturn( StatusValue::newFatal( 'some-error' ) );
			$updater = $this->createNoOpMock( TrackingToolUpdater::class );
		} else {
			$tool->expects( $this->atLeastOnce() )
				->method( 'onEventDeleted' )
				->willReturn( StatusValue::newGood() );
			$updater = $this->createMock( TrackingToolUpdater::class );
			$updater->expects( $this->once() )
				->method( 'updateToolSyncStatus' )
				->with( $event->getID(), $toolID, $this->anything(), TrackingToolAssociation::SYNC_STATUS_UNKNOWN );
		}

		$this->getWatcher( $registry, $this->getLoggerSpy( $shouldFail ), $updater )->onEventDeleted( $event );
		// The test uses soft assertions
		$this->addToAssertionCount( 1 );
	}

	public static function provideOnEventDeleted(): Generator {
		yield 'Tool attached, error' => [ true ];
		yield 'Tool attached, successful' => [ false ];
	}

	/** @covers ::validateParticipantAdded */
	public function testValidateParticipantAdded__noTool() {
		$eventWithoutTools = $this->createMock( ExistingEventRegistration::class );
		$eventWithoutTools->method( 'getTrackingTools' )->willReturn( [] );
		$status = $this->getWatcher()->validateParticipantAdded(
			$eventWithoutTools,
			$this->createMock( CentralUser::class ),
			false
		);
		$this->assertStatusGood( $status );
	}

	/**
	 * @covers ::validateParticipantAdded
	 * @dataProvider provideValidateParticipantAdded
	 */
	public function testValidateParticipantAdded( bool $shouldFail ) {
		$toolID = 1;
		$event = $this->createMock( ExistingEventRegistration::class );
		$event->method( 'getTrackingTools' )->willReturn( [ self::getAssoc( $toolID ) ] );
		$participant = $this->createMock( CentralUser::class );

		$tool = $this->createMock( TrackingTool::class );
		$registry = $this->createMock( TrackingToolRegistry::class );
		$registry->method( 'newFromDBID' )->with( $toolID )->willReturn( $tool );
		if ( $shouldFail ) {
			$toolError = 'some-error';
			$tool->expects( $this->atLeastOnce() )
				->method( 'validateParticipantAdded' )
				->willReturn( StatusValue::newFatal( $toolError ) );
			$status = $this->getWatcher( $registry )->validateParticipantAdded( $event, $participant, false );
			$this->assertStatusError( $toolError, $status );
		} else {
			$tool->expects( $this->atLeastOnce() )
				->method( 'validateParticipantAdded' )
				->willReturn( StatusValue::newGood() );
			$status = $this->getWatcher( $registry )->validateParticipantAdded( $event, $participant, false );
			$this->assertStatusGood( $status );
		}
	}

	public static function provideValidateParticipantAdded(): Generator {
		yield 'Tool attached, error' => [ true ];
		yield 'Tool attached, successful' => [ false ];
	}

	/** @covers ::onParticipantAdded */
	public function testOnParticipantAdded__noTool() {
		$eventWithoutTools = $this->createMock( ExistingEventRegistration::class );
		$eventWithoutTools->method( 'getTrackingTools' )->willReturn( [] );
		$logger = $this->getLoggerSpy( false );
		$updater = $this->createNoOpMock( TrackingToolUpdater::class );
		$this->getWatcher( null, $logger, $updater )->onParticipantAdded(
			$eventWithoutTools,
			$this->createMock( CentralUser::class ),
			false
		);
		// The test uses soft assertions
		$this->addToAssertionCount( 1 );
	}

	/**
	 * @covers ::onParticipantAdded
	 * @dataProvider provideOnParticipantAdded
	 */
	public function testOnParticipantAdded( bool $shouldFail ) {
		$toolID = 1;
		$event = $this->createMock( ExistingEventRegistration::class );
		$event->method( 'getTrackingTools' )->willReturn( [ self::getAssoc( $toolID ) ] );
		$eventID = $event->getID();

		$tool = $this->createMock( TrackingTool::class );
		$registry = $this->createMock( TrackingToolRegistry::class );
		$registry->method( 'newFromDBID' )->with( $toolID )->willReturn( $tool );
		$updater = $this->createMock( TrackingToolUpdater::class );
		if ( $shouldFail ) {
			$tool->expects( $this->atLeastOnce() )
				->method( 'addParticipant' )
				->willReturn( StatusValue::newFatal( 'some-error' ) );
			$updater->expects( $this->once() )
				->method( 'updateToolSyncStatus' )
				->with( $eventID, $toolID, $this->anything(), TrackingToolAssociation::SYNC_STATUS_FAILED );
		} else {
			$tool->expects( $this->atLeastOnce() )
				->method( 'addParticipant' )
				->willReturn( StatusValue::newGood() );
			$updater->expects( $this->once() )
				->method( 'updateToolSyncStatus' )
				->with( $eventID, $toolID, $this->anything(), TrackingToolAssociation::SYNC_STATUS_SYNCED );
		}

		$logger = $this->getLoggerSpy( $shouldFail );
		$this->getWatcher( $registry, $logger, $updater )->onParticipantAdded(
			$event,
			$this->createMock( CentralUser::class ),
			false
		);
		// The test uses soft assertions
		$this->addToAssertionCount( 1 );
	}

	public static function provideOnParticipantAdded(): Generator {
		yield 'Tool attached, error' => [ true ];
		yield 'Tool attached, successful' => [ false ];
	}

	/** @covers ::validateParticipantsRemoved */
	public function testValidateParticipantsRemoved__noTool() {
		$eventWithoutTools = $this->createMock( ExistingEventRegistration::class );
		$eventWithoutTools->method( 'getTrackingTools' )->willReturn( [] );
		$status = $this->getWatcher()->validateParticipantsRemoved( $eventWithoutTools, null, false );
		$this->assertStatusGood( $status );
	}

	/**
	 * @covers ::validateParticipantsRemoved
	 * @dataProvider provideValidateParticipantsRemoved
	 */
	public function testValidateParticipantsRemoved( bool $shouldFail ) {
		$toolID = 1;
		$event = $this->createMock( ExistingEventRegistration::class );
		$event->method( 'getTrackingTools' )->willReturn( [ self::getAssoc( $toolID ) ] );

		$tool = $this->createMock( TrackingTool::class );
		$registry = $this->createMock( TrackingToolRegistry::class );
		$registry->method( 'newFromDBID' )->with( $toolID )->willReturn( $tool );
		if ( $shouldFail ) {
			$toolError = 'some-error';
			$tool->expects( $this->atLeastOnce() )
				->method( 'validateParticipantsRemoved' )
				->willReturn( StatusValue::newFatal( $toolError ) );
			$status = $this->getWatcher( $registry )->validateParticipantsRemoved( $event, null, false );
			$this->assertStatusError( $toolError, $status );
		} else {
			$tool->expects( $this->atLeastOnce() )
				->method( 'validateParticipantsRemoved' )
				->willReturn( StatusValue::newGood() );
			$status = $this->getWatcher( $registry )->validateParticipantsRemoved( $event, null, false );
			$this->assertStatusGood( $status );
		}
	}

	public static function provideValidateParticipantsRemoved(): Generator {
		yield 'Tool attached, error' => [ true ];
		yield 'Tool attached, successful' => [ false ];
	}

	/** @covers ::onParticipantsRemoved */
	public function testOnParticipantsRemoved__noTool() {
		$eventWithoutTools = $this->createMock( ExistingEventRegistration::class );
		$eventWithoutTools->method( 'getTrackingTools' )->willReturn( [] );
		$logger = $this->getLoggerSpy( false );
		$updater = $this->createNoOpMock( TrackingToolUpdater::class );
		$this->getWatcher( null, $logger, $updater )->onParticipantsRemoved( $eventWithoutTools, null, false );
		// The test uses soft assertions
		$this->addToAssertionCount( 1 );
	}

	/**
	 * @covers ::onParticipantsRemoved
	 * @dataProvider provideOnParticipantsRemoved
	 */
	public function testOnParticipantsRemoved( bool $shouldFail ) {
		$toolID = 1;
		$event = $this->createMock( ExistingEventRegistration::class );
		$event->method( 'getTrackingTools' )->willReturn( [ self::getAssoc( $toolID ) ] );

		$tool = $this->createMock( TrackingTool::class );
		$registry = $this->createMock( TrackingToolRegistry::class );
		$registry->method( 'newFromDBID' )->with( $toolID )->willReturn( $tool );
		$updater = $this->createMock( TrackingToolUpdater::class );
		if ( $shouldFail ) {
			$toolError = 'some-error';
			$tool->expects( $this->atLeastOnce() )
				->method( 'removeParticipants' )
				->willReturn( StatusValue::newFatal( $toolError ) );
			$updater->expects( $this->once() )
				->method( 'updateToolSyncStatus' )
				->with( $event->getID(), $toolID, $this->anything(), TrackingToolAssociation::SYNC_STATUS_FAILED );
		} else {
			$tool->expects( $this->atLeastOnce() )
				->method( 'removeParticipants' )
				->willReturn( StatusValue::newGood() );
			$updater->expects( $this->once() )
				->method( 'updateToolSyncStatus' )
				->with( $event->getID(), $toolID, $this->anything(), TrackingToolAssociation::SYNC_STATUS_SYNCED );
		}

		$logger = $this->getLoggerSpy( $shouldFail );
		$this->getWatcher( $registry, $logger, $updater )->onParticipantsRemoved( $event, null, false );
		// The test uses soft assertions
		$this->addToAssertionCount( 1 );
	}

	public static function provideOnParticipantsRemoved(): Generator {
		yield 'Tool attached, error' => [ true ];
		yield 'Tool attached, successful' => [ false ];
	}
}
