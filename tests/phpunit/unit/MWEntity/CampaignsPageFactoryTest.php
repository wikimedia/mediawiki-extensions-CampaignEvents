<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\MWEntity;

use Generator;
use MalformedTitleException;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFactory;
use MediaWiki\Extension\CampaignEvents\MWEntity\InvalidTitleStringException;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageNotFoundException;
use MediaWiki\Extension\CampaignEvents\MWEntity\UnexpectedInterwikiException;
use MediaWiki\Extension\CampaignEvents\MWEntity\UnexpectedSectionAnchorException;
use MediaWiki\Extension\CampaignEvents\MWEntity\UnexpectedVirtualNamespaceException;
use MediaWiki\Page\ExistingPageRecord;
use MediaWiki\Page\PageStore;
use MediaWiki\Page\PageStoreFactory;
use MediaWikiUnitTestCase;
use TitleFormatter;
use TitleParser;
use TitleValue;

/**
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFactory
 * @covers ::__construct
 */
class CampaignsPageFactoryTest extends MediaWikiUnitTestCase {
	private function getFactory(
		TitleParser $titleParser = null,
		PageStoreFactory $pageStoreFactory = null
	): CampaignsPageFactory {
		$titleFormatter = $this->createMock( TitleFormatter::class );
		// TODO Remove the followig line once the return value of getPrefixedText is typehinted
		$titleFormatter->method( 'getPrefixedText' )->willReturn( 'Something' );
		return new CampaignsPageFactory(
			$pageStoreFactory ?? $this->createMock( PageStoreFactory::class ),
			$titleParser ?? $this->createMock( TitleParser::class ),
			$titleFormatter
		);
	}

	/**
	 * @param string $titleString
	 * @param string|null $expectedExcepClass
	 * @param TitleParser|null $titleParser
	 * @param PageStoreFactory|null $pageStoreFactory
	 * @dataProvider provideTitleStrings
	 * @covers ::newLocalExistingPageFromString
	 */
	public function testNewLocalExistingPageFromString(
		string $titleString,
		?string $expectedExcepClass,
		TitleParser $titleParser = null,
		PageStoreFactory $pageStoreFactory = null
	) {
		$factory = $this->getFactory( $titleParser, $pageStoreFactory );
		if ( $expectedExcepClass !== null ) {
			$this->expectException( $expectedExcepClass );
		}
		$factory->newLocalExistingPageFromString( $titleString );
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
		yield 'Valid' => [ 'Foobar', null, $validTitleParser, $existingPageStoreFactory ];

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

		$interwikiPrefix = 'en';
		$interwikiStr = "$interwikiPrefix:Something";
		$interwikiTitleParser = $this->createMock( TitleParser::class );
		$interwikiTitleParser->expects( $this->atLeastOnce() )
			->method( 'parseTitle' )
			->with( $interwikiStr )
			->willReturn( new TitleValue( NS_MAIN, 'Something', '', $interwikiPrefix ) );
		yield 'Unexpected interwiki' => [
			$interwikiStr,
			UnexpectedInterwikiException::class,
			$interwikiTitleParser
		];

		$section = 'SomeSection';
		$sectionStr = "Something#$section";
		$sectionTitleParser = $this->createMock( TitleParser::class );
		$sectionTitleParser->expects( $this->atLeastOnce() )
			->method( 'parseTitle' )
			->with( $sectionStr )
			->willReturn( new TitleValue( NS_MAIN, 'Something', $section ) );
		yield 'Unexpected section anchor' => [
			$sectionStr,
			UnexpectedSectionAnchorException::class,
			$sectionTitleParser
		];

		$specialStr = 'Special:Foobar';
		$specialTitle = $this->createMock( TitleValue::class );
		$specialTitle->method( 'getNamespace' )->willReturn( NS_SPECIAL );
		$specialTitleParser = $this->createMock( TitleParser::class );
		$specialTitleParser->method( 'parseTitle' )->willReturn( $specialTitle );
		yield 'In the Special: namespace' => [
			$specialStr,
			UnexpectedVirtualNamespaceException::class,
			$specialTitleParser
		];

		yield 'Not found' => [ 'Foobar', PageNotFoundException::class, $validTitleParser ];
	}
}
