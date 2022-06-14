<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Hooks\Handlers;

use Article;
use Generator;
use IContextSource;
use Language;
use MediaWiki\Extension\CampaignEvents\EventPage\EventPageDecorator;
use MediaWiki\Extension\CampaignEvents\Hooks\Handlers\ArticleViewHeaderHandler;
use MediaWikiIntegrationTestCase;
use OutputPage;
use User;
use WikiPage;

/**
 * @group Test
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Hooks\Handlers\ArticleViewHeaderHandler
 * @covers ::__construct()
 * @todo Make this a pure unit test once it's possible to use NS_EVENT (T310375)
 */
class ArticleViewHeaderHandlerTest extends MediaWikiIntegrationTestCase {
	/**
	 * @param Article $article
	 * @param bool $expectedDecorates
	 * @dataProvider provideArticle
	 * @covers ::onArticleViewHeader
	 */
	public function testOnArticleViewHeader( Article $article, bool $expectedDecorates ) {
		$decorator = $this->createMock( EventPageDecorator::class );
		if ( $expectedDecorates ) {
			$decorator->expects( $this->once() )->method( 'decoratePage' );
		} else {
			$decorator->expects( $this->never() )->method( 'decoratePage' );
		}
		$handler = new ArticleViewHeaderHandler( $decorator );
		$outputDone = true;
		$pcache = true;
		$handler->onArticleViewHeader( $article, $outputDone, $pcache );
		// The soft assertions in the mock are sufficient
		$this->addToAssertionCount( 1 );
	}

	public function provideArticle(): Generator {
		$mockArticleInNamespace = function ( int $ns ): Article {
			$wikiPage = $this->createMock( WikiPage::class );
			$wikiPage->method( 'getNamespace' )->willReturn( $ns );
			$article = $this->createMock( Article::class );
			$article->method( 'getPage' )->willReturn( $wikiPage );
			// XXX Need to mock all this stuff because the method is not typehinted
			$ctx = $this->createMock( IContextSource::class );
			$ctx->method( 'getOutput' )->willReturn( $this->createMock( OutputPage::class ) );
			$ctx->method( 'getLanguage' )->willReturn( $this->createMock( Language::class ) );
			$ctx->method( 'getUser' )->willReturn( $this->createMock( User::class ) );
			$article->method( 'getContext' )->willReturn( $ctx );
			return $article;
		};

		yield 'Mainspace article' => [ $mockArticleInNamespace( NS_MAIN ), false ];
		yield 'Project page' => [ $mockArticleInNamespace( NS_PROJECT ), false ];
		yield 'Event page' => [ $mockArticleInNamespace( NS_EVENT ), true ];
	}
}
