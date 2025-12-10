<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\EventContribution;

use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionStore;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionValidator;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\Permissions\Authority;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Revision\RevisionStoreFactory;
use MediaWiki\Tests\Unit\DummyServicesTrait;
use MediaWiki\User\UserIdentity;
use MediaWiki\Utils\MWTimestamp;
use MediaWikiUnitTestCase;
use StatusValue;

/**
 * @group Test
 * @covers \MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionValidator
 */
class EventContributionValidatorTest extends MediaWikiUnitTestCase {
	use DummyServicesTrait;

	private EventContributionValidator $validator;
	private CampaignsCentralUserLookup $centralUserLookup;
	private ParticipantsStore $participantsStore;
	private JobQueueGroup $jobQueueGroup;
	private RevisionStoreFactory $revisionStoreFactory;
	private EventContributionStore $eventContributionStore;
	private OrganizersStore $organizersStore;
	private PermissionChecker $permissionChecker;
	private Authority $performer;

	protected function setUp(): void {
		parent::setUp();

		$this->centralUserLookup = $this->createMock( CampaignsCentralUserLookup::class );
		$this->participantsStore = $this->createMock( ParticipantsStore::class );
		$this->jobQueueGroup = $this->createMock( JobQueueGroup::class );
		$this->revisionStoreFactory = $this->createMock( RevisionStoreFactory::class );
		$this->eventContributionStore = $this->createMock( EventContributionStore::class );
		$this->performer = $this->createMock( Authority::class );
		$this->organizersStore = $this->createMock( OrganizersStore::class );
		$this->permissionChecker = $this->createMock( PermissionChecker::class );

		$this->validator = new EventContributionValidator(
			$this->centralUserLookup,
			$this->jobQueueGroup,
			$this->revisionStoreFactory,
			$this->eventContributionStore,
			$this->permissionChecker
		);
	}

	public function testValidateAndScheduleUserNotGlobal(): void {
		// Setup user not global
		$this->centralUserLookup->method( 'newFromAuthority' )
			->with( $this->performer )
			->willThrowException( new UserNotGlobalException( 123 ) );

		// Create a mock event
		$event = $this->createMock( ExistingEventRegistration::class );
		$event->method( 'getID' )->willReturn( 1 );

		// Expect exception
		$this->expectException( LocalizedHttpException::class );
		$this->expectExceptionMessage( 'campaignevents-event-contribution-user-not-global' );

		$this->validator->validateAndSchedule( $event, 123, 'testwiki', $this->performer );
	}

	public function testValidateAndSchedule__alreadyAssociatedSameEvent(): void {
		$eventID = 12345;
		$wikiID = 'awiki';
		$revID = 54321;

		$event = $this->createMock( ExistingEventRegistration::class );
		$event->method( 'getID' )->willReturn( $eventID );

		$this->eventContributionStore->expects( $this->atLeastOnce() )
			->method( 'getEventIDForRevision' )
			->with( $wikiID, $revID )
			->willReturn( $eventID );

		$this->validator->validateAndSchedule( $event, $revID, $wikiID, $this->performer );
		// Assert no exception thrown.
		$this->addToAssertionCount( 1 );
	}

	public function testValidateAndSchedule__alreadyAssociatedDifferentEvent(): void {
		$eventID = 12345;
		$wikiID = 'awiki';
		$revID = 54321;

		$event = $this->createMock( ExistingEventRegistration::class );
		$event->method( 'getID' )->willReturn( $eventID );

		$this->eventContributionStore->expects( $this->atLeastOnce() )
			->method( 'getEventIDForRevision' )
			->with( $wikiID, $revID )
			->willReturn( $eventID + 1 );

		$this->expectException( LocalizedHttpException::class );
		$this->expectExceptionMessage( 'campaignevents-event-contribution-already-associated' );
		$this->validator->validateAndSchedule( $event, $revID, $wikiID, $this->performer );
	}

	public function testValidateAndScheduleContributionTrackingDisabled(): void {
		// Setup central user
		$centralUser = $this->createMock( CentralUser::class );
		$this->centralUserLookup->method( 'newFromAuthority' )
			->with( $this->performer )
			->willReturn( $centralUser );

		// Create a mock event with contribution tracking disabled
		$event = $this->createMock( ExistingEventRegistration::class );
		$event->method( 'getID' )->willReturn( 1 );
		$event->method( 'hasContributionTracking' )->willReturn( false );

		// Expect exception
		$this->expectException( LocalizedHttpException::class );
		$this->expectExceptionMessage( 'campaignevents-event-contribution-tracking-disabled' );

		$this->validator->validateAndSchedule( $event, 123, 'testwiki', $this->performer );
	}

	public function testValidateAndScheduleEventDeleted(): void {
		// Setup central user
		$centralUser = $this->createMock( CentralUser::class );
		$this->centralUserLookup->method( 'newFromAuthority' )
			->with( $this->performer )
			->willReturn( $centralUser );

		// Create a mock event that is deleted
		$event = $this->createMock( ExistingEventRegistration::class );
		$event->method( 'getID' )->willReturn( 1 );
		$event->method( 'hasContributionTracking' )->willReturn( true );
		$event->method( 'getDeletionTimestamp' )->willReturn( '20230101000000' );

		// Expect exception
		$this->expectException( LocalizedHttpException::class );
		$this->expectExceptionMessage( 'campaignevents-event-contribution-event-deleted' );

		$this->validator->validateAndSchedule( $event, 123, 'testwiki', $this->performer );
	}

	public function testValidateAndSchedule__eventNotOngoing(): void {
		// Setup central user
		$centralUser = $this->createMock( CentralUser::class );
		$this->centralUserLookup->method( 'newFromAuthority' )
			->with( $this->performer )
			->willReturn( $centralUser );

		// Create a mock event that is in the past
		$event = $this->createMock( ExistingEventRegistration::class );
		$event->method( 'getID' )->willReturn( 1 );
		$event->method( 'getDeletionTimestamp' )->willReturn( null );
		$event->method( 'isOngoing' )->willReturn( false );
		$event->method( 'hasContributionTracking' )->willReturn( true );

		// Expect exception
		$this->expectException( LocalizedHttpException::class );
		$this->expectExceptionMessage( 'campaignevents-event-contribution-event-not-active' );

		$this->validator->validateAndSchedule( $event, 123, 'testwiki', $this->performer );
	}

	public function testValidateAndScheduleRevisionNotFound(): void {
		// Setup central user
		$centralUser = $this->createMock( CentralUser::class );
		$this->centralUserLookup->method( 'newFromAuthority' )
			->with( $this->performer )
			->willReturn( $centralUser );

		// Create a mock event
		$event = $this->createMock( ExistingEventRegistration::class );
		$event->method( 'getID' )->willReturn( 1 );
		$event->method( 'getDeletionTimestamp' )->willReturn( null );
		$event->method( 'isOngoing' )->willReturn( true );
		$event->method( 'hasContributionTracking' )->willReturn( true );
		$event->method( 'getWikis' )->willReturn( EventRegistration::ALL_WIKIS );

		// Setup revision store to return null (revision not found)
		$revisionStore = $this->createMock( RevisionStore::class );
		$revisionStore->method( 'getRevisionById' )
			->with( 123 )
			->willReturn( null );
		$this->revisionStoreFactory->method( 'getRevisionStore' )
			->with( 'testwiki' )
			->willReturn( $revisionStore );

		// Expect exception
		$this->expectException( LocalizedHttpException::class );
		$this->expectExceptionMessage( 'campaignevents-event-contribution-revision-not-found' );

		$this->validator->validateAndSchedule( $event, 123, 'testwiki', $this->performer );
	}

	public function testValidateAndSchedulePermissionError(): void {
		// Setup central user
		$centralUser = $this->createMock( CentralUser::class );
		$centralUser->method( 'getCentralID' )->willReturn( 123 );
		$this->centralUserLookup->method( 'newFromAuthority' )
			->with( $this->performer )
			->willReturn( $centralUser );

		// Create a mock event
		$event = $this->createMock( ExistingEventRegistration::class );
		$event->method( 'getID' )->willReturn( 1 );
		$event->method( 'getDeletionTimestamp' )->willReturn( null );
		$event->method( 'isOngoing' )->willReturn( true );
		$event->method( 'hasContributionTracking' )->willReturn( true );
		$event->method( 'getWikis' )->willReturn( EventRegistration::ALL_WIKIS );

		// Setup revision
		$revisionAuthor = $this->createMock( UserIdentity::class );
		$revision = $this->createMock( RevisionRecord::class );
		$revision->method( 'getTimestamp' )->willReturn( MWTimestamp::now( TS_MW ) );
		$revision->method( 'getUser' )->willReturn( $revisionAuthor );
		$revision->method( 'getPageId' )->willReturn( 456 );

		$revisionStore = $this->createMock( RevisionStore::class );
		$revisionStore->method( 'getRevisionById' )
			->with( 123 )
			->willReturn( $revision );
		$this->revisionStoreFactory->method( 'getRevisionStore' )
			->with( 'testwiki' )
			->willReturn( $revisionStore );

		// Setup same central user for revision author
		$revisionAuthorCentralUser = $this->createMock( CentralUser::class );
		$revisionAuthorCentralUser->method( 'getCentralID' )->willReturn( 123 );
		$this->centralUserLookup->method( 'newFromUserIdentity' )
			->with( $revisionAuthor )
			->willReturn( $revisionAuthorCentralUser );

		// Setup user not participating in event
		$this->participantsStore->method( 'userParticipatesInEvent' )
			->with( 1, $centralUser, true )
			->willReturn( false );

		// setup permission checker
		$this->permissionChecker->method( 'userCanAddContribution' )->willReturn(
			StatusValue::newFatal( 'permission-error' )
		);

		// Expect exception
		$this->expectException( LocalizedHttpException::class );
		$this->expectExceptionMessage( 'permission-error' );

		$this->validator->validateAndSchedule( $event, 123, 'testwiki', $this->performer );
	}

	public function testValidateAndScheduleOrganizerNotAuthor(): void {
		// Setup central user
		$centralUser = $this->createMock( CentralUser::class );
		$centralUser->method( 'getCentralID' )->willReturn( 123 );
		$this->centralUserLookup->method( 'newFromAuthority' )
			->with( $this->performer )
			->willReturn( $centralUser );

		// Create a mock event
		$event = $this->createMock( ExistingEventRegistration::class );
		$event->method( 'getID' )->willReturn( 1 );
		$event->method( 'getDeletionTimestamp' )->willReturn( null );
		$event->method( 'isOngoing' )->willReturn( true );
		$event->method( 'hasContributionTracking' )->willReturn( true );
		$event->method( 'getWikis' )->willReturn( EventRegistration::ALL_WIKIS );

		// Setup revision
		$revisionAuthor = $this->createMock( UserIdentity::class );
		$revision = $this->createMock( RevisionRecord::class );
		$revision->method( 'getTimestamp' )->willReturn( MWTimestamp::now( TS_MW ) );
		$revision->method( 'getUser' )->willReturn( $revisionAuthor );
		$revision->method( 'getPageId' )->willReturn( 456 );

		$revisionStore = $this->createMock( RevisionStore::class );
		$revisionStore->method( 'getRevisionById' )
			->with( 123 )
			->willReturn( $revision );
		$this->revisionStoreFactory->method( 'getRevisionStore' )
			->with( 'testwiki' )
			->willReturn( $revisionStore );

		// Setup same central user for revision author
		$authorId = 234;
		$revisionAuthorCentralUser = $this->createMock( CentralUser::class );
		$revisionAuthorCentralUser->method( 'getCentralID' )->willReturn( $authorId );
		$this->centralUserLookup->method( 'newFromUserIdentity' )
			->with( $revisionAuthor )
			->willReturn( $revisionAuthorCentralUser );

		// Setup user not participating in event
		$this->participantsStore->method( 'userParticipatesInEvent' )
			->with( 1, $centralUser, true )
			->willReturn( false );

		// setup permission checker
		$this->permissionChecker->method( 'userCanAddContribution' )->willReturn(
			StatusValue::newGood()
		);
		// Expect job to be pushed
		$this->jobQueueGroup->expects( $this->once() )
			->method( 'push' )
			->willReturnCallback(
				function ( $job ) use ( $authorId ) {
					$this->assertSame( $authorId, $job->params['userId'] );
				}
			);

		$this->validator->validateAndSchedule( $event, 123, 'testwiki', $this->performer );
	}

	/**
	 * @dataProvider provideTargetWikis
	 */
	public function testValidateAndScheduleTargetWikis(
		$isGood,
		$targetWikis
	): void {
		// Setup central user
		$centralUser = $this->createMock( CentralUser::class );
		$centralUser->method( 'getCentralID' )->willReturn( 123 );
		$this->centralUserLookup->method( 'newFromAuthority' )
			->with( $this->performer )
			->willReturn( $centralUser );

		// Create a mock event
		$event = $this->createMock( ExistingEventRegistration::class );
		$event->method( 'getID' )->willReturn( 1 );
		$event->method( 'getDeletionTimestamp' )->willReturn( null );
		$event->method( 'isOngoing' )->willReturn( true );
		$event->method( 'hasContributionTracking' )->willReturn( true );
		$event->method( 'getWikis' )->willReturn( $targetWikis );

		// Setup revision
		$revisionAuthor = $this->createMock( UserIdentity::class );
		$revision = $this->createMock( RevisionRecord::class );
		$revision->method( 'getTimestamp' )->willReturn( MWTimestamp::now( TS_MW ) );
		$revision->method( 'getUser' )->willReturn( $revisionAuthor );
		$revision->method( 'getPageId' )->willReturn( 456 );

		$revisionStore = $this->createMock( RevisionStore::class );
		$revisionStore->method( 'getRevisionById' )
			->with( 123 )
			->willReturn( $revision );
		$this->revisionStoreFactory->method( 'getRevisionStore' )
			->with( 'testwiki' )
			->willReturn( $revisionStore );
		// Setup same central user for revision author
		$revisionAuthorCentralUser = $this->createMock( CentralUser::class );
		$revisionAuthorCentralUser->method( 'getCentralID' )->willReturn( 123 );
		$this->centralUserLookup->method( 'newFromUserIdentity' )
			->with( $revisionAuthor )
			->willReturn( $revisionAuthorCentralUser );

		// Setup user participating in event
		$this->participantsStore->method( 'userParticipatesInEvent' )
			->with( 1, $centralUser, true )
			->willReturn( true );

		// setup permission checker
		$this->permissionChecker->method( 'userCanAddContribution' )->willReturn(
			StatusValue::newGood()
		);
		if ( $isGood ) {
			// Expect job to be pushed
			$this->jobQueueGroup->expects( $this->once() )
				->method( 'push' );
		} else {
			// Expect exception
			$this->expectException( LocalizedHttpException::class );
			$this->expectExceptionMessage( 'campaignevents-event-contribution-not-target-wiki' );
		}

		$this->validator->validateAndSchedule( $event, 123, 'testwiki', $this->performer );
	}

	public function provideTargetWikis() {
		yield 'one wiki' => [ true, [ 'testwiki' ] ];
		yield 'two wikis' => [ true, [ 'testwiki', 'testwiki2' ] ];
		yield 'all wikis' => [ true, EventRegistration::ALL_WIKIS ];
		yield 'no wikis' => [ false, [] ];
		yield 'bad wikis' => [ false, [ 'testwiki2', 'testwiki3' ] ];
	}

	public function testValidateAndScheduleSuccess(): void {
		// Setup central user
		$centralUser = $this->createMock( CentralUser::class );
		$centralUser->method( 'getCentralID' )->willReturn( 123 );
		$this->centralUserLookup->method( 'newFromAuthority' )
			->with( $this->performer )
			->willReturn( $centralUser );

		// Create a mock event
		$event = $this->createMock( ExistingEventRegistration::class );
		$event->method( 'getID' )->willReturn( 1 );
		$event->method( 'getDeletionTimestamp' )->willReturn( null );
		$event->method( 'isOngoing' )->willReturn( true );
		$event->method( 'hasContributionTracking' )->willReturn( true );
		$event->method( 'getWikis' )->willReturn( EventRegistration::ALL_WIKIS );

		// Setup revision
		$revisionAuthor = $this->createMock( UserIdentity::class );
		$revision = $this->createMock( RevisionRecord::class );
		$revision->method( 'getTimestamp' )->willReturn( MWTimestamp::now( TS_MW ) );
		$revision->method( 'getPageId' )->willReturn( 456 );
		$revision->method( 'getUser' )->willReturn( $revisionAuthor );

		$revisionStore = $this->createMock( RevisionStore::class );
		$revisionStore->method( 'getRevisionById' )
			->with( 123 )
			->willReturn( $revision );
		$this->revisionStoreFactory->method( 'getRevisionStore' )
			->with( 'testwiki' )
			->willReturn( $revisionStore );

		// Setup same central user for revision author
		$revisionAuthorCentralUser = $this->createMock( CentralUser::class );
		$revisionAuthorCentralUser->method( 'getCentralID' )->willReturn( 123 );
		$this->centralUserLookup->method( 'newFromUserIdentity' )
			->with( $revisionAuthor )
			->willReturn( $revisionAuthorCentralUser );

		// setup permission checker
		$this->permissionChecker->method( 'userCanAddContribution' )->willReturn(
			StatusValue::newGood()
		);

		// Setup user participating in event
		$this->participantsStore->method( 'userParticipatesInEvent' )
			->with( 1, $centralUser, true )
			->willReturn( true );

		// Expect job to be pushed
		$this->jobQueueGroup->expects( $this->once() )
			->method( 'push' );

		// Should not throw exception
		$this->validator->validateAndSchedule( $event, 123, 'testwiki', $this->performer );
	}
}
