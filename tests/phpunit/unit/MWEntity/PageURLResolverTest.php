<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\MWEntity;

use MediaWiki\Extension\CampaignEvents\MWEntity\MWPageProxy;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageURLResolver;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWikiUnitTestCase;

/**
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\MWEntity\PageURLResolver
 * @covers ::__construct
 * @todo Unit-test the external page case. Right now this is hard because WikiMap
 * is not DI-friendly and reads globals all over the place.
 */
class PageURLResolverTest extends MediaWikiUnitTestCase {
	/**
	 * @covers ::getUrl
	 */
	public function testGetUrl__localPage() {
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

		$resolver = new PageURLResolver( $localTitleFactory );
		$this->assertSame( $localUrl, $resolver->getUrl( $localPage ) );
	}
}
