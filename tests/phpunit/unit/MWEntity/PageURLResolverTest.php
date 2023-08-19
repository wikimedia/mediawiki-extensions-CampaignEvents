<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\MWEntity;

use Generator;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsPage;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWPageProxy;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageURLResolver;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWikiUnitTestCase;

/**
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\MWEntity\PageURLResolver
 * @covers ::__construct
 */
class PageURLResolverTest extends MediaWikiUnitTestCase {
	/**
	 * @dataProvider providePageAndURL
	 * @covers ::getUrl
	 */
	public function testGetUrl( ICampaignsPage $page, TitleFactory $titleFactory, string $expected ) {
		$resolver = new PageURLResolver( $titleFactory );
		$this->assertSame( $expected, $resolver->getUrl( $page ) );
	}

	public function providePageAndURL(): Generator {
		$localUrl = 'test-local-url';
		$localPageIdentity = $this->createMock( ProperPageIdentity::class );
		$localPageIdentity->method( 'getWikiId' )->willReturn( ProperPageIdentity::LOCAL );
		$localPage = new MWPageProxy( $localPageIdentity, 'Unused' );
		$localTitle = $this->createMock( Title::class );
		$localTitle->method( 'getLocalURL' )->willReturn( $localUrl );
		$localTitleFactory = $this->createMock( TitleFactory::class );
		$localTitleFactory->expects( $this->once() )
			->method( 'castFromPageIdentity' )
			->with( $localPageIdentity )
			->willReturn( $localTitle );
		yield 'Local' => [ $localPage, $localTitleFactory, $localUrl ];

		// TODO Unit-test the external page case. Right now this is hard because WikiMap
		// is not DI-friendly and reads globals all over the place.
	}
}
