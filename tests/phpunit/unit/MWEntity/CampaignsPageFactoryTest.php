<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\MWEntity;

use Generator;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFactory;
use MediaWiki\Extension\CampaignEvents\MWEntity\InvalidTitleStringException;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageNotFoundException;
use MediaWiki\Extension\CampaignEvents\MWEntity\UnexpectedInterwikiException;
use MediaWiki\Extension\CampaignEvents\MWEntity\UnexpectedSectionAnchorException;
use MediaWiki\Extension\CampaignEvents\MWEntity\UnexpectedVirtualNamespaceException;
use MediaWiki\Page\ExistingPageRecord;
use MediaWiki\Page\PageStore;
use MediaWiki\Page\PageStoreFactory;
use MediaWiki\Title\MalformedTitleException;
use MediaWiki\Title\TitleFormatter;
use MediaWiki\Title\TitleParser;
use MediaWiki\Title\TitleValue;
use MediaWikiUnitTestCase;

/**
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFactory
 * @covers ::__construct
 */
class CampaignsPageFactoryTest extends MediaWikiUnitTestCase {
	private function getFactory(
		?TitleParser $titleParser = null,
		?PageStoreFactory $pageStoreFactory = null
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

	/** @todo Replace with just `createMock` when TitleParser::parseTitle will be return-typehinted */
	private function getValidTitleParser(): TitleParser {
		$titleParser = $this->createMock( TitleParser::class );
		$titleParser->method( 'parseTitle' )->willReturn( $this->createMock( TitleValue::class ) );
		return $titleParser;
	}

	public function testNewLocalExistingPageFromString__valid() {
		$titleParser = $this->getValidTitleParser();
		$existingPageStore = $this->createMock( PageStore::class );
		$existingPageStore->method( 'getPageByName' )->willReturn( $this->createMock( ExistingPageRecord::class ) );
		$pageStoreFactory = $this->createMock( PageStoreFactory::class );
		$pageStoreFactory->method( 'getPageStore' )->willReturn( $existingPageStore );

		$factory = $this->getFactory( $titleParser, $pageStoreFactory );
		$factory->newLocalExistingPageFromString( 'Foobar' );
		// Implicit assertion that no exception is thrown.
		$this->addToAssertionCount( 1 );
	}

	public function testNewLocalExistingPageFromString__pageNotFound() {
		$titleParser = $this->getValidTitleParser();
		$factory = $this->getFactory( $titleParser );
		$this->expectException( PageNotFoundException::class );
		$factory->newLocalExistingPageFromString( 'Foobar' );
	}

	public function testNewLocalExistingPageFromString__malformedTitleString() {
		$titleString = 'Foo|bar';
		// TODO: Remove mocking of the exception methods once they'll be return-typehinted.
		// Also, we can't instantiate it directly due to T287405
		$malformedTitleExcep = $this->createMock( MalformedTitleException::class );
		$malformedTitleExcep->method( 'getErrorMessage' )->willReturn( 'Foo' );
		$malformedTitleExcep->method( 'getErrorMessageParameters' )->willReturn( [] );
		$titleParser = $this->createMock( TitleParser::class );
		$titleParser->expects( $this->atLeastOnce() )
			->method( 'parseTitle' )
			->with( $titleString )
			->willThrowException( $malformedTitleExcep );

		$factory = $this->getFactory( $titleParser );
		$this->expectException( InvalidTitleStringException::class );
		$factory->newLocalExistingPageFromString( $titleString );
	}

	/**
	 * @dataProvider provideInvalidTitles
	 * @covers ::newLocalExistingPageFromString
	 */
	public function testNewLocalExistingPageFromString__invalidTitle(
		string $titleString,
		string $expectedExcepClass,
		TitleValue $parsedTitle,
		?PageStoreFactory $pageStoreFactory = null
	) {
		$titleParser = $this->createMock( TitleParser::class );
		$titleParser->expects( $this->atLeastOnce() )
			->method( 'parseTitle' )
			->with( $titleString )
			->willReturn( $parsedTitle );

		$factory = $this->getFactory( $titleParser, $pageStoreFactory );
		$this->expectException( $expectedExcepClass );
		$factory->newLocalExistingPageFromString( $titleString );
	}

	public static function provideInvalidTitles(): Generator {
		$interwikiPrefix = 'en';
		$interwikiStr = "$interwikiPrefix:Something";
		yield 'Unexpected interwiki' => [
			$interwikiStr,
			UnexpectedInterwikiException::class,
			new TitleValue( NS_MAIN, 'Something', '', $interwikiPrefix )
		];

		$section = 'SomeSection';
		$sectionStr = "Something#$section";
		yield 'Unexpected section anchor' => [
			$sectionStr,
			UnexpectedSectionAnchorException::class,
			new TitleValue( NS_MAIN, 'Something', $section )
		];

		$specialStr = 'Special:Foobar';
		yield 'In the Special: namespace' => [
			$specialStr,
			UnexpectedVirtualNamespaceException::class,
			new TitleValue( NS_SPECIAL, $specialStr )
		];
	}
}
