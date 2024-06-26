<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Permissions;

use Generator;
use MediaWiki\Block\Block;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsAuthority;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsPage;
use MediaWiki\Extension\CampaignEvents\MWEntity\IPermissionsLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWAuthorityProxy;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageAuthorLookup;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Permissions\Authority;
use MediaWiki\Tests\Unit\Permissions\MockAuthorityTrait;
use MediaWiki\User\UserIdentityValue;
use MediaWikiUnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker
 * @covers ::__construct
 */
class PermissionCheckerTest extends MediaWikiUnitTestCase {
	use MockAuthorityTrait;

	private const LOGGED_IN = true;
	private const TEMP = true;
	private const BLOCKED = true;
	private const LOGGED_OUT = false;
	private const NAMED = false;
	private const NOT_BLOCKED = false;
	private const ALL_RIGHTS = '*';

	private const LOCAL_EVENT = true;
	private const FOREIGN_EVENT = false;

	/**
	 * @param OrganizersStore|null $organizersStore
	 * @param PageAuthorLookup|null $pageAuthorLookup
	 * @param IPermissionsLookup|null $permissionsLookup
	 * @return PermissionChecker
	 */
	private function getPermissionChecker(
		OrganizersStore $organizersStore = null,
		PageAuthorLookup $pageAuthorLookup = null,
		IPermissionsLookup $permissionsLookup = null
	): PermissionChecker {
		return new PermissionChecker(
			$organizersStore ?? $this->createMock( OrganizersStore::class ),
			$pageAuthorLookup ?? $this->createMock( PageAuthorLookup::class ),
			$this->createMock( CampaignsCentralUserLookup::class ),
			$permissionsLookup ?? $this->createMock( IPermissionsLookup::class )
		);
	}

	/**
	 * @param bool $isLoggedIn
	 * @param bool $isTemp
	 * @param bool $isBlocked
	 * @param array|string $userRights Array of rights, or self::ALL_RIGHTS to indicate all.
	 * @return ICampaignsAuthority
	 */
	private function makeAuthority(
		bool $isLoggedIn,
		bool $isTemp,
		bool $isBlocked,
		$userRights
	): ICampaignsAuthority {
		if ( $isTemp ) {
			$user = new UserIdentityValue( 42, '*Unregistered1' );
		} elseif ( $isLoggedIn ) {
			$user = new UserIdentityValue( 100, 'Rick Astley' );
		} else {
			$user = new UserIdentityValue( 0, '127.0.0.1' );
		}
		if ( $isBlocked ) {
			$block = $this->createMock( Block::class );
			$block->method( 'isSitewide' )->willReturn( true );
		} else {
			$block = null;
		}
		$authority = $this->mockAuthority(
			$user,
			static fn ( $right ) => $userRights === self::ALL_RIGHTS || in_array( $right, $userRights, true ),
			$block,
			$isTemp
		);
		return new MWAuthorityProxy( $authority );
	}

	private function makePermLookup(
		bool $isNamed,
		$userRights,
		bool $isSitewideBlocked
	): IPermissionsLookup {
		$lookup = $this->createMock( IPermissionsLookup::class );
		$lookup->method( 'userIsNamed' )->willReturn( $isNamed );
		$lookup->method( 'userHasRight' )
			->willReturnCallback( static fn ( $user, $right ) =>
				$userRights === self::ALL_RIGHTS || in_array( $right, $userRights, true )
			);
		$lookup->method( 'userIsSitewideBlocked' )->willReturn( $isSitewideBlocked );
		return $lookup;
	}

	/**
	 * @param bool $expected
	 * @param bool $isLoggedIn
	 * @param bool $isTemp
	 * @param bool $isBlocked
	 * @param array|string $userRights
	 * @covers ::userCanEnableRegistrations
	 * @dataProvider provideCanEnableRegistrations
	 */
	public function testUserCanEnableRegistrations(
		bool $expected,
		bool $isLoggedIn,
		bool $isTemp,
		bool $isBlocked,
		$userRights
	) {
		$performer = $this->makeAuthority( $isLoggedIn, $isTemp, $isBlocked, $userRights );
		$this->assertSame(
			$expected,
			$this->getPermissionChecker()->userCanEnableRegistrations( $performer )
		);
	}

	public static function provideCanEnableRegistrations(): Generator {
		yield 'Authorized' => [
			true,
			self::LOGGED_IN,
			self::NAMED,
			self::NOT_BLOCKED,
			[ 'campaignevents-enable-registration' ],
		];
		yield 'Lacking right' => [
			false,
			self::LOGGED_IN,
			self::NAMED,
			self::NOT_BLOCKED,
			[],
		];
		yield 'Logged out' => [
			false,
			self::LOGGED_OUT,
			self::NAMED,
			self::NOT_BLOCKED,
			self::ALL_RIGHTS,
		];
		yield 'Temp user' => [
			false,
			self::LOGGED_IN,
			self::TEMP,
			self::NOT_BLOCKED,
			self::ALL_RIGHTS,
		];
		yield 'Blocked' => [
			false,
			self::LOGGED_IN,
			self::NAMED,
			self::BLOCKED,
			self::ALL_RIGHTS,
		];
	}

	/**
	 * @param bool $expected
	 * @param IPermissionsLookup $permissionsLookup
	 * @covers ::userCanOrganizeEvents
	 * @dataProvider provideCanOrganizeEvents
	 */
	public function testUserCanOrganizeEvents(
		bool $expected,
		IPermissionsLookup $permissionsLookup
	) {
		$permissionChecker = $this->getPermissionChecker( null, null, $permissionsLookup );
		$this->assertSame( $expected, $permissionChecker->userCanOrganizeEvents( 'Some username' ) );
	}

	public function provideCanOrganizeEvents(): Generator {
		$loggedOutPermLookup = $this->createMock( IPermissionsLookup::class );
		$loggedOutPermLookup->expects( $this->atLeastOnce() )->method( 'userIsNamed' )->willReturn( false );
		yield 'Not named' => [ false, $loggedOutPermLookup ];

		$lacksRightPermLookup = $this->createMock( IPermissionsLookup::class );
		$lacksRightPermLookup->method( 'userIsNamed' )->willReturn( true );
		$lacksRightPermLookup->expects( $this->atLeastOnce() )
			->method( 'userHasRight' )
			->with( $this->anything(), PermissionChecker::ORGANIZE_EVENTS_RIGHT )
			->willReturn( false );
		yield 'Lacking right' => [ false, $lacksRightPermLookup ];

		$blockedPermLookup = $this->createMock( IPermissionsLookup::class );
		$blockedPermLookup->method( 'userIsNamed' )->willReturn( true );
		$blockedPermLookup->method( 'userHasRight' )->willReturn( true );
		$blockedPermLookup->expects( $this->atLeastOnce() )->method( 'userIsSitewideBlocked' )->willReturn( true );
		yield 'Blocked' => [ false, $blockedPermLookup ];

		$authorizedPermLookup = $this->createMock( IPermissionsLookup::class );
		$authorizedPermLookup->method( 'userIsNamed' )->willReturn( true );
		$authorizedPermLookup->method( 'userHasRight' )->willReturn( true );
		$authorizedPermLookup->method( 'userIsSitewideBlocked' )->willReturn( false );
		yield 'Authorized' => [ true, $authorizedPermLookup ];
	}

	/**
	 * @covers ::userCanEnableRegistration
	 * @dataProvider provideCanEnableRegistration
	 */
	public function testUserCanEnableRegistration(
		bool $expected,
		bool $isAuthorized,
		?bool $isPageAuthor
	) {
		if ( $isAuthorized ) {
			$performer = new MWAuthorityProxy(
				$this->mockRegisteredAuthorityWithPermissions( [ 'campaignevents-enable-registration' ] )
			);
		} else {
			$performer = new MWAuthorityProxy( $this->mockRegisteredNullAuthority() );
		}

		$page = $this->createMock( ICampaignsPage::class );

		if ( $isPageAuthor !== null ) {
			$pageAuthorLookup = $this->createMock( PageAuthorLookup::class );
			$pageAuthor = $this->createMock( CentralUser::class );
			$pageAuthor->expects( $this->atLeastOnce() )->method( 'equals' )->willReturn( $isPageAuthor );
			$pageAuthorLookup->expects( $this->atLeastOnce() )
				->method( 'getAuthor' )
				->with( $page )
				->willReturn( $pageAuthor );
		} else {
			$pageAuthorLookup = null;
		}

		$this->assertSame(
			$expected,
			$this->getPermissionChecker( null, $pageAuthorLookup )->userCanEnableRegistration( $performer, $page )
		);
	}

	public static function provideCanEnableRegistration(): Generator {
		yield 'Cannot create registrations' => [
			false,
			false,
			null,
		];

		yield 'Did not create the page' => [
			false,
			true,
			false,
		];

		yield 'Authorized' => [
			true,
			true,
			true,
		];
	}

	/**
	 * @param bool $expected
	 * @param ICampaignsAuthority $performer
	 * @param ExistingEventRegistration $event
	 * @param OrganizersStore|null $organizersStore
	 * @param IPermissionsLookup|null $permissionsLookup
	 * @covers ::userCanEditRegistration
	 * @dataProvider provideGenericEditPermissions
	 */
	public function testUserCanEditRegistration(
		bool $expected,
		ICampaignsAuthority $performer,
		ExistingEventRegistration $event,
		OrganizersStore $organizersStore = null,
		IPermissionsLookup $permissionsLookup = null
	) {
		$checker = $this->getPermissionChecker( $organizersStore, null, $permissionsLookup );
		$this->assertSame(
			$expected,
			$checker->userCanEditRegistration( $performer, $event )
		);
	}

	public function provideGenericEditPermissions(): Generator {
		yield 'Logged out' => [
			false,
			new MWAuthorityProxy( $this->mockAnonUltimateAuthority() ),
			$this->mockExistingEventRegistration( true )
		];
		yield 'Temp user' => [
			false,
			new MWAuthorityProxy( $this->mockTempUltimateAuthority() ),
			$this->mockExistingEventRegistration( true ),
		];
		yield 'Lacks right to enable registrations' => [
			false,
			new MWAuthorityProxy( $this->mockRegisteredNullAuthority() ),
			$this->mockExistingEventRegistration( true )
		];
		yield 'Blocked' => [
			false,
			$this->mockSitewideBlockedRegisteredUltimateAuthority(),
			$this->mockExistingEventRegistration( true )
		];
		yield 'Not an organizer' => [
			false,
			new MWAuthorityProxy( $this->mockRegisteredNullAuthority() ),
			$this->mockExistingEventRegistration( true )
		];
		yield 'Event is not local' => [
			false,
			new MWAuthorityProxy( $this->mockRegisteredUltimateAuthority() ),
			$this->mockExistingEventRegistration( false )
		];

		$authorizedOrgStore = $this->createMock( OrganizersStore::class );
		$authorizedOrgStore->method( 'isEventOrganizer' )->willReturn( true );

		$cannotBeOrganizerPermLookup = $this->createMock( IPermissionsLookup::class );
		$cannotBeOrganizerPermLookup->method( 'userIsNamed' )->willReturn( true );
		$cannotBeOrganizerPermLookup
			->method( 'userHasRight' )
			->with( $this->anything(), 'campaignevents-organize-events' )
			->willReturn( false );
		$cannotBeOrganizerPermLookup->method( 'userIsSitewideBlocked' )->willReturn( false );

		$canBeOrganizerPermLookup = $this->createMock( IPermissionsLookup::class );
		$canBeOrganizerPermLookup->method( 'userIsNamed' )->willReturn( true );
		$canBeOrganizerPermLookup
			->method( 'userHasRight' )
			->with( $this->anything(), 'campaignevents-organize-events' )
			->willReturn( true );
		$canBeOrganizerPermLookup->method( 'userIsSitewideBlocked' )->willReturn( false );
		yield 'Authorized: can enable registrations but not be an organizer' => [
			true,
			new MWAuthorityProxy(
				$this->mockRegisteredAuthorityWithPermissions( [ 'campaignevents-enable-registration' ] )
			),
			$this->mockExistingEventRegistration( true ),
			$authorizedOrgStore,
			$cannotBeOrganizerPermLookup
		];

		yield 'Authorized: can be an organizer but not enable registrations' => [
			true,
			new MWAuthorityProxy(
				$this->mockRegisteredAuthorityWithPermissions( [ 'campaignevents-organize-events' ] )
			),
			$this->mockExistingEventRegistration( true ),
			$authorizedOrgStore,
			$canBeOrganizerPermLookup
		];

		yield 'Authorized: can enable registrations and be an organizer' => [
			true,
			new MWAuthorityProxy(
				$this->mockRegisteredAuthorityWithPermissions( [
					'campaignevents-enable-registration',
					'campaignevents-organize-events'
				] )
			),
			$this->mockExistingEventRegistration( true ),
			$authorizedOrgStore,
			$canBeOrganizerPermLookup
		];
	}

	/**
	 * @covers ::userCanDeleteRegistration
	 * @covers ::userCanDeleteRegistrations
	 * @dataProvider provideCanDeleteRegistration
	 */
	public function testUserCanDeleteRegistration(
		bool $expected,
		bool $isLoggedIn,
		bool $isTemp,
		bool $isBlocked,
		$userRights,
		bool $eventIsLocal,
		?bool $isStoredOrganizer = null,
		IPermissionsLookup $permissionsLookup = null
	) {
		$performer = $this->makeAuthority( $isLoggedIn, $isTemp, $isBlocked, $userRights );
		$event = $this->mockExistingEventRegistration( $eventIsLocal );
		if ( $isStoredOrganizer ) {
			$organizersStore = $this->createMock( OrganizersStore::class );
			$organizersStore->expects( $this->once() )->method( 'isEventOrganizer' )->willReturn( true );
		} else {
			$organizersStore = null;
		}
		$checker = $this->getPermissionChecker( $organizersStore, null, $permissionsLookup );
		$this->assertSame(
			$expected,
			$checker->userCanDeleteRegistration( $performer, $event )
		);
	}

	public static function provideCanDeleteRegistration(): Generator {
		yield 'Logged out' => [
			false,
			self::LOGGED_OUT,
			self::NAMED,
			self::NOT_BLOCKED,
			self::ALL_RIGHTS,
			self::LOCAL_EVENT,
		];
		yield 'Temp user' => [
			false,
			self::LOGGED_IN,
			self::TEMP,
			self::NOT_BLOCKED,
			self::ALL_RIGHTS,
			self::LOCAL_EVENT,
		];
		yield 'Lacks right to enable registrations' => [
			false,
			self::LOGGED_IN,
			self::NAMED,
			self::NOT_BLOCKED,
			[],
			self::LOCAL_EVENT,
		];
		yield 'Blocked' => [
			false,
			self::LOGGED_IN,
			self::NAMED,
			self::BLOCKED,
			self::ALL_RIGHTS,
			self::LOCAL_EVENT,
		];
		yield 'Event is not local' => [
			false,
			self::LOGGED_IN,
			self::NAMED,
			self::NOT_BLOCKED,
			self::ALL_RIGHTS,
			self::FOREIGN_EVENT,
		];
		yield 'Can edit the registration' => [
			true,
			self::LOGGED_IN,
			self::NAMED,
			self::NOT_BLOCKED,
			[
				'campaignevents-enable-registration',
				'campaignevents-organize-events',
			],
			self::LOCAL_EVENT,
			true,
		];
		yield 'Can delete all registrations' => [
			true,
			self::LOGGED_IN,
			self::NAMED,
			self::NOT_BLOCKED,
			[
				'campaignevents-delete-registration',
				'campaignevents-organize-events'
			],
			self::LOCAL_EVENT,
		];
	}

	/**
	 * @covers ::userCanRegisterForEvent
	 * @dataProvider provideCanRegisterForEvents
	 */
	public function testUserCanRegisterForEvents(
		bool $expected,
		bool $isLoggedIn,
		bool $isTemp,
		bool $isBlocked,
		bool $eventIsLocal
	) {
		$performer = $this->makeAuthority( $isLoggedIn, $isTemp, $isBlocked, self::ALL_RIGHTS );
		$event = $this->mockExistingEventRegistration( $eventIsLocal );
		$this->assertSame(
			$expected,
			$this->getPermissionChecker()->userCanRegisterForEvent( $performer, $event )
		);
	}

	public static function provideCanRegisterForEvents(): Generator {
		yield 'Authorized' => [
			true,
			self::LOGGED_IN,
			self::NAMED,
			self::NOT_BLOCKED,
			self::LOCAL_EVENT,
		];
		yield 'Logged out' => [
			false,
			self::LOGGED_OUT,
			self::NAMED,
			self::NOT_BLOCKED,
			self::LOCAL_EVENT,
		];
		yield 'Temp user' => [
			false,
			self::LOGGED_IN,
			self::TEMP,
			self::NOT_BLOCKED,
			self::LOCAL_EVENT,
		];
		yield 'Blocked' => [
			false,
			self::LOGGED_IN,
			self::NAMED,
			self::BLOCKED,
			self::LOCAL_EVENT,
		];
		yield 'Event is not local' => [
			false,
			self::LOGGED_IN,
			self::NAMED,
			self::NOT_BLOCKED,
			self::FOREIGN_EVENT,
		];
	}

	/**
	 * @covers ::userCanCancelRegistration
	 * @dataProvider provideCanCancelRegistration
	 */
	public function testUserCanCancelRegistration(
		bool $expected,
		bool $isLoggedIn,
		bool $isTemp,
		bool $isBlocked
	) {
		$performer = $this->makeAuthority( $isLoggedIn, $isTemp, $isBlocked, self::ALL_RIGHTS );
		$this->assertSame(
			$expected,
			$this->getPermissionChecker()->userCanCancelRegistration( $performer )
		);
	}

	public static function provideCanCancelRegistration(): Generator {
		yield 'Authorized' => [
			true,
			self::LOGGED_IN,
			self::NAMED,
			self::NOT_BLOCKED,
		];
		yield 'Logged out' => [
			false,
			self::LOGGED_OUT,
			self::NAMED,
			self::NOT_BLOCKED,
		];
		yield 'Temp user' => [
			false,
			self::LOGGED_IN,
			self::TEMP,
			self::NOT_BLOCKED,
		];
		yield 'Blocked' => [
			true,
			self::LOGGED_IN,
			self::NAMED,
			self::BLOCKED,
		];
	}

	private function mockSitewideBlockedRegisteredUltimateAuthority(): ICampaignsAuthority {
		$block = $this->createMock( Block::class );
		$block->expects( $this->atLeastOnce() )->method( 'isSitewide' )->willReturn( true );
		$authority = $this->mockAuthority(
			new UserIdentityValue( 42, 'Test' ),
			static function () {
				return true;
			},
			$block
		);
		/** @var Authority&MockObject $authority */
		$authority->method( 'isNamed' )->willReturn( true );
		return new MWAuthorityProxy( $authority );
	}

	/**
	 * @covers ::userCanRemoveParticipants
	 * @dataProvider provideGenericEditPermissions
	 */
	public function testUserCanRemoveParticipants(
		bool $expected,
		ICampaignsAuthority $performer,
		ExistingEventRegistration $event,
		OrganizersStore $organizersStore = null,
		IPermissionsLookup $permissionsLookup = null
	) {
		$checker = $this->getPermissionChecker( $organizersStore, null, $permissionsLookup );
		$this->assertSame(
			$expected,
			$checker->userCanRemoveParticipants( $performer, $event )
		);
	}

	/**
	 * @param bool $expected
	 * @param ICampaignsAuthority $performer
	 * @param ExistingEventRegistration $event
	 * @param OrganizersStore|null $organizersStore
	 * @param IPermissionsLookup|null $permissionsLookup
	 * @covers ::userCanViewPrivateParticipants
	 * @dataProvider provideGenericEditPermissions
	 */
	public function testUserCanViewPrivateParticipants(
		bool $expected,
		ICampaignsAuthority $performer,
		ExistingEventRegistration $event,
		OrganizersStore $organizersStore = null,
		IPermissionsLookup $permissionsLookup = null
	) {
		$checker = $this->getPermissionChecker( $organizersStore, null, $permissionsLookup );
		$this->assertSame(
			$expected,
			$checker->userCanViewPrivateParticipants( $performer, $event )
		);
	}

	/**
	 * @covers ::userCanViewNonPIIParticipantsData
	 * @dataProvider provideGenericEditPermissions
	 */
	public function testUserCanViewNonPIIParticipantsData(
		bool $expected,
		ICampaignsAuthority $performer,
		ExistingEventRegistration $event,
		OrganizersStore $organizersStore = null,
		IPermissionsLookup $permissionsLookup = null
	) {
		$checker = $this->getPermissionChecker( $organizersStore, null, $permissionsLookup );
		$this->assertSame(
			$expected,
			$checker->userCanViewNonPIIParticipantsData( $performer, $event )
		);
	}

	/**
	 * @covers ::userCanEmailParticipants
	 * @dataProvider provideUserCanEmailParticipants
	 */
	public function testUserCanEmailParticipants(
		bool $expected,
		bool $isLoggedIn,
		bool $isTemp,
		bool $isBlocked,
		$userRights,
		bool $eventIsLocal,
		?bool $isStoredOrganizer = null
	) {
		$performer = $this->makeAuthority( $isLoggedIn, $isTemp, $isBlocked, $userRights );
		$event = $this->mockExistingEventRegistration( $eventIsLocal );
		if ( $isStoredOrganizer ) {
			$organizersStore = $this->createMock( OrganizersStore::class );
			$organizersStore->expects( $this->once() )->method( 'isEventOrganizer' )->willReturn( true );
		} else {
			$organizersStore = null;
		}
		$checker = $this->getPermissionChecker( $organizersStore );
		$this->assertSame(
			$expected,
			$checker->userCanEmailParticipants( $performer, $event )
		);
	}

	public static function provideUserCanEmailParticipants(): Generator {
		yield 'Logged out' => [
			false,
			self::LOGGED_OUT,
			self::NAMED,
			self::NOT_BLOCKED,
			self::ALL_RIGHTS,
			self::LOCAL_EVENT,
		];
		yield 'Temp user' => [
			false,
			self::LOGGED_IN,
			self::TEMP,
			self::NOT_BLOCKED,
			self::ALL_RIGHTS,
			self::LOCAL_EVENT,
		];
		yield 'Lacks right to enable registrations' => [
			false,
			self::LOGGED_IN,
			self::NAMED,
			self::NOT_BLOCKED,
			[],
			self::LOCAL_EVENT,
		];
		yield 'Blocked' => [
			false,
			self::LOGGED_IN,
			self::NAMED,
			self::BLOCKED,
			self::ALL_RIGHTS,
			self::LOCAL_EVENT,
		];
		yield 'Event is not local' => [
			false,
			self::LOGGED_IN,
			self::NAMED,
			self::NOT_BLOCKED,
			self::ALL_RIGHTS,
			self::FOREIGN_EVENT,
		];
		yield 'Is organizer but does not have email permissions' => [
			false,
			self::LOGGED_IN,
			self::NAMED,
			self::NOT_BLOCKED,
			[
				PermissionChecker::ENABLE_REGISTRATIONS_RIGHT
			],
			self::LOCAL_EVENT,
			true,
		];

		yield 'Authorized' => [
			true,
			self::LOGGED_IN,
			self::NAMED,
			self::NOT_BLOCKED,
			[
				PermissionChecker::ENABLE_REGISTRATIONS_RIGHT,
				PermissionChecker::SEND_EVENTS_EMAIL_RIGHT
			],
			self::LOCAL_EVENT,
			true,
		];
	}

	/**
	 * @covers ::userCanUseInvitationLists
	 * @dataProvider provideCanUseInvitationLists
	 */
	public function testUserCanUseInvitationLists(
		bool $expected,
		bool $isLoggedIn,
		bool $isTemp,
		bool $isBlocked,
		$userRights
	) {
		$performer = $this->makeAuthority( $isLoggedIn, $isTemp, $isBlocked, $userRights );
		$permLookup = $this->makePermLookup( $isLoggedIn && !$isTemp, $userRights, $isBlocked );

		$permChecker = $this->getPermissionChecker( null, null, $permLookup );
		$this->assertSame(
			$expected,
			$permChecker->userCanUseInvitationLists( $performer )
		);
	}

	public static function provideCanUseInvitationLists(): Generator {
		yield 'Logged out' => [
			false,
			self::LOGGED_OUT,
			self::NAMED,
			self::NOT_BLOCKED,
			self::ALL_RIGHTS,
		];
		yield 'Temp user' => [
			false,
			self::LOGGED_IN,
			self::TEMP,
			self::NOT_BLOCKED,
			self::ALL_RIGHTS,
		];
		yield 'Blocked' => [
			false,
			self::LOGGED_IN,
			self::NAMED,
			self::BLOCKED,
			self::ALL_RIGHTS,
		];
		yield 'Can be organizer but not enable registration' => [
			true,
			self::LOGGED_IN,
			self::NAMED,
			self::NOT_BLOCKED,
			[
				PermissionChecker::ORGANIZE_EVENTS_RIGHT
			],
		];
		yield 'Can enable registration but not be organizer' => [
			true,
			self::LOGGED_IN,
			self::NAMED,
			self::NOT_BLOCKED,
			[
				PermissionChecker::ENABLE_REGISTRATIONS_RIGHT
			],
		];
		yield 'Can be organizer and enable registration' => [
			true,
			self::LOGGED_IN,
			self::NAMED,
			self::NOT_BLOCKED,
			[
				PermissionChecker::ORGANIZE_EVENTS_RIGHT,
				PermissionChecker::ENABLE_REGISTRATIONS_RIGHT
			],
		];
	}

	/**
	 * @param bool $isLocal
	 * @return ExistingEventRegistration
	 */
	private function mockExistingEventRegistration( bool $isLocal ): ExistingEventRegistration {
		$mock = $this->createMock( ExistingEventRegistration::class );
		$mock->method( 'isOnLocalWiki' )->willReturn( $isLocal );
		$mock->method( 'getID' )->willReturn( 42 );
		return $mock;
	}
}
