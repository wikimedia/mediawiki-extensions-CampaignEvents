<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\MWEntity;

use MediaWiki\Config\SiteConfiguration;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\WikiLookup;
use MediaWiki\Tests\Unit\FakeQqxMessageLocalizer;
use MediaWikiUnitTestCase;
use Wikimedia\ObjectCache\EmptyBagOStuff;
use Wikimedia\ObjectCache\WANObjectCache;

/**
 * @covers \MediaWiki\Extension\CampaignEvents\MWEntity\WikiLookup
 */
class WikiLookupTest extends MediaWikiUnitTestCase {
	private function getLookup( array $localDatabases ): WikiLookup {
		$siteConfig = $this->createMock( SiteConfiguration::class );
		$siteConfig->method( 'getLocalDatabases' )->willReturn( $localDatabases );
		return new WikiLookup(
			$siteConfig,
			new WANObjectCache( [ 'cache' => new EmptyBagOStuff() ] ),
			new FakeQqxMessageLocalizer()
		);
	}

	public function testGetAllWikis() {
		$localWikis = [ 'foowiki', 'barwiki' ];
		$lookup = $this->getLookup( $localWikis );
		$this->assertSame( $localWikis, $lookup->getAllWikis() );
	}

	public function testGetAllWikis__duplicates() {
		$localWikis = [ 'foowiki', 'foowiki', 'barwiki' ];
		$lookup = $this->getLookup( $localWikis );
		$this->assertSame( [ 'foowiki', 'barwiki' ], $lookup->getAllWikis() );
	}

	public function testGetListForSelect() {
		$localWikis = [ 'foowiki', 'barwiki' ];
		$lookup = $this->getLookup( $localWikis );
		$this->assertSame(
			[ '(project-localized-name-foowiki)' => 'foowiki', '(project-localized-name-barwiki)' => 'barwiki' ],
			$lookup->getListForSelect()
		);
	}

	public function testGetLocalizedNames() {
		$localWikis = [ 'foowiki', 'barwiki', 'bazwiki' ];
		$lookup = $this->getLookup( $localWikis );
		$expectedLocalized = [
			'foowiki' => '(project-localized-name-foowiki)',
			'barwiki' => '(project-localized-name-barwiki)'
		];
		$this->assertSame( $expectedLocalized, $lookup->getLocalizedNames( array_keys( $expectedLocalized ) ) );
	}

	/**
	 * @param string[]|true $wikis
	 * @param string $expectedIcon
	 * @dataProvider provideWikiIconData
	 * @return void
	 */
	public function testGetWikiIcon( $wikis, string $expectedIcon ) {
		$lookup = $this->getLookup( [] );
		$this->assertSame( $expectedIcon, $lookup->getWikiIcon( $wikis ) );
	}

	public static function provideWikiIconData(): array {
		return [
			'Mixed wikis' => [
				[ 'acommonswiki', 'anothercommonswiki', 'awikidatawiki' ],
				'logoWikimedia'
			],
			'All wikis' => [
				EventRegistration::ALL_WIKIS,
				'logoWikimedia'
			],
			'Single wikiGroup' => [
				[ 'acommonswiki', 'anothercommonswiki', 'adifferentcommonswiki' ],
				'logoWikimediaCommons'
			],
			'Wikipedia family' => [
				[ 'enwiki', 'frwiki', 'eswiki' ],
				'logoWikipedia'
			],
			'Unknown wikis' => [
				[ 'foo', 'bar', 'baz' ],
				'logoWikimedia'
			],
			'No wikis' => [ [], 'logoWikimedia' ],
		];
	}
}