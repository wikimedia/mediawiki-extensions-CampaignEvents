<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Worklist;

use MediaWiki\Extension\CampaignEvents\MWEntity\WikiLookup;
use MediaWiki\Extension\CampaignEvents\Worklist\WorklistContent;
use MediaWiki\Tests\Unit\DummyServicesTrait;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\CampaignEvents\Worklist\WorklistContent
 */
class WorklistContentTest extends MediaWikiIntegrationTestCase {
	use DummyServicesTrait;

	private const VALID_WIKI = 'some_valid_wiki';
	private const VALID_WIKI_OTHER = 'other_valid_wiki';
	private const INTERWIKI_PREFIX = 'other';

	protected function setUp(): void {
		parent::setUp();
		$this->setService( 'InterwikiLookup', $this->getDummyInterwikiLookup( [ self::INTERWIKI_PREFIX ] ) );
		$wikiLookup = $this->createMock( WikiLookup::class );
		$wikiLookup->method( 'getAllWikis' )
			->willReturn( [ WikiMap::getCurrentWikiId(), self::VALID_WIKI, self::VALID_WIKI_OTHER ] );
		$this->setService( WikiLookup::SERVICE_NAME, $wikiLookup );
	}

	/** @dataProvider provideValidate */
	public function testValidate( string $contentText, ?string $expectedError ) {
		$content = new WorklistContent( $contentText );
		$status = $content->validate();
		if ( $expectedError !== null ) {
			$this->assertStatusError( $expectedError, $status );
		} else {
			$this->assertStatusGood( $status );
		}
	}

	public static function provideValidate() {
		$e = json_encode( ... );

		yield 'Invalid json' => [ ',', 'json-error-syntax' ];
		yield 'Not an object' => [ '[]', 'campaignevents-worklist-content-not-object' ];
		yield 'Wiki does not exist' => [
			$e( [ 'wiki_does_not_exist_56484816434135' => [ 'Foo' ] ] ),
			'campaignevents-worklist-content-nonexistent-wiki'
		];
		yield 'Wiki value not an array' => [
			$e( [ self::VALID_WIKI => true ] ),
			'campaignevents-worklist-content-wiki-value-not-array'
		];
		yield 'Empty page list for a wiki' => [
			$e( [ self::VALID_WIKI => [] ] ),
			'campaignevents-worklist-content-wiki-empty'
		];
		yield 'Title is not a string' => [
			$e( [ self::VALID_WIKI => [ 1234 ] ] ),
			'campaignevents-worklist-content-title-not-string'
		];
		yield 'Duplicated title' => [
			$e( [ self::VALID_WIKI => [ 'A', 'B', 'A' ] ] ),
			'campaignevents-worklist-content-duplicated-title'
		];
		yield 'Title with interwiki prefix' => [
			$e( [ self::VALID_WIKI => [ self::INTERWIKI_PREFIX . ':Some Title' ] ] ),
			'campaignevents-worklist-content-title-with-interwiki'
		];
		yield 'Title with section fragment' => [
			$e( [ self::VALID_WIKI => [ 'Some Title#Section' ] ] ),
			'campaignevents-worklist-content-title-with-fragment'
		];
		yield 'Title is not in canonical form' => [
			$e( [ self::VALID_WIKI => [ 'some_title' ] ] ),
			'campaignevents-worklist-content-title-non-canonical'
		];
		yield 'Malformed title' => [
			$e( [ self::VALID_WIKI => [ '[|]' ] ] ),
			'campaignevents-worklist-content-invalid-title'
		];
	}

	public function testGetLocalLinkTargets() {
		$contentStructure = [
			self::VALID_WIKI => [
				'Title1',
				'Talk:Title2',
			],
			WikiMap::getCurrentWikiId() => [
				'User:Title3',
			],
			self::VALID_WIKI_OTHER => [
				'Title5',
			],
		];
		$content = new WorklistContent( json_encode( $contentStructure ) );

		$localLinks = $content->getLocalLinkTargets();
		$this->assertCount( 1, $localLinks );
		$link = $localLinks[0];
		$this->assertSame( 'Title3', $link->getText() );
		$this->assertSame( NS_USER, $link->getNamespace() );
	}

	public function testGetLocalLinkTargets__none() {
		$curWikiID = WikiMap::getCurrentWikiId();
		$contentStructure = [
			"not-$curWikiID" => [
				'Title1',
				'Talk:Title2',
			],
		];
		$content = new WorklistContent( json_encode( $contentStructure ) );

		$this->assertSame( [], $content->getLocalLinkTargets() );
	}
}
