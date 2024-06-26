<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Invitation;

use Generator;
use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Extension\CampaignEvents\Invitation\Worklist;
use MediaWiki\Page\PageIdentityValue;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiUnitTestCase;
use Wikimedia\Assert\AssertionException;

/**
 * @covers \MediaWiki\Extension\CampaignEvents\Invitation\Worklist
 */
class WorklistTest extends MediaWikiUnitTestCase {
	/**
	 * @dataProvider provideConstructorData
	 */
	public function testConstructor( array $pages, ?string $expectedError ) {
		if ( $expectedError !== null ) {
			$this->expectException( AssertionException::class );
			$this->expectExceptionMessage( $expectedError );
		}
		new Worklist( $pages );
		if ( $expectedError === null ) {
			$this->addToAssertionCount( 1 );
		}
	}

	public static function provideConstructorData(): Generator {
		yield 'Malformed array' => [
			[ 'some_wiki' => true ],
			'Bad value for parameter $pagesByWiki',
		];
		yield 'Empty pages' => [
			[ 'some_wiki' => [] ],
			'Pages must not be empty',
		];
		yield 'Page is not a PageIdentity' => [
			[ 'some_wiki' => [ 'Foobar' ] ],
			'Pages must be PageIdentity objects',
		];
		yield 'Mismatching wiki ID' => [
			[ 'some_wiki' => [ new PageIdentityValue( 1, NS_MAIN, 'Foo', 'other_wiki' ) ] ],
			'Page wiki ID should match array key',
		];
		yield 'Page does not exist' => [
			[ 'some_wiki' => [ new PageIdentityValue( 0, NS_MAIN, 'Foo', 'some_wiki' ) ] ],
			null,
		];
		yield 'Page is not in the mainspace' => [
			[ 'some_wiki' => [ new PageIdentityValue( 42, NS_TEMPLATE, 'Foo', 'some_wiki' ) ] ],
			null,
		];
	}

	public function testGetter() {
		$pagesByWiki = self::getSamplePagesByWiki();
		$worklist = new Worklist( $pagesByWiki );
		$this->assertSame( $pagesByWiki, $worklist->getPagesByWiki() );
	}

	public function testSerialization() {
		$pagesByWiki = self::getSamplePagesByWiki();
		$worklist = new Worklist( $pagesByWiki );
		$this->assertEquals( $worklist, Worklist::fromPlainArray( $worklist->toPlainArray() ) );
	}

	private static function getSamplePagesByWiki(): array {
		return [
			WikiMap::getCurrentWikiId() => [
				new PageIdentityValue( 42, NS_MAIN, 'Test', WikiAwareEntity::LOCAL ),
				new PageIdentityValue( 100, NS_MAIN, 'Test2', WikiAwareEntity::LOCAL )
			],
			'some_other_wiki' => [
				new PageIdentityValue( 1, NS_MAIN, 'Foreign_test', 'some_other_wiki' )
			]
		];
	}
}
