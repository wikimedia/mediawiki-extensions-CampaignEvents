<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Invitation;

use Generator;
use InvalidArgumentException;
use LogicException;
use MediaWiki\Extension\CampaignEvents\Invitation\Worklist;
use MediaWiki\Extension\CampaignEvents\Invitation\WorklistParser;
use MediaWiki\Page\PageIdentityValue;
use MediaWiki\Page\PageStore;
use MediaWiki\Page\PageStoreFactory;
use MediaWiki\Page\ProperPageIdentity;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\CampaignEvents\Invitation\WorklistParser
 */
class WorklistParserTest extends MediaWikiUnitTestCase {
	private function getWorklistParser(
		PageStoreFactory $pageStoreFactory = null
	): WorklistParser {
		return new WorklistParser(
			$pageStoreFactory ?? $this->createMock( PageStoreFactory::class )
		);
	}

	public function testParseWorklist() {
		$wiki = 'some_wiki';
		$pages = [
			'Page 1' => new PageIdentityValue( 5, NS_MAIN, 'Page_1', $wiki ),
			'Page 2' => new PageIdentityValue( 15, NS_MAIN, 'Page_2', $wiki ),
		];

		$pageStore = $this->createMock( PageStore::class );
		$pageStore->method( 'getPageByText' )
			->willReturnCallback( static function ( $page ) use ( $pages ) {
				if ( !array_key_exists( $page, $pages ) ) {
					throw new LogicException( "No mapping for page $page" );
				}
				return $pages[ $page ];
			} );
		$pageStoreFactory = $this->createMock( PageStoreFactory::class );
		$pageStoreFactory->method( 'getPageStore' )
			->with( $wiki )
			->willReturn( $pageStore );
		$parser = $this->getWorklistParser( $pageStoreFactory );

		$expectedWorklist = new Worklist( [
			$wiki => array_values( $pages )
		] );
		$this->assertEquals( $expectedWorklist, $parser->parseWorklist( [ $wiki => array_keys( $pages ) ] ) );
	}

	/**
	 * @param array $pages
	 * @param array<string,ProperPageIdentity|null> $pageToObjMap Array that maps page names to the page identity
	 * (or null) that PageStore should return for that page.
	 * @param string $expectedError
	 * @dataProvider provideParseWorklist
	 */
	public function testParseWorklist__error( array $pages, array $pageToObjMap, string $expectedError ) {
		$pageStore = $this->createMock( PageStore::class );
		$pageStore->method( 'getPageByText' )
			->willReturnCallback( static function ( $page ) use ( $pageToObjMap ) {
				if ( !array_key_exists( $page, $pageToObjMap ) ) {
					throw new LogicException( "No mapping for page $page" );
				}
				return $pageToObjMap[ $page ];
			} );
		$pageStoreFactory = $this->createMock( PageStoreFactory::class );
		$pageStoreFactory->method( 'getPageStore' )->willReturn( $pageStore );
		$parser = $this->getWorklistParser( $pageStoreFactory );
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( $expectedError );
		$parser->parseWorklist( $pages );
	}

	public static function provideParseWorklist(): Generator {
		$wiki = 'localwiki';
		$invalidTitles = [ 'Invalid title 1', 'Invalid title 2' ];
		$nonexistentTitles = [ 'Nonexistent title 1', 'Nonexistent title 2' ];
		$nonMainspaceTitles = [ 'Help:Help page 1', 'Help:Help page 2' ];
		$nonExistentNonMainspaceTitles = [ 'User:Rick Astley', 'User:Jimbo Wales' ];
		$validTitles = [ 'Valid title 1', 'Valid title 2' ];

		$pageToObjMap = array_fill_keys( $invalidTitles, null );
		foreach ( $nonexistentTitles as $nonexistentTitle ) {
			$pageToObjMap[$nonexistentTitle] = new PageIdentityValue( 0, NS_MAIN, $nonexistentTitle, $wiki );
		}
		foreach ( $nonMainspaceTitles as $nonMainspaceTitle ) {
			$pageToObjMap[$nonMainspaceTitle] = new PageIdentityValue(
				random_int( 1, 1000 ),
				NS_HELP,
				str_replace( 'Help:', '', $nonMainspaceTitle ),
				$wiki
			);
		}
		foreach ( $nonExistentNonMainspaceTitles as $nonExistentNonMainspaceTitle ) {
			$pageToObjMap[$nonExistentNonMainspaceTitle] = new PageIdentityValue(
				0,
				NS_USER,
				str_replace( 'User:', '', $nonExistentNonMainspaceTitle ),
				$wiki
			);
		}
		foreach ( $validTitles as $validTitle ) {
			$pageToObjMap[$validTitle] = new PageIdentityValue( random_int( 1, 1000 ), NS_MAIN, $validTitle, $wiki );
		}

		yield 'Single invalid title' => [
			[
				$wiki => [
					$invalidTitles[0],
					$validTitles[0],
				]
			],
			$pageToObjMap,
			"Invalid title: $invalidTitles[0]",
		];
		yield 'Multiple invalid titles' => [
			[
				$wiki => [
					$invalidTitles[0],
					$validTitles[0],
					$invalidTitles[1],
				],
			],
			$pageToObjMap,
			"Invalid title: $invalidTitles[0]",
		];
		yield 'Single nonexistent page' => [
			[
				$wiki => [
					$validTitles[0],
					$nonexistentTitles[0],
				],
			],
			$pageToObjMap,
			"Page does not exist: $nonexistentTitles[0]",
		];
		yield 'Multiple nonexistent pages' => [
			[
				$wiki => [
					$validTitles[0],
					$nonexistentTitles[0],
					$nonexistentTitles[1],
				],
			],
			$pageToObjMap,
			"Page does not exist: $nonexistentTitles[0]",
		];
		yield 'Single non-mainspace page' => [
			[
				$wiki => [
					$nonMainspaceTitles[0],
					$validTitles[0],
				],
			],
			$pageToObjMap,
			"Page is not in the mainspace: $nonMainspaceTitles[0]",
		];
		yield 'Multiple non-mainspace pages' => [
			[
				$wiki => [
					$validTitles[0],
					$nonMainspaceTitles[0],
					$nonMainspaceTitles[1],
				],
			],
			$pageToObjMap,
			"Page is not in the mainspace: $nonMainspaceTitles[0]",
		];

		yield 'Two invalid, two nonexistent pages' => [
			[
				$wiki => [
					$invalidTitles[0],
					$invalidTitles[1],
					$nonexistentTitles[0],
					$nonexistentTitles[1],
				],
			],
			$pageToObjMap,
			"Invalid title: $invalidTitles[0]",
		];
		yield 'Two nonexistent, two non-mainspace pages' => [
			[
				$wiki => [
					$nonexistentTitles[0],
					$nonexistentTitles[1],
					$nonMainspaceTitles[0],
					$nonMainspaceTitles[1],
				],
			],
			$pageToObjMap,
			"Page does not exist: $nonexistentTitles[0]",
		];
		yield 'Two invalid, two non-mainspace pages' => [
			[
				$wiki => [
					$invalidTitles[0],
					$invalidTitles[1],
					$nonMainspaceTitles[0],
					$nonMainspaceTitles[1],
				],
			],
			$pageToObjMap,
			"Invalid title: $invalidTitles[0]",
		];
		yield 'Two invalid, two nonexistent, two non-mainspace pages' => [
			[
				$wiki => [
					$invalidTitles[0],
					$invalidTitles[1],
					$nonexistentTitles[0],
					$nonexistentTitles[1],
					$nonMainspaceTitles[0],
					$nonMainspaceTitles[1],
				],
			],
			$pageToObjMap,
			"Invalid title: $invalidTitles[0]",
		];

		yield 'Page does not exist and is not in the mainspace' => [
			[
				$wiki => [
					$nonExistentNonMainspaceTitles[0]
				],
			],
			$pageToObjMap,
			"Page does not exist: $nonExistentNonMainspaceTitles[0]",
		];

		yield 'Empty list' => [
			[],
			$pageToObjMap,
			'Empty list of articles'
		];

		yield 'Wiki with empty list' => [
			[
				$wiki => []
			],
			$pageToObjMap,
			'Empty list of articles'
		];

		yield 'Too many pages' => [
			[
				$wiki => range( 1, WorklistParser::ARTICLES_LIMIT + 1 )
			],
			[],
			'The worklist has more than'
		];
	}
}
