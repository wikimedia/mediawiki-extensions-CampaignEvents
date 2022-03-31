<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Permissions;

use Generator;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsPage;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWUserProxy;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserBlockChecker;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Permissions\SimpleAuthority;
use MediaWiki\Tests\Unit\Permissions\MockAuthorityTrait;
use MediaWiki\User\UserIdentityValue;
use MediaWikiUnitTestCase;

/**
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker
 */
class PermissionCheckerTest extends MediaWikiUnitTestCase {
	use MockAuthorityTrait;

	/**
	 * @param UserBlockChecker|null $blockChecker
	 * @return PermissionChecker
	 */
	private function getPermissionChecker( UserBlockChecker $blockChecker = null ): PermissionChecker {
		return new PermissionChecker( $blockChecker ?? $this->createMock( UserBlockChecker::class ) );
	}

	/**
	 * @param bool $expected
	 * @param ICampaignsUser $user
	 * @param UserBlockChecker|null $blockChecker
	 * @covers ::userCanCreateRegistrations
	 * @dataProvider provideUsers
	 */
	public function testUserCanCreateRegistrations(
		bool $expected,
		ICampaignsUser $user,
		UserBlockChecker $blockChecker = null
	) {
		$this->assertSame(
			$expected,
			$this->getPermissionChecker( $blockChecker )->userCanCreateRegistrations( $user )
		);
	}

	public function provideUsers(): Generator {
		$identity = new UserIdentityValue( 42, 'Name' );
		yield 'Authorized' => [
			true,
			new MWUserProxy( $identity, new SimpleAuthority( $identity, [ 'campaignevents-create-registration' ] ) )
		];
		yield 'Lacking right' => [ false, new MWUserProxy( $identity, new SimpleAuthority( $identity, [] ) ) ];
		yield 'Logged out' => [
			false,
			new MWUserProxy( new UserIdentityValue( 0, '1.1.1.1' ), $this->mockAnonUltimateAuthority() )
		];
		$blockedUser = new MWUserProxy( $identity, $this->mockRegisteredUltimateAuthority() );
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
	 * @covers ::userCanCreateRegistration
	 * @dataProvider provideUsersAndPages
	 */
	public function testUserCanCreateRegistration( bool $expected, ICampaignsUser $user, ICampaignsPage $page ) {
		$this->assertSame(
			$expected,
			$this->getPermissionChecker()->userCanCreateRegistration( $user, $page )
		);
	}

	public function provideUsersAndPages(): Generator {
		$identity = new UserIdentityValue( 42, 'Name' );
		yield 'Authorized' => [
			true,
			new MWUserProxy( $identity, new SimpleAuthority( $identity, [ 'campaignevents-create-registration' ] ) ),
			$this->createMock( ICampaignsPage::class )
		];
		yield 'Not authorized' => [
			false,
			new MWUserProxy( $identity, new SimpleAuthority( $identity, [] ) ),
			$this->createMock( ICampaignsPage::class )
		];
	}
}
