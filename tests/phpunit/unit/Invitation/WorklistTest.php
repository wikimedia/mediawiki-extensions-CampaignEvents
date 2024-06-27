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
	 * @dataProvider provideConstructorErrors
	 */
	public function testConstructor__errors( array $pages, string $expectedError ) {
		$this->expectException( AssertionException::class );
		$this->expectExceptionMessage( $expectedError );
		new Worklist( $pages );
	}

	public static function provideConstructorErrors(): Generator {
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
			'Pages must exist',
		];
		yield 'Page is not in the mainspace' => [
			[ 'some_wiki' => [ new PageIdentityValue( 42, NS_TEMPLATE, 'Foo', 'some_wiki' ) ] ],
			'Pages must be in the mainspace',
		];
	}

	public function testGetter() {
		$pagesByWiki = [
			WikiMap::getCurrentWikiId() => [
				new PageIdentityValue( 42, NS_MAIN, 'Test', WikiAwareEntity::LOCAL ),
				new PageIdentityValue( 100, NS_MAIN, 'Test2', WikiAwareEntity::LOCAL )
			],
			'some_other_wiki' => [
				new PageIdentityValue( 1, NS_MAIN, 'Foreign_test', 'some_other_wiki' )
			]
		];
		$worklist = new Worklist( $pagesByWiki );
		$this->assertSame( $pagesByWiki, $worklist->getPagesByWiki() );
	}
}
