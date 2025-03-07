<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\MWEntity;

use Generator;
use InvalidArgumentException;
use MediaWiki\Block\AbstractBlock;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWPermissionsLookup;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserNameUtils;
use MediaWikiUnitTestCase;

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
	public function testUserHasRight( array $isAllowedMap, string $right, bool $expected ) {
		$user = $this->createMock( User::class );
		$user->method( 'isAllowed' )->willReturnMap( $isAllowedMap );
		$userFactory = $this->createMock( UserFactory::class );
		$userFactory->method( 'newFromName' )->willReturn( $user );

		$this->assertSame( $expected, $this->getLookup( $userFactory )->userHasRight( 'Foo', $right ) );
	}

	public static function provideUserHasRight(): Generator {
		$allowedRight = 'some-right-1';
		$notAllowedRight = 'some-right-2';
		$isAllowedMap = [
			[ $allowedRight, null, true ],
			[ $notAllowedRight, null, false ]
		];

		yield 'Has right' => [ $isAllowedMap, $allowedRight, true ];
		yield 'Does not have right' => [ $isAllowedMap, $notAllowedRight, false ];
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
	public function testUserIsSitewideBlocked( bool $isBlocked, bool $blockIsSitewide, bool $expected ) {
		if ( $isBlocked ) {
			$block = $this->createMock( AbstractBlock::class );
			$block->method( 'isSitewide' )->willReturn( $blockIsSitewide );
		} else {
			$block = null;
		}
		$user = $this->createMock( User::class );
		$user->method( 'getBlock' )->willReturn( $block );
		$userFactory = $this->createMock( UserFactory::class );
		$userFactory->method( 'newFromName' )->willReturn( $user );
		$this->assertSame( $expected, $this->getLookup( $userFactory )->userIsSitewideBlocked( 'Foo' ) );
	}

	public static function provideUserIsSitewideBlocked(): Generator {
		yield 'Not blocked' => [ false, false, false ];
		yield 'Block not sitewide' => [ true, false, false ];
		yield 'Sitewide blocked' => [ true, true, true ];
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
	public function testUserIsNamed( bool $isNamed ) {
		$user = $this->createMock( User::class );
		$user->method( 'isNamed' )->willReturn( $isNamed );
		$userFactory = $this->createMock( UserFactory::class );
		$userFactory->method( 'newFromName' )->willReturn( $user );

		$this->assertSame( $isNamed, $this->getLookup( $userFactory )->userIsNamed( 'Foo' ) );
	}

	public static function provideUserIsNamed(): Generator {
		yield 'Not named' => [ false ];
		yield 'Named' => [ true ];
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
