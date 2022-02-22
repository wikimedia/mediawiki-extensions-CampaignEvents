<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Permissions;

use Generator;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsPage;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWUserProxy;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Permissions\SimpleAuthority;
use MediaWiki\User\UserIdentityValue;
use MediaWikiUnitTestCase;

/**
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker
 */
class PermissionCheckerTest extends MediaWikiUnitTestCase {
	private function getPermissionChecker(): PermissionChecker {
		return new PermissionChecker();
	}

	/**
	 * @param bool $expected
	 * @param ICampaignsUser $user
	 * @covers ::userCanCreateRegistrations
	 * @dataProvider provideUsers
	 */
	public function testUserCanCreateRegistrations( bool $expected, ICampaignsUser $user ) {
		$this->assertSame( $expected, $this->getPermissionChecker()->userCanCreateRegistrations( $user ) );
	}

	public function provideUsers(): Generator {
		$identity = new UserIdentityValue( 42, 'Name' );
		yield 'Authorized' => [
			true,
			new MWUserProxy( $identity, new SimpleAuthority( $identity, [ 'campaignevents-create-registration' ] ) )
		];
		yield 'Not authorized' => [ false, new MWUserProxy( $identity, new SimpleAuthority( $identity, [] ) ) ];
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
