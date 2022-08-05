<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Permissions;

use Generator;
use MediaWiki\Block\Block;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsAuthority;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsPage;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWAuthorityProxy;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageAuthorLookup;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
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
	 * @return PermissionChecker
	 */
	private function getPermissionChecker(
		OrganizersStore $organizersStore = null,
		PageAuthorLookup $pageAuthorLookup = null
	): PermissionChecker {
		return new PermissionChecker(
			$organizersStore ?? $this->createMock( OrganizersStore::class ),
			$pageAuthorLookup ?? $this->createMock( PageAuthorLookup::class ),
			$this->createMock( CampaignsCentralUserLookup::class )
		);
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
		yield 'Blocked' => [ false, $this->mockSitewideBlockedRegisteredUltimateAuthority() ];
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
			->willReturn( $this->createMock( ICampaignsUser::class ) );
		yield 'Did not create the page' => [
			false,
			$authorizedUser,
			$testPage,
			$notCreatedAuthorLookup
		];

		$pageAuthor = $this->createMock( ICampaignsUser::class );
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
	 * @covers ::userCanEditRegistration
	 * @dataProvider provideCanEditRegistration
	 */
	public function testUserCanEditRegistration(
		bool $expected,
		ICampaignsAuthority $performer,
		OrganizersStore $organizersStore = null
	) {
		$checker = $this->getPermissionChecker( $organizersStore );
		$this->assertSame(
			$expected,
			$checker->userCanEditRegistration( $performer, 42 )
		);
	}

	public function provideCanEditRegistration(): Generator {
		yield 'Logged out' => [
			false,
			new MWAuthorityProxy( $this->mockAnonUltimateAuthority() )
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
		$authorizedOrgStore->expects( $this->once() )->method( 'isEventOrganizer' )->willReturn( true );
		yield 'Authorized' => [
			true,
			new MWAuthorityProxy(
				$this->mockRegisteredAuthorityWithPermissions( [ 'campaignevents-enable-registration' ] )
			),
			$authorizedOrgStore
		];
	}

	/**
	 * @param bool $expected
	 * @param ICampaignsAuthority $performer
	 * @param OrganizersStore|null $organizersStore
	 * @covers ::userCanDeleteRegistration
	 * Reuses the data provider for convenience.
	 * @dataProvider provideCanDeleteRegistration
	 */
	public function testUserCanDeleteRegistration(
		bool $expected,
		ICampaignsAuthority $performer,
		OrganizersStore $organizersStore = null
	) {
		$checker = $this->getPermissionChecker( $organizersStore );
		$this->assertSame(
			$expected,
			$checker->userCanDeleteRegistration( $performer, 42 )
		);
	}

	public function provideCanDeleteRegistration(): Generator {
		yield 'Logged out but allowed' => [
			true,
			new MWAuthorityProxy( $this->mockAnonUltimateAuthority() )
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
		yield 'Can edit the registration' => [
			true,
			new MWAuthorityProxy(
				$this->mockRegisteredAuthorityWithPermissions( [ 'campaignevents-enable-registration' ] )
			),
			$authorizedOrgStore
		];
		yield 'Can delete all registrations' => [
			true,
			new MWAuthorityProxy(
				$this->mockRegisteredAuthorityWithPermissions( [ 'campaignevents-delete-registration' ] )
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
}
