<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Permissions;

use Generator;
use MediaWiki\Block\Block;
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
use MediaWiki\Permissions\UltimateAuthority;
use MediaWiki\Tests\Unit\Permissions\MockAuthorityTrait;
use MediaWiki\User\UserIdentityValue;
use MediaWikiUnitTestCase;

/**
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker
 * @covers ::__construct
 */
class PermissionCheckerTest extends MediaWikiUnitTestCase {
	use MockAuthorityTrait;

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
	 * @todo This should probably be in core's MockAuthorityTrait
	 * @return Authority
	 */
	private function mockTempUltimateAuthority(): Authority {
		return new UltimateAuthority( new UserIdentityValue( 42, '*Unregistered1' ), true );
	}

	/**
	 * @param bool $expected
	 * @param ICampaignsAuthority $performer
	 * @covers ::userCanEnableRegistrations
	 * @dataProvider provideCanEnableRegistrations
	 */
	public function testUserCanEnableRegistrations(
		bool $expected,
		ICampaignsAuthority $performer
	) {
		$this->assertSame(
			$expected,
			$this->getPermissionChecker()->userCanEnableRegistrations( $performer )
		);
	}

	public function provideCanEnableRegistrations(): Generator {
		yield 'Authorized' => [
			true,
			new MWAuthorityProxy(
				$this->mockRegisteredAuthorityWithPermissions( [ 'campaignevents-enable-registration' ] )
			)
		];
		yield 'Lacking right' => [
			false,
			new MWAuthorityProxy( $this->mockRegisteredNullAuthority() )
		];
		yield 'Logged out' => [
			false,
			new MWAuthorityProxy( $this->mockAnonUltimateAuthority() )
		];
		yield 'Temp user' => [
			false,
			new MWAuthorityProxy( $this->mockTempUltimateAuthority() )
		];
		yield 'Blocked' => [ false, $this->mockSitewideBlockedRegisteredUltimateAuthority() ];
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
	 * @param bool $expected
	 * @param ICampaignsAuthority $performer
	 * @param ICampaignsPage $page
	 * @param PageAuthorLookup|null $pageAuthorLookup
	 * @covers ::userCanEnableRegistration
	 * @dataProvider provideCanEnableRegistration
	 */
	public function testUserCanEnableRegistration(
		bool $expected,
		ICampaignsAuthority $performer,
		ICampaignsPage $page,
		PageAuthorLookup $pageAuthorLookup = null
	) {
		$this->assertSame(
			$expected,
			$this->getPermissionChecker( null, $pageAuthorLookup )->userCanEnableRegistration( $performer, $page )
		);
	}

	public function provideCanEnableRegistration(): Generator {
		$authorizedUser = new MWAuthorityProxy(
			$this->mockRegisteredAuthorityWithPermissions( [ 'campaignevents-enable-registration' ] )
		);
		$unauthorizedUser = new MWAuthorityProxy(
			$this->mockRegisteredNullAuthority()
		);

		yield 'Cannot create registrations' => [
			false,
			$unauthorizedUser,
			$this->createMock( ICampaignsPage::class )
		];

		$testPage = $this->createMock( ICampaignsPage::class );

		$notCreatedAuthorLookup = $this->createMock( PageAuthorLookup::class );
		$notCreatedAuthorLookup->expects( $this->atLeastOnce() )
			->method( 'getAuthor' )
			->with( $testPage )
			->willReturn( $this->createMock( CentralUser::class ) );
		yield 'Did not create the page' => [
			false,
			$authorizedUser,
			$testPage,
			$notCreatedAuthorLookup
		];

		$pageAuthor = $this->createMock( CentralUser::class );
		$pageAuthor->expects( $this->atLeastOnce() )->method( 'equals' )->willReturn( true );
		$createdAuthorLookup = $this->createMock( PageAuthorLookup::class );
		$createdAuthorLookup->expects( $this->atLeastOnce() )
			->method( 'getAuthor' )
			->with( $testPage )
			->willReturn( $pageAuthor );
		yield 'Authorized' => [
			true,
			$authorizedUser,
			$testPage,
			$createdAuthorLookup
		];
	}

	/**
	 * @param bool $expected
	 * @param ICampaignsAuthority $performer
	 * @param OrganizersStore|null $organizersStore
	 * @param IPermissionsLookup|null $permissionsLookup
	 * @covers ::userCanEditRegistration
	 * @dataProvider provideGenericEditPermissions
	 */
	public function testUserCanEditRegistration(
		bool $expected,
		ICampaignsAuthority $performer,
		OrganizersStore $organizersStore = null,
		IPermissionsLookup $permissionsLookup = null
	) {
		$checker = $this->getPermissionChecker( $organizersStore, null, $permissionsLookup );
		$this->assertSame(
			$expected,
			$checker->userCanEditRegistration( $performer, 42 )
		);
	}

	public function provideGenericEditPermissions(): Generator {
		yield 'Logged out' => [
			false,
			new MWAuthorityProxy( $this->mockAnonUltimateAuthority() )
		];
		yield 'Temp user' => [
			false,
			new MWAuthorityProxy( $this->mockTempUltimateAuthority() )
		];
		yield 'Lacks right to enable registrations' => [
			false,
			new MWAuthorityProxy( $this->mockRegisteredNullAuthority() )
		];
		yield 'Blocked' => [
			false,
			$this->mockSitewideBlockedRegisteredUltimateAuthority(),
		];
		yield 'Not an organizer' => [
			false,
			new MWAuthorityProxy( $this->mockRegisteredNullAuthority() )
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
			$authorizedOrgStore,
			$cannotBeOrganizerPermLookup
		];

		yield 'Authorized: can be an organizer but not enable registrations' => [
			true,
			new MWAuthorityProxy(
				$this->mockRegisteredAuthorityWithPermissions( [ 'campaignevents-organize-events' ] )
			),
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
			$authorizedOrgStore,
			$canBeOrganizerPermLookup
		];
	}

	/**
	 * @param bool $expected
	 * @param ICampaignsAuthority $performer
	 * @param OrganizersStore|null $organizersStore
	 * @param IPermissionsLookup|null $permissionsLookup
	 * @covers ::userCanDeleteRegistration
	 * @covers ::userCanDeleteRegistrations
	 * Reuses the data provider for convenience.
	 * @dataProvider provideCanDeleteRegistration
	 */
	public function testUserCanDeleteRegistration(
		bool $expected,
		ICampaignsAuthority $performer,
		OrganizersStore $organizersStore = null,
		IPermissionsLookup $permissionsLookup = null
	) {
		$checker = $this->getPermissionChecker( $organizersStore, null, $permissionsLookup );
		$this->assertSame(
			$expected,
			$checker->userCanDeleteRegistration( $performer, 42 )
		);
	}

	public function provideCanDeleteRegistration(): Generator {
		yield 'Logged out' => [
			false,
			new MWAuthorityProxy( $this->mockAnonUltimateAuthority() )
		];
		yield 'Temp user' => [
			false,
			new MWAuthorityProxy( $this->mockTempUltimateAuthority() )
		];
		yield 'Lacks right to enable registrations' => [
			false,
			new MWAuthorityProxy( $this->mockRegisteredNullAuthority() )
		];
		yield 'Blocked' => [
			false,
			$this->mockSitewideBlockedRegisteredUltimateAuthority()
		];
		yield 'Not an organizer' => [
			false,
			new MWAuthorityProxy( $this->mockRegisteredNullAuthority() )
		];
		$authorizedOrgStore = $this->createMock( OrganizersStore::class );
		$authorizedOrgStore->expects( $this->once() )->method( 'isEventOrganizer' )->willReturn( true );
		$permissionsLookup = $this->createMock( IPermissionsLookup::class );
		$permissionsLookup->method( 'userHasRight' )->willReturn( true );
		$permissionsLookup->method( 'userIsSitewideBlocked' )->willReturn( false );
		$permissionsLookup->method( 'userIsNamed' )->willReturn( true );
		yield 'Can edit the registration' => [
			true,
			new MWAuthorityProxy(
				$this->mockRegisteredAuthorityWithPermissions( [
					'campaignevents-enable-registration',
					'campaignevents-organize-events',
				] )
			),
			$authorizedOrgStore,
			$permissionsLookup
		];
		yield 'Can delete all registrations' => [
			true,
			new MWAuthorityProxy(
				$this->mockRegisteredAuthorityWithPermissions( [
					'campaignevents-delete-registration',
					'campaignevents-organize-events'
				] )
			)
		];
	}

	/**
	 * @param bool $expected
	 * @param ICampaignsAuthority $performer
	 * @covers ::userCanRegisterForEvents
	 * @dataProvider provideCanRegisterForEvents
	 */
	public function testUserCanRegisterForEvents(
		bool $expected,
		ICampaignsAuthority $performer
	) {
		$this->assertSame(
			$expected,
			$this->getPermissionChecker()->userCanRegisterForEvents( $performer )
		);
	}

	public function provideCanRegisterForEvents(): Generator {
		yield 'Authorized' => [
			true,
			new MWAuthorityProxy( $this->mockRegisteredUltimateAuthority() )
		];
		yield 'Logged out' => [
			false,
			new MWAuthorityProxy( $this->mockAnonUltimateAuthority() )
		];
		yield 'Temp user' => [
			false,
			new MWAuthorityProxy( $this->mockTempUltimateAuthority() )
		];
		yield 'Blocked' => [ false, $this->mockSitewideBlockedRegisteredUltimateAuthority() ];
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
		return new MWAuthorityProxy( $authority );
	}

	/**
	 * @param bool $expected
	 * @param ICampaignsAuthority $performer
	 * @param OrganizersStore|null $organizersStore
	 * @param IPermissionsLookup|null $permissionsLookup
	 * @covers ::userCanViewPrivateParticipants
	 * @dataProvider provideGenericEditPermissions
	 */
	public function testUserCanViewPrivateParticipants(
		bool $expected,
		ICampaignsAuthority $performer,
		OrganizersStore $organizersStore = null,
		IPermissionsLookup $permissionsLookup = null
	) {
		$checker = $this->getPermissionChecker( $organizersStore, null, $permissionsLookup );
		$this->assertSame(
			$expected,
			$checker->userCanViewPrivateParticipants( $performer, 42 )
		);
	}
}
