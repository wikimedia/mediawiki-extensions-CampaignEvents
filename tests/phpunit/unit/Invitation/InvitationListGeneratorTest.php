<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Invitation;

use Generator;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\PageEventLookup;
use MediaWiki\Extension\CampaignEvents\Invitation\FindPotentialInviteesJob;
use MediaWiki\Extension\CampaignEvents\Invitation\InvitationListGenerator;
use MediaWiki\Extension\CampaignEvents\Invitation\InvitationListStore;
use MediaWiki\Extension\CampaignEvents\Invitation\Worklist;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFactory;
use MediaWiki\Extension\CampaignEvents\MWEntity\InvalidEventPageException;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWPageProxy;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\Page\PageIdentityValue;
use MediaWiki\Permissions\Authority;
use MediaWikiUnitTestCase;
use Wikimedia\TestingAccessWrapper;

/**
 * @covers \MediaWiki\Extension\CampaignEvents\Invitation\InvitationListGenerator
 */
class InvitationListGeneratorTest extends MediaWikiUnitTestCase {
	private const VALID_EVENT_PAGE = true;
	private const USES_REGISTRATION = true;
	private const EVENT_ENDED = true;
	private const IS_ORGANIZER = true;

	private const INVALID_EVENT_PAGE = false;
	private const DOES_NOT_USE_REGISTRATION = false;
	private const EVENT_HAS_NOT_ENDED = false;
	private const IS_NOT_ORGANIZER = false;
	private const EVENT_DELETED = true;

	private function getGenerator(
		?PermissionChecker $permissionChecker = null,
		?CampaignsPageFactory $campaignsPageFactory = null,
		?PageEventLookup $pageEventLookup = null,
		?OrganizersStore $organizersStore = null,
		?InvitationListStore $invitationListStore = null,
		?JobQueueGroup $jobQueueGroup = null
	): InvitationListGenerator {
		if ( !$permissionChecker ) {
			$permissionChecker = $this->createMock( PermissionChecker::class );
			$permissionChecker->method( 'userCanUseInvitationLists' )->willReturn( true );
		}
		return new InvitationListGenerator(
			$permissionChecker,
			$campaignsPageFactory ?? $this->createMock( CampaignsPageFactory::class ),
			$pageEventLookup ?? $this->createMock( PageEventLookup::class ),
			$organizersStore ?? $this->createMock( OrganizersStore::class ),
			$this->createMock( CampaignsCentralUserLookup::class ),
			$invitationListStore ?? $this->createMock( InvitationListStore::class ),
			$jobQueueGroup ?? $this->createMock( JobQueueGroup::class )
		);
	}

	/**
	 * @dataProvider provideCreateIfAllowed
	 */
	public function testCreateIfAllowed( bool $canUseInvitationLists, ?string $expectedError ) {
		$permChecker = $this->createMock( PermissionChecker::class );
		$permChecker->method( 'userCanUseInvitationLists' )->willReturn( $canUseInvitationLists );
		$generator = $this->getGenerator( $permChecker );
		$worklist = $this->createMock( Worklist::class );
		$performer = $this->createMock( Authority::class );
		$res = $generator->createIfAllowed( 'Name', null, $worklist, $performer );
		if ( $expectedError ) {
			$this->assertStatusNotGood( $res );
			$this->assertStatusMessage( $expectedError, $res );
		} else {
			$this->assertStatusGood( $res );
		}
	}

	public static function provideCreateIfAllowed(): Generator {
		yield 'Permission error' => [
			false,
			'campaignevents-invitation-list-not-allowed'
		];
		yield 'Successful' => [
			true,
			null
		];
	}

	/**
	 * @dataProvider provideCreateUnsafe
	 */
	public function testCreateUnsafe(
		?string $expectedError,
		string $name,
		?string $eventPageTitle,
		bool $eventPageIsValid,
		bool $pageUsesRegistration,
		bool $eventIsPast,
		bool $isOrganizer,
		bool $eventDeleted = false
	) {
		$eventPage = $this->createMock( MWPageProxy::class );
		$pageFactory = $this->createMock( CampaignsPageFactory::class );
		if ( $eventPageIsValid ) {
			$pageFactory->method( 'newLocalExistingPageFromString' )
				->with( $eventPageTitle )
				->willReturn( $eventPage );
		} else {
			$pageFactory->method( 'newLocalExistingPageFromString' )
				->with( $eventPageTitle )
				->willThrowException( $this->createMock( InvalidEventPageException::class ) );
		}

		$event = $this->createMock( ExistingEventRegistration::class );
		$event->method( 'isPast' )->willReturn( $eventIsPast );
		$event->method( 'getDeletionTimestamp' )->willReturn( $eventDeleted ? '20200101120000' : null );
		$pageEventLookup = $this->createMock( PageEventLookup::class );
		$pageEventLookup->method( 'getRegistrationForPage' )
			->with( $eventPage )
			->willReturn( $pageUsesRegistration ? $event : null );

		$organizersStore = $this->createMock( OrganizersStore::class );
		$organizersStore->method( 'isEventOrganizer' )
			->willReturn( $isOrganizer );

		$worklist = new Worklist( [
			'some_wiki' => [
				new PageIdentityValue( 42, NS_MAIN, 'Some_title', 'some_wiki' )
			]
		] );
		$performer = $this->createMock( Authority::class );

		$invitationListStore = $this->createMock( InvitationListStore::class );
		$jobQueueGroup = $this->createMock( JobQueueGroup::class );
		$listID = 123456;
		if ( !$expectedError ) {
			$invitationListStore->expects( $this->once() )
				->method( 'createInvitationList' )
				->willReturn( $listID );
			$jobQueueGroup->expects( $this->once() )
				->method( 'push' )
				->willReturnCallback( function ( $job ) use ( $listID, $worklist ) {
					$this->assertInstanceOf( FindPotentialInviteesJob::class, $job );
					/** @var TestingAccessWrapper&FindPotentialInviteesJob $jobWrapper */
					$jobWrapper = TestingAccessWrapper::newFromObject( $job );
					$this->assertSame( $listID, $jobWrapper->listID );
					$this->assertEquals( $worklist, $jobWrapper->worklist );
				} );
		}

		$generator = $this->getGenerator(
			null, $pageFactory, $pageEventLookup, $organizersStore, $invitationListStore, $jobQueueGroup
		);

		$res = $generator->createUnsafe( $name, $eventPageTitle, $worklist, $performer );
		if ( $expectedError ) {
			$this->assertStatusNotGood( $res );
			$this->assertStatusMessage( $expectedError, $res );
		} else {
			$this->assertStatusGood( $res );
			$this->assertStatusValue( $listID, $res );
		}
	}

	public static function provideCreateUnsafe(): Generator {
		yield 'Empty name' => [
			'campaignevents-invitation-list-error-empty-name',
			'',
			null,
			self::INVALID_EVENT_PAGE,
			self::DOES_NOT_USE_REGISTRATION,
			self::EVENT_HAS_NOT_ENDED,
			self::IS_NOT_ORGANIZER
		];
		yield 'Space-only name' => [
			'campaignevents-invitation-list-error-empty-name',
			"  \t\n ",
			null,
			self::INVALID_EVENT_PAGE,
			self::DOES_NOT_USE_REGISTRATION,
			self::EVENT_HAS_NOT_ENDED,
			self::IS_NOT_ORGANIZER
		];
		yield 'Page is not in the event namespace' => [
			'campaignevents-invitation-list-error-invalid-page',
			'Name',
			'Some random page',
			self::INVALID_EVENT_PAGE,
			self::DOES_NOT_USE_REGISTRATION,
			self::EVENT_HAS_NOT_ENDED,
			self::IS_NOT_ORGANIZER
		];
		yield 'Event page does not use event registration' => [
			'campaignevents-invitation-list-error-invalid-page',
			'Name',
			'Event:My event',
			self::VALID_EVENT_PAGE,
			self::DOES_NOT_USE_REGISTRATION,
			self::EVENT_HAS_NOT_ENDED,
			self::IS_NOT_ORGANIZER
		];
		yield 'Event deleted' => [
			'campaignevents-invitation-list-error-event-deleted',
			'Name',
			'Event:My event',
			self::VALID_EVENT_PAGE,
			self::USES_REGISTRATION,
			self::EVENT_HAS_NOT_ENDED,
			self::IS_ORGANIZER,
			self::EVENT_DELETED,
		];
		yield 'Event has ended' => [
			'campaignevents-invitation-list-error-event-ended',
			'Name',
			'Event:My event',
			self::VALID_EVENT_PAGE,
			self::USES_REGISTRATION,
			self::EVENT_ENDED,
			self::IS_ORGANIZER
		];
		yield 'User is not an organizer' => [
			'campaignevents-invitation-list-error-not-organizer',
			'Name',
			'Event:My event',
			self::VALID_EVENT_PAGE,
			self::USES_REGISTRATION,
			self::EVENT_HAS_NOT_ENDED,
			self::IS_NOT_ORGANIZER
		];

		yield 'Successful without event page' => [
			null,
			'Name',
			null,
			self::VALID_EVENT_PAGE,
			self::USES_REGISTRATION,
			self::EVENT_HAS_NOT_ENDED,
			self::IS_ORGANIZER
		];
		yield 'Successful with event page' => [
			null,
			'Name',
			'Event:My event',
			self::VALID_EVENT_PAGE,
			self::USES_REGISTRATION,
			self::EVENT_HAS_NOT_ENDED,
			self::IS_ORGANIZER
		];

		yield 'Name exceeds max length' => [
			'campaignevents-generateinvitationlist-name-too-long',
			str_repeat( 'a', InvitationListGenerator::INVITATION_LIST_NAME_MAXLENGTH_BYTES + 1 ),
			null,
			self::VALID_EVENT_PAGE,
			self::USES_REGISTRATION,
			self::EVENT_HAS_NOT_ENDED,
			self::IS_ORGANIZER
		];
	}
}
