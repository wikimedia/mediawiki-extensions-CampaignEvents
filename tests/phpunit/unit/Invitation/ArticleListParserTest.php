<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Invitation;

use Generator;
use LogicException;
use MediaWiki\Extension\CampaignEvents\Invitation\ArticleList;
use MediaWiki\Extension\CampaignEvents\Invitation\ArticleListParser;
use MediaWiki\Message\Message;
use MediaWiki\Page\PageIdentityValue;
use MediaWiki\Page\PageStore;
use MediaWiki\Page\PageStoreFactory;
use MediaWiki\Page\ProperPageIdentity;
use MediaWikiUnitTestCase;
use StatusValue;

/**
 * @covers \MediaWiki\Extension\CampaignEvents\Invitation\ArticleListParser
 */
class ArticleListParserTest extends MediaWikiUnitTestCase {
	private function getArticleListParser(
		?PageStoreFactory $pageStoreFactory = null
	): ArticleListParser {
		return new ArticleListParser(
			$pageStoreFactory ?? $this->createMock( PageStoreFactory::class )
		);
	}

	public function testParseArticleList() {
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
		$parser = $this->getArticleListParser( $pageStoreFactory );

		$expectedArticleList = new ArticleList( [
			$wiki => array_values( $pages )
		] );
		$status = $parser->parseArticleList( [ $wiki => array_keys( $pages ) ] );
		$this->assertStatusGood( $status );
		$this->assertEquals( $expectedArticleList, $status->getValue() );
	}

	/**
	 * @param array $pages
	 * @param array<string,ProperPageIdentity|null> $pageToObjMap Array that maps page names to the page identity
	 * (or null) that PageStore should return for that page.
	 * @param StatusValue $expected
	 * @dataProvider provideParseArticleList
	 */
	public function testParseArticleList__error( array $pages, array $pageToObjMap, StatusValue $expected ) {
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
		$parser = $this->getArticleListParser( $pageStoreFactory );
		$status = $parser->parseArticleList( $pages );
		$this->assertStatusMessagesExactly( $expected, $status );
	}

	public static function provideParseArticleList(): Generator {
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

		$makeList = static fn ( ...$titles ) => "<ul>\n<li>" . implode( "</li>\n<li>", $titles ) . "</li>\n</ul>";

		yield 'Single invalid title' => [
			[
				$wiki => [
					$invalidTitles[0],
					$validTitles[0],
				]
			],
			$pageToObjMap,
			StatusValue::newFatal(
				'campaignevents-invitation-list-article-list-error-invalid-titles',
				1,
				$makeList( $invalidTitles[0] )
			),
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
			StatusValue::newFatal(
				'campaignevents-invitation-list-article-list-error-invalid-titles',
				2,
				$makeList( $invalidTitles[0], $invalidTitles[1] )
			),
		];
		yield 'Single nonexistent page' => [
			[
				$wiki => [
					$validTitles[0],
					$nonexistentTitles[0],
				],
			],
			$pageToObjMap,
			StatusValue::newFatal(
				'campaignevents-invitation-list-article-list-error-nonexistent-titles',
				1,
				$makeList( $nonexistentTitles[0] )
			),
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
			StatusValue::newFatal(
				'campaignevents-invitation-list-article-list-error-nonexistent-titles',
				2,
				$makeList( $nonexistentTitles[0], $nonexistentTitles[1] )
			),
		];
		yield 'Single non-mainspace page' => [
			[
				$wiki => [
					$nonMainspaceTitles[0],
					$validTitles[0],
				],
			],
			$pageToObjMap,
			StatusValue::newFatal(
				'campaignevents-invitation-list-article-list-error-titles-not-mainspace',
				1,
				$makeList( $nonMainspaceTitles[0] )
			),
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
			StatusValue::newFatal(
				'campaignevents-invitation-list-article-list-error-titles-not-mainspace',
				2,
				$makeList( $nonMainspaceTitles[0], $nonMainspaceTitles[1] )
			),
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
			StatusValue::newFatal(
				'campaignevents-invitation-list-article-list-error-invalid-titles',
				2,
				$makeList( $invalidTitles[0], $invalidTitles[1] )
			)
				->fatal(
					'campaignevents-invitation-list-article-list-error-nonexistent-titles',
					2,
					$makeList( $nonexistentTitles[0], $nonexistentTitles[1] )
				),
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
			StatusValue::newFatal(
				'campaignevents-invitation-list-article-list-error-nonexistent-titles',
				2,
				$makeList( $nonexistentTitles[0], $nonexistentTitles[1] )
			)
				->fatal(
					'campaignevents-invitation-list-article-list-error-titles-not-mainspace',
					2,
					$makeList( $nonMainspaceTitles[0], $nonMainspaceTitles[1] )
				),
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
			StatusValue::newFatal(
				'campaignevents-invitation-list-article-list-error-invalid-titles',
				2,
				$makeList( $invalidTitles[0], $invalidTitles[1] )
			)
				->fatal(
					'campaignevents-invitation-list-article-list-error-titles-not-mainspace',
					2,
					$makeList( $nonMainspaceTitles[0], $nonMainspaceTitles[1] )
				),
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
			StatusValue::newFatal(
				'campaignevents-invitation-list-article-list-error-invalid-titles',
				2,
				$makeList( $invalidTitles[0], $invalidTitles[1] )
			)
				->fatal(
					'campaignevents-invitation-list-article-list-error-nonexistent-titles',
					2,
					$makeList( $nonexistentTitles[0], $nonexistentTitles[1] )
				)
				->fatal(
					'campaignevents-invitation-list-article-list-error-titles-not-mainspace',
					2,
					$makeList( $nonMainspaceTitles[0], $nonMainspaceTitles[1] )
				),
		];

		yield 'Page does not exist and is not in the mainspace' => [
			[
				$wiki => [
					$nonExistentNonMainspaceTitles[0]
				],
			],
			$pageToObjMap,
			StatusValue::newFatal(
				'campaignevents-invitation-list-article-list-error-nonexistent-titles',
				1,
				$makeList( $nonExistentNonMainspaceTitles[0] )
			),
		];

		yield 'Empty list' => [
			[],
			$pageToObjMap,
			StatusValue::newFatal( 'campaignevents-invitation-list-article-list-error-empty' ),
		];

		yield 'Wiki with empty list' => [
			[
				$wiki => []
			],
			$pageToObjMap,
			StatusValue::newFatal( 'campaignevents-invitation-list-article-list-error-empty' ),
		];

		yield 'Too many pages' => [
			[
				$wiki => range( 1, ArticleListParser::ARTICLES_LIMIT + 1 )
			],
			[],
			StatusValue::newFatal(
				'campaignevents-invitation-list-article-list-error-too-large',
				Message::numParam( ArticleListParser::ARTICLES_LIMIT + 1 ),
				Message::numParam( ArticleListParser::ARTICLES_LIMIT )
			),
		];
	}
}
