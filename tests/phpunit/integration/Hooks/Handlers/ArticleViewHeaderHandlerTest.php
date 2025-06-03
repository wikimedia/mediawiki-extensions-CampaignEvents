<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Hooks\Handlers;

use Generator;
use MediaWiki\Config\HashConfig;
use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\CampaignEvents\EventPage\EventPageDecorator;
use MediaWiki\Extension\CampaignEvents\EventPage\EventPageDecoratorFactory;
use MediaWiki\Extension\CampaignEvents\Hooks\Handlers\ArticleViewHeaderHandler;
use MediaWiki\Language\Language;
use MediaWiki\Output\OutputPage;
use MediaWiki\Page\Article;
use MediaWiki\Page\WikiPage;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;

/**
 * @group Test
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Hooks\Handlers\ArticleViewHeaderHandler
 * @covers ::__construct()
 * @todo Make this a pure unit test once it's possible to use NS_EVENT (T310375)
 */
class ArticleViewHeaderHandlerTest extends MediaWikiIntegrationTestCase {
	/**
	 * @dataProvider provideArticle
	 * @covers ::onArticleViewHeader
	 */
	public function testOnArticleViewHeader(
		int $ns,
		array $allowedNamespaces,
		bool $exists,
		bool $expectedDecorates
	) {
		$decorator = $this->createMock( EventPageDecorator::class );
		if ( $expectedDecorates ) {
			$decorator->expects( $this->once() )->method( 'decoratePage' );
		} else {
			$decorator->expects( $this->never() )->method( 'decoratePage' );
		}
		$decoratorFactory = $this->createMock( EventPageDecoratorFactory::class );
		$decoratorFactory->method( 'newDecorator' )->willReturn( $decorator );
		$handler = new ArticleViewHeaderHandler(
			$decoratorFactory,
			new HashConfig( [ 'CampaignEventsEventNamespaces' => $allowedNamespaces ] )
		);
		$outputDone = true;
		$pcache = true;
		$article = $this->getMockArticle( $ns, $exists );
		$handler->onArticleViewHeader( $article, $outputDone, $pcache );
		// The soft assertions in the mock are sufficient
		$this->addToAssertionCount( 1 );
	}

	public static function provideArticle(): Generator {
		$exists = true;
		$doesNotExist = false;

		yield 'Project namespace, not allowed, does not exist' => [ NS_PROJECT, [], $doesNotExist, false ];
		yield 'Project namespace, allowed, does not exist' => [ NS_PROJECT, [ NS_PROJECT ], $doesNotExist, false ];
		yield 'Project namespace, not allowed, exists' => [ NS_PROJECT, [], $exists, false ];
		yield 'Project namespace, allowed, exists' => [ NS_PROJECT, [ NS_PROJECT ], $exists, true ];

		yield 'Event namespace, not allowed, does not exist' => [ NS_EVENT, [], $doesNotExist, false ];
		yield 'Event namespace, allowed, does not exist' => [ NS_EVENT, [ NS_EVENT ], $doesNotExist, false ];
		yield 'Event namespace, not allowed, exists' => [ NS_EVENT, [], $exists, false ];
		yield 'Event namespace, allowed, exists' => [ NS_EVENT, [ NS_EVENT ], $exists, true ];
	}

	private function getMockArticle( int $ns, bool $exists ): Article {
		$wikiPage = $this->createMock( WikiPage::class );
		$wikiPage->method( 'getNamespace' )->willReturn( $ns );
		$wikiPage->method( 'exists' )->willReturn( $exists );
		$article = $this->createMock( Article::class );
		$article->method( 'getPage' )->willReturn( $wikiPage );
		// XXX Need to mock all this stuff because the methods are not typehinted
		$ctx = $this->createMock( IContextSource::class );
		$ctx->method( 'getOutput' )->willReturn( $this->createMock( OutputPage::class ) );
		$ctx->method( 'getLanguage' )->willReturn( $this->createMock( Language::class ) );
		$ctx->method( 'getUser' )->willReturn( $this->createMock( User::class ) );
		$article->method( 'getContext' )->willReturn( $ctx );
		return $article;
	}
}
