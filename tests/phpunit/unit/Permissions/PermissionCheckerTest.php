<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Permissions;

use Generator;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsPage;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWUserProxy;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageAuthorLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserBlockChecker;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Permissions\SimpleAuthority;
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
	 * @param UserBlockChecker|null $blockChecker
	 * @param OrganizersStore|null $organizersStore
	 * @param PageAuthorLookup|null $pageAuthorLookup
	 * @return PermissionChecker
	 */
	private function getPermissionChecker(
		UserBlockChecker $blockChecker = null,
		OrganizersStore $organizersStore = null,
		PageAuthorLookup $pageAuthorLookup = null
	): PermissionChecker {
		return new PermissionChecker(
			$blockChecker ?? $this->createMock( UserBlockChecker::class ),
			$organizersStore ?? $this->createMock( OrganizersStore::class ),
			$pageAuthorLookup ?? $this->createMock( PageAuthorLookup::class )
		);
	}

	/**
	 * @param bool $expected
	 * @param ICampaignsUser $user
	 * @param UserBlockChecker|null $blockChecker
	 * @covers ::userCanEnableRegistrations
	 * @dataProvider provideCanEnableRegistrations
	 */
	public function testUserCanEnableRegistrations(
		bool $expected,
		ICampaignsUser $user,
		UserBlockChecker $blockChecker = null
	) {
		$this->assertSame(
			$expected,
			$this->getPermissionChecker( $blockChecker )->userCanEnableRegistrations( $user )
		);
	}

	public function provideCanEnableRegistrations(): Generator {
		$registeredUser = new UserIdentityValue( 42, 'Name' );
		yield 'Authorized' => [
			true,
			new MWUserProxy(
				$registeredUser,
				new SimpleAuthority( $registeredUser, [ 'campaignevents-enable-registration' ] )
			)
		];
		yield 'Lacking right' => [
			false,
			new MWUserProxy( $registeredUser, new SimpleAuthority( $registeredUser, [] ) )
		];
		yield 'Logged out' => [
			false,
			new MWUserProxy( new UserIdentityValue( 0, '1.1.1.1' ), $this->mockAnonUltimateAuthority() )
		];
		$blockedUser = new MWUserProxy( $registeredUser, $this->mockRegisteredUltimateAuthority() );
		$blockChecker = $this->createMock( UserBlockChecker::class );
		$blockChecker->expects( $this->atLeastOnce() )
			->method( 'isSitewideBlocked' )
			->with( $blockedUser )
			->willReturn( true );
		yield 'Blocked' => [ false, $blockedUser, $blockChecker ];
	}

	/**
	 * @param bool $expected
	 * @param ICampaignsUser $user
	 * @param ICampaignsPage $page
	 * @param PageAuthorLookup|null $pageAuthorLookup
	 * @covers ::userCanEnableRegistration
	 * @dataProvider provideCanEnableRegistration
	 */
	public function testUserCanEnableRegistration(
		bool $expected,
		ICampaignsUser $user,
		ICampaignsPage $page,
		PageAuthorLookup $pageAuthorLookup = null
	) {
		$this->assertSame(
			$expected,
			$this->getPermissionChecker( null, null, $pageAuthorLookup )->userCanEnableRegistration( $user, $page )
		);
	}

	public function provideCanEnableRegistration(): Generator {
		$registeredUserIdentity = new UserIdentityValue( 42, 'Name' );
		$authorizedUser = new MWUserProxy(
			$registeredUserIdentity,
			new SimpleAuthority( $registeredUserIdentity, [ 'campaignevents-enable-registration' ] )
		);
		$unauthorizedUser = new MWUserProxy(
			$registeredUserIdentity,
			new SimpleAuthority( $registeredUserIdentity, [] )
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

		$createdAuthorLookup = $this->createMock( PageAuthorLookup::class );
		$createdAuthorLookup->expects( $this->atLeastOnce() )
			->method( 'getAuthor' )
			->with( $testPage )
			->willReturn( $authorizedUser );
		yield 'Authorized' => [
			true,
			$authorizedUser,
			$testPage,
			$createdAuthorLookup
		];
	}

	/**
	 * @param bool $expected
	 * @param ICampaignsUser $user
	 * @param UserBlockChecker|null $blockChecker
	 * @param OrganizersStore|null $organizersStore
	 * @covers ::userCanEditRegistration
	 * @dataProvider provideCanEditRegistration
	 */
	public function testUserCanEditRegistration(
		bool $expected,
		ICampaignsUser $user,
		UserBlockChecker $blockChecker = null,
		OrganizersStore $organizersStore = null
	) {
		$checker = $this->getPermissionChecker( $blockChecker, $organizersStore );
		$this->assertSame(
			$expected,
			$checker->userCanEditRegistration( $user, 42 )
		);
	}

	public function provideCanEditRegistration(): Generator {
		$registeredUser = new UserIdentityValue( 42, 'Name' );
		yield 'Logged out' => [
			false,
			new MWUserProxy( new UserIdentityValue( 0, '1.1.1.1' ), $this->mockAnonUltimateAuthority() )
		];
		yield 'Lacks right to enable registrations' => [
			false,
			new MWUserProxy( $registeredUser, new SimpleAuthority( $registeredUser, [] ) )
		];
		$blockedUser = new MWUserProxy( $registeredUser, $this->mockRegisteredUltimateAuthority() );
		$blockChecker = $this->createMock( UserBlockChecker::class );
		$blockChecker->expects( $this->atLeastOnce() )
			->method( 'isSitewideBlocked' )
			->with( $blockedUser )
			->willReturn( true );
		yield 'Blocked' => [
			false,
			$blockedUser,
			$blockChecker,
		];
		yield 'Not an organizer' => [
			false,
			new MWUserProxy( $registeredUser, new SimpleAuthority( $registeredUser, [] ) )
		];
		$authorizedOrgStore = $this->createMock( OrganizersStore::class );
		$authorizedOrgStore->expects( $this->once() )->method( 'isEventOrganizer' )->willReturn( true );
		yield 'Authorized' => [
			true,
			new MWUserProxy(
				$registeredUser,
				new SimpleAuthority( $registeredUser, [ 'campaignevents-enable-registration' ] )
			),
			null,
			$authorizedOrgStore
		];
	}

	/**
	 * @param bool $expected
	 * @param ICampaignsUser $user
	 * @param UserBlockChecker|null $blockChecker
	 * @param OrganizersStore|null $organizersStore
	 * @covers ::userCanDeleteRegistration
	 * Reuses the data provider for convenience.
	 * @dataProvider provideCanDeleteRegistration
	 */
	public function testUserCanDeleteRegistration(
		bool $expected,
		ICampaignsUser $user,
		UserBlockChecker $blockChecker = null,
		OrganizersStore $organizersStore = null
	) {
		$checker = $this->getPermissionChecker( $blockChecker, $organizersStore );
		$this->assertSame(
			$expected,
			$checker->userCanDeleteRegistration( $user, 42 )
		);
	}

	public function provideCanDeleteRegistration(): Generator {
		$registeredUser = new UserIdentityValue( 42, 'Name' );
		yield 'Logged out but allowed' => [
			true,
			new MWUserProxy( new UserIdentityValue( 0, '1.1.1.1' ), $this->mockAnonUltimateAuthority() )
		];
		yield 'Lacks right to enable registrations' => [
			false,
			new MWUserProxy( $registeredUser, new SimpleAuthority( $registeredUser, [] ) )
		];
		$blockedUser = new MWUserProxy( $registeredUser, $this->mockRegisteredUltimateAuthority() );
		$blockChecker = $this->createMock( UserBlockChecker::class );
		$blockChecker->expects( $this->atLeastOnce() )
			->method( 'isSitewideBlocked' )
			->with( $blockedUser )
			->willReturn( true );
		yield 'Blocked' => [
			false,
			$blockedUser,
			$blockChecker,
		];
		yield 'Not an organizer' => [
			false,
			new MWUserProxy( $registeredUser, new SimpleAuthority( $registeredUser, [] ) )
		];
		$authorizedOrgStore = $this->createMock( OrganizersStore::class );
		$authorizedOrgStore->expects( $this->once() )->method( 'isEventOrganizer' )->willReturn( true );
		yield 'Can edit the registration' => [
			true,
			new MWUserProxy(
				$registeredUser,
				new SimpleAuthority( $registeredUser, [ 'campaignevents-enable-registration' ] )
			),
			null,
			$authorizedOrgStore
		];
		yield 'Can delete all registrations' => [
			true,
			new MWUserProxy(
				$registeredUser,
				new SimpleAuthority( $registeredUser, [ 'campaignevents-delete-registration' ] )
			)
		];
	}

	/**
	 * @param bool $expected
	 * @param ICampaignsUser $user
	 * @param UserBlockChecker|null $blockChecker
	 * @covers ::userCanRegisterForEvents
	 * @dataProvider provideCanRegisterForEvents
	 */
	public function testUserCanRegisterForEvents(
		bool $expected,
		ICampaignsUser $user,
		UserBlockChecker $blockChecker = null
	) {
		$this->assertSame(
			$expected,
			$this->getPermissionChecker( $blockChecker )->userCanRegisterForEvents( $user )
		);
	}

	public function provideCanRegisterForEvents(): Generator {
		$registeredUser = new UserIdentityValue( 42, 'Name' );
		yield 'Authorized' => [
			true,
			new MWUserProxy( $registeredUser, $this->mockRegisteredUltimateAuthority() )
		];
		yield 'Logged out' => [
			false,
			new MWUserProxy( new UserIdentityValue( 0, '1.1.1.1' ), $this->mockAnonUltimateAuthority() )
		];
		$blockedUser = new MWUserProxy( $registeredUser, $this->mockRegisteredUltimateAuthority() );
		$blockChecker = $this->createMock( UserBlockChecker::class );
		$blockChecker->expects( $this->atLeastOnce() )
			->method( 'isSitewideBlocked' )
			->with( $blockedUser )
			->willReturn( true );
		yield 'Blocked' => [ false, $blockedUser, $blockChecker ];
	}
}
