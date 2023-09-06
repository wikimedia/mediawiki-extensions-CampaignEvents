<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\MWEntity;

use Generator;
use InvalidArgumentException;
use MediaWiki\Block\AbstractBlock;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWPermissionsLookup;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserNameUtils;
use MediaWikiUnitTestCase;
use User;

/**
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\MWEntity\MWPermissionsLookup
 * @covers ::__construct
 * @covers ::getUser
 */
class MWPermissionsLookupTest extends MediaWikiUnitTestCase {
	private function getLookup( UserFactory $userFactory ): MWPermissionsLookup {
		return new MWPermissionsLookup(
			$userFactory,
			$this->createMock( UserNameUtils::class )
		);
	}

	private function getInvalidUsernameUserFactory(): UserFactory {
		$factory = $this->createMock( UserFactory::class );
		$factory->method( 'newFromName' )->willReturn( null );
		return $factory;
	}

	/**
	 * @covers ::userHasRight
	 * @dataProvider provideUserHasRight
	 */
	public function testUserHasRight( UserFactory $userFactory, string $right, bool $expected ) {
		$this->assertSame( $expected, $this->getLookup( $userFactory )->userHasRight( 'Foo', $right ) );
	}

	public function provideUserHasRight(): Generator {
		$allowedRight = 'some-right-1';
		$notAllowedRight = 'some-right-2';
		$user = $this->createMock( User::class );
		$user->method( 'isAllowed' )
			->willReturnMap( [
				[ $allowedRight, null, true ],
				[ $notAllowedRight, null, false ]
			] );
		$userFactory = $this->createMock( UserFactory::class );
		$userFactory->method( 'newFromName' )->willReturn( $user );

		yield 'Has right' => [ $userFactory, $allowedRight, true ];
		yield 'Does not have right' => [ $userFactory, $notAllowedRight, false ];
	}

	/**
	 * @covers ::userHasRight
	 */
	public function testUserHasRight__invalidUsername() {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'not a valid username' );
		$this->getLookup( $this->getInvalidUsernameUserFactory() )->userHasRight( 'Foo', 'bar' );
	}

	/**
	 * @covers ::userIsSitewideBlocked
	 * @dataProvider provideUserIsSitewideBlocked
	 */
	public function testUserIsSitewideBlocked( UserFactory $userFactory, bool $expected ) {
		$this->assertSame( $expected, $this->getLookup( $userFactory )->userIsSitewideBlocked( 'Foo' ) );
	}

	public function provideUserIsSitewideBlocked(): Generator {
		$notBlockedUser = $this->createMock( User::class );
		$notBlockedUser->method( 'getBlock' )->willReturn( null );
		$notBlockedUserFactory = $this->createMock( UserFactory::class );
		$notBlockedUserFactory->method( 'newFromName' )->willReturn( $notBlockedUser );
		yield 'Not blocked' => [ $notBlockedUserFactory, false ];

		$notSitewideBlock = $this->createMock( AbstractBlock::class );
		$notSitewideBlock->method( 'isSitewide' )->willReturn( false );
		$notSitewideUser = $this->createMock( User::class );
		$notSitewideUser->method( 'getBlock' )->willReturn( $notSitewideBlock );
		$notSitewideUserFactory = $this->createMock( UserFactory::class );
		$notSitewideUserFactory->method( 'newFromName' )->willReturn( $notSitewideUser );
		yield 'Block not sitewide' => [ $notSitewideUserFactory, false ];

		$sitewideBlock = $this->createMock( AbstractBlock::class );
		$sitewideBlock->method( 'isSitewide' )->willReturn( true );
		$sitewideUser = $this->createMock( User::class );
		$sitewideUser->method( 'getBlock' )->willReturn( $sitewideBlock );
		$sitewideUserFactory = $this->createMock( UserFactory::class );
		$sitewideUserFactory->method( 'newFromName' )->willReturn( $sitewideUser );
		yield 'Sitewide blocked' => [ $sitewideUserFactory, true ];
	}

	/**
	 * @covers ::userIsSitewideBlocked
	 */
	public function testUserIsSitewideBlocked__invalidUsername() {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'not a valid username' );
		$this->getLookup( $this->getInvalidUsernameUserFactory() )->userIsSitewideBlocked( 'Foo' );
	}

	/**
	 * @covers ::userIsNamed
	 * @dataProvider provideUserIsNamed
	 */
	public function testUserIsNamed( UserFactory $userFactory, bool $expected ) {
		$this->assertSame( $expected, $this->getLookup( $userFactory )->userIsNamed( 'Foo' ) );
	}

	public function provideUserIsNamed(): Generator {
		$notNamedUser = $this->createMock( User::class );
		$notNamedUser->method( 'isNamed' )->willReturn( false );
		$notNamedUserFactory = $this->createMock( UserFactory::class );
		$notNamedUserFactory->method( 'newFromName' )->willReturn( $notNamedUser );
		yield 'Not named' => [ $notNamedUserFactory, false ];

		$namedUser = $this->createMock( User::class );
		$namedUser->method( 'isNamed' )->willReturn( true );
		$namedUserFactory = $this->createMock( UserFactory::class );
		$namedUserFactory->method( 'newFromName' )->willReturn( $namedUser );
		yield 'Named' => [ $namedUserFactory, true ];
	}

	/**
	 * @covers ::userIsNamed
	 */
	public function testUserIsNamed__invalidUsername() {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'not a valid username' );
		$this->getLookup( $this->getInvalidUsernameUserFactory() )->userIsNamed( 'Foo' );
	}
}
