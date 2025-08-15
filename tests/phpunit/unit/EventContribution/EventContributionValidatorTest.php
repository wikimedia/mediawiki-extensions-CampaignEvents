<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\EventContribution;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionValidator;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\Permissions\Authority;
use MediaWiki\Rest\HttpException;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Revision\RevisionStoreFactory;
use MediaWiki\Tests\Unit\DummyServicesTrait;
use MediaWiki\User\UserIdentity;
use MediaWiki\Utils\MWTimestamp;
use MediaWikiUnitTestCase;

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
	private ServiceOptions $options;
	private Authority $performer;

	protected function setUp(): void {
		parent::setUp();

		$this->centralUserLookup = $this->createMock( CampaignsCentralUserLookup::class );
		$this->participantsStore = $this->createMock( ParticipantsStore::class );
		$this->jobQueueGroup = $this->createMock( JobQueueGroup::class );
		$this->revisionStoreFactory = $this->createMock( RevisionStoreFactory::class );
		$this->options = $this->createMock( ServiceOptions::class );
		$this->performer = $this->createMock( Authority::class );

		$this->validator = new EventContributionValidator(
			$this->centralUserLookup,
			$this->participantsStore,
			$this->jobQueueGroup,
			$this->revisionStoreFactory,
			$this->options
		);
	}

	public function testValidateAndScheduleFeatureFlagDisabled(): void {
		// Setup feature flag disabled
		$this->options->method( 'get' )
			->with( 'CampaignEventsEnableContributionTracking' )
			->willReturn( false );

		// Create a mock event
		$event = $this->createMock( ExistingEventRegistration::class );
		$event->method( 'getID' )->willReturn( 1 );

		$this->expectException( HttpException::class );
		$this->expectExceptionMessage( 'This feature is not enabled on this wiki' );

		$this->validator->validateAndSchedule( $event, 123, 'testwiki', $this->performer );
	}

	public function testValidateAndScheduleUserNotGlobal(): void {
		// Setup feature flag enabled
		$this->options->method( 'get' )
			->with( 'CampaignEventsEnableContributionTracking' )
			->willReturn( true );

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

	public function testValidateAndScheduleContributionTrackingDisabled(): void {
		// Setup feature flag enabled
		$this->options->method( 'get' )
			->with( 'CampaignEventsEnableContributionTracking' )
			->willReturn( true );

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
		// Setup feature flag enabled
		$this->options->method( 'get' )
			->with( 'CampaignEventsEnableContributionTracking' )
			->willReturn( true );

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

	public function testValidateAndScheduleEventPast(): void {
		// Setup feature flag enabled
		$this->options->method( 'get' )
			->with( 'CampaignEventsEnableContributionTracking' )
			->willReturn( true );

		// Setup central user
		$centralUser = $this->createMock( CentralUser::class );
		$this->centralUserLookup->method( 'newFromAuthority' )
			->with( $this->performer )
			->willReturn( $centralUser );

		// Create a mock event that is in the past
		$event = $this->createMock( ExistingEventRegistration::class );
		$event->method( 'getID' )->willReturn( 1 );
		$event->method( 'getDeletionTimestamp' )->willReturn( null );
		$event->method( 'isPast' )->willReturn( true );
		$event->method( 'hasContributionTracking' )->willReturn( true );

		// Expect exception
		$this->expectException( LocalizedHttpException::class );
		$this->expectExceptionMessage( 'campaignevents-event-contribution-event-ended' );

		$this->validator->validateAndSchedule( $event, 123, 'testwiki', $this->performer );
	}

	public function testValidateAndScheduleRevisionNotFound(): void {
		// Setup feature flag enabled
		$this->options->method( 'get' )
			->with( 'CampaignEventsEnableContributionTracking' )
			->willReturn( true );

		// Setup central user
		$centralUser = $this->createMock( CentralUser::class );
		$this->centralUserLookup->method( 'newFromAuthority' )
			->with( $this->performer )
			->willReturn( $centralUser );

		// Create a mock event
		$event = $this->createMock( ExistingEventRegistration::class );
		$event->method( 'getID' )->willReturn( 1 );
		$event->method( 'getDeletionTimestamp' )->willReturn( null );
		$event->method( 'isPast' )->willReturn( false );
		$event->method( 'hasContributionTracking' )->willReturn( true );

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

	public function testValidateAndScheduleRevisionTooOld(): void {
		// Setup feature flag enabled
		$this->options->method( 'get' )
			->with( 'CampaignEventsEnableContributionTracking' )
			->willReturn( true );

		// Setup central user
		$centralUser = $this->createMock( CentralUser::class );
		$this->centralUserLookup->method( 'newFromAuthority' )
			->with( $this->performer )
			->willReturn( $centralUser );

		// Create a mock event
		$event = $this->createMock( ExistingEventRegistration::class );
		$event->method( 'getID' )->willReturn( 1 );
		$event->method( 'getDeletionTimestamp' )->willReturn( null );
		$event->method( 'isPast' )->willReturn( false );
		$event->method( 'hasContributionTracking' )->willReturn( true );

		// Setup revision with old timestamp
		$revision = $this->createMock( RevisionRecord::class );
		$revision->method( 'getTimestamp' )->willReturn( '20230101000000' );
		$revision->method( 'getPageId' )->willReturn( 456 );
		$revision->method( 'getUser' )->willReturn( $this->createMock( UserIdentity::class ) );

		$revisionStore = $this->createMock( RevisionStore::class );
		$revisionStore->method( 'getRevisionById' )
			->with( 123 )
			->willReturn( $revision );
		$this->revisionStoreFactory->method( 'getRevisionStore' )
			->with( 'testwiki' )
			->willReturn( $revisionStore );

		// Expect exception
		$this->expectException( LocalizedHttpException::class );
		$this->expectExceptionMessage( 'campaignevents-event-contribution-timestamp-too-old' );

		$this->validator->validateAndSchedule( $event, 123, 'testwiki', $this->performer );
	}

	public function testValidateAndScheduleNotOwner(): void {
		// Setup feature flag enabled
		$this->options->method( 'get' )
			->with( 'CampaignEventsEnableContributionTracking' )
			->willReturn( true );

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
		$event->method( 'isPast' )->willReturn( false );
		$event->method( 'hasContributionTracking' )->willReturn( true );

		// Setup revision with different author
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

		// Setup different central user for revision author
		$revisionAuthorCentralUser = $this->createMock( CentralUser::class );
		$revisionAuthorCentralUser->method( 'getCentralID' )->willReturn( 456 );
		$this->centralUserLookup->method( 'newFromUserIdentity' )
			->with( $revisionAuthor )
			->willReturn( $revisionAuthorCentralUser );

		// Expect exception
		$this->expectException( LocalizedHttpException::class );
		$this->expectExceptionMessage( 'campaignevents-event-contribution-not-owner' );

		$this->validator->validateAndSchedule( $event, 123, 'testwiki', $this->performer );
	}

	public function testValidateAndScheduleNotParticipant(): void {
		// Setup feature flag enabled
		$this->options->method( 'get' )
			->with( 'CampaignEventsEnableContributionTracking' )
			->willReturn( true );

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
		$event->method( 'isPast' )->willReturn( false );
		$event->method( 'hasContributionTracking' )->willReturn( true );

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

		// Expect exception
		$this->expectException( LocalizedHttpException::class );
		$this->expectExceptionMessage( 'campaignevents-event-contribution-not-participant' );

		$this->validator->validateAndSchedule( $event, 123, 'testwiki', $this->performer );
	}

	public function testValidateAndScheduleSuccess(): void {
		// Setup feature flag enabled
		$this->options->method( 'get' )
			->with( 'CampaignEventsEnableContributionTracking' )
			->willReturn( true );

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
		$event->method( 'isPast' )->willReturn( false );
		$event->method( 'hasContributionTracking' )->willReturn( true );

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
