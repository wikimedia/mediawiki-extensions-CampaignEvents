<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\MWEntity;

use Generator;
use MalformedTitleException;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFactory;
use MediaWiki\Extension\CampaignEvents\MWEntity\InvalidInterwikiException;
use MediaWiki\Extension\CampaignEvents\MWEntity\InvalidTitleStringException;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageNotFoundException;
use MediaWiki\Interwiki\InterwikiLookup;
use MediaWiki\Page\ExistingPageRecord;
use MediaWiki\Page\PageStore;
use MediaWiki\Page\PageStoreFactory;
use MediaWikiUnitTestCase;
use TitleParser;
use TitleValue;

/**
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFactory
 * @covers ::__construct
 */
class CampaignsPageFactoryTest extends MediaWikiUnitTestCase {
	private function getFactory(
		TitleParser $titleParser = null,
		InterwikiLookup $interwikiLookup = null,
		PageStoreFactory $pageStoreFactory = null
	): CampaignsPageFactory {
		return new CampaignsPageFactory(
			$pageStoreFactory ?? $this->createMock( PageStoreFactory::class ),
			$titleParser ?? $this->createMock( TitleParser::class ),
			$interwikiLookup ?? $this->createMock( InterwikiLookup::class )
		);
	}

	/**
	 * @param string $titleString
	 * @param string|null $expectedExcepClass
	 * @param TitleParser|null $titleParser
	 * @param InterwikiLookup|null $interwikiLookup
	 * @param PageStoreFactory|null $pageStoreFactory
	 * @dataProvider provideTitleStrings
	 */
	public function testNewExistingPageFromString(
		string $titleString,
		?string $expectedExcepClass,
		TitleParser $titleParser = null,
		InterwikiLookup $interwikiLookup = null,
		PageStoreFactory $pageStoreFactory = null
	) {
		$factory = $this->getFactory( $titleParser, $interwikiLookup, $pageStoreFactory );
		if ( $expectedExcepClass !== null ) {
			$this->expectException( $expectedExcepClass );
		}
		$factory->newExistingPageFromString( $titleString );
		if ( $expectedExcepClass === null ) {
			$this->addToAssertionCount( 1 );
		}
	}

	public function provideTitleStrings(): Generator {
		$validTitleParser = $this->createMock( TitleParser::class );
		// TODO: Remove this line when TitleParser::parseTitle will be return-typehinted
		$validTitleParser->method( 'parseTitle' )->willReturn( $this->createMock( TitleValue::class ) );
		$existingPageStore = $this->createMock( PageStore::class );
		$existingPageStore->method( 'getPageByName' )->willReturn( $this->createMock( ExistingPageRecord::class ) );
		$existingPageStoreFactory = $this->createMock( PageStoreFactory::class );
		$existingPageStoreFactory->method( 'getPageStore' )->willReturn( $existingPageStore );
		yield 'Valid' => [ 'Foobar', null, $validTitleParser, null, $existingPageStoreFactory ];

		$malformedStr = 'Foo|bar';
		// TODO: Remove mocking of the exception methods once they'll be return-typehinted.
		// Also, we can't instantiate it directly due to T287405
		$malformedTitleExcep = $this->createMock( MalformedTitleException::class );
		$malformedTitleExcep->method( 'getErrorMessage' )->willReturn( 'Foo' );
		$malformedTitleExcep->method( 'getErrorMessageParameters' )->willReturn( [] );
		$malformedTitleParser = $this->createMock( TitleParser::class );
		$malformedTitleParser->expects( $this->atLeastOnce() )
			->method( 'parseTitle' )
			->with( $malformedStr )
			->willThrowException( $malformedTitleExcep );
		yield 'Malformed' => [ $malformedStr, InvalidTitleStringException::class, $malformedTitleParser ];

		$badInterwiki = 'foooooo';
		$badInterwikiStr = "$badInterwiki:Something";
		$interwikiTitleParser = $this->createMock( TitleParser::class );
		$interwikiTitleParser->expects( $this->atLeastOnce() )
			->method( 'parseTitle' )
			->with( $badInterwikiStr )
			->willReturn( new TitleValue( NS_MAIN, 'Something', '', $badInterwiki ) );
		$badInterwikiLookup = $this->createMock( InterwikiLookup::class );
		$badInterwikiLookup->expects( $this->atLeastOnce() )
			->method( 'fetch' )
			->with( $badInterwiki )
			->willReturn( null );
		yield 'Bad interwiki' => [
			$badInterwikiStr,
			InvalidInterwikiException::class,
			$interwikiTitleParser,
			$badInterwikiLookup
		];

		yield 'Not found' => [ 'Foobar', PageNotFoundException::class, $validTitleParser ];
	}
}
