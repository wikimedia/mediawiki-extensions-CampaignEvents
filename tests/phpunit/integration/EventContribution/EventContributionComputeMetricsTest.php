<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\EventContribution;

use InvalidArgumentException;
use MediaWiki\Content\Content;
use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContribution;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionComputeMetrics;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\MainConfigNames;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Page\PageIdentityValue;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Revision\RevisionStoreFactory;
use MediaWiki\Tests\MockWikiMapTrait;
use MediaWiki\Title\TitleFormatter;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use MockHttpTrait;
use MWHttpRequest;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * This would ideally be a unit test, but can't due to some global state usages.
 *
 * @group Test
 * @covers \MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionComputeMetrics
 */
class EventContributionComputeMetricsTest extends MediaWikiIntegrationTestCase {
	use MockWikiMapTrait;
	use MockHttpTrait;

	private function getComputeMetrics(
		RevisionStore $revisionStore,
		TitleFormatter $titleFormatter,
		array $extraSites = [],
		?MWHttpRequest $httpRequest = null,
	): EventContributionComputeMetrics {
		$revisionStoreFactory = $this->createMock( RevisionStoreFactory::class );
		$revisionStoreFactory->method( 'getRevisionStore' )->willReturn( $revisionStore );
		$this->setService( 'RevisionStoreFactory', $revisionStoreFactory );

		$this->setService( 'TitleFormatter', $titleFormatter );

		$this->mockWikiMap( 'https://example.com', $extraSites );

		$this->installMockHttp( $httpRequest );

		$centralUserLookup = $this->createMock( CampaignsCentralUserLookup::class );
		$centralUserLookup->method( 'getUserName' )->willReturn( 'Test username' );

		// Mock the services
		$this->setService( 'RevisionStoreFactory', $revisionStoreFactory );
		$this->setService( 'TitleFormatter', $titleFormatter );
		$this->setService( CampaignsCentralUserLookup::SERVICE_NAME, $centralUserLookup );

		return CampaignEventsServices::getEventContributionComputeMetrics();
	}

	/**
	 * @dataProvider provideComputeEventContribution
	 */
	public function testComputeEventContribution(
		int $revisionId,
		?int $parentId,
		int $revisionSize,
		?int $parentSize,
		int $expectedBytesDelta,
		int $expectedEditedType,
		int $expectedLinksDelta,
		bool $shouldThrow = false,
		string $expectedExceptionMessage = ''
	) {
		if ( $shouldThrow ) {
			$this->expectException( InvalidArgumentException::class );
			$this->expectExceptionMessage( $expectedExceptionMessage );
		}

		// Create mock revision
		$revision = $this->createMockRevision( $revisionId, $revisionSize, $parentId );
		$parentRevision = $parentSize !== null ? $this->createMockRevision( $parentId, $parentSize, null ) : null;

		$revisionStore = $this->createMock( RevisionStore::class );
		if ( $shouldThrow ) {
			$revisionStore->expects( $this->once() )
				->method( 'getRevisionById' )
				->with( $revisionId )
				->willReturn( null );
		} else {
			$revisionStore->expects( $this->exactly( $parentRevision ? 2 : 1 ) )
				->method( 'getRevisionById' )
				->willReturnMap( [
					[ $revisionId, 0, null, $revision ],
					[ $parentId, 0, null, $parentRevision ]
				] );
		}

		$titleFormatter = $this->createMock( TitleFormatter::class );
		// Mocking needed because there is no return type declaration on `getPrefixedText`.
		$titleFormatter->method( 'getPrefixedText' )->willReturn( 'Some string' );
		$computeMetrics = $this->getComputeMetrics( $revisionStore, $titleFormatter );
		$result = $computeMetrics->computeEventContribution(
			$revisionId,
			123,
			456,
			WikiMap::getCurrentWikiId()
		);

		if ( !$shouldThrow ) {
			$this->assertInstanceOf( EventContribution::class, $result );
			$this->assertEquals( $expectedBytesDelta, $result->getBytesDelta() );
			$this->assertSame( $expectedEditedType, $result->getEditFlags() );
			$this->assertSame( $expectedLinksDelta, $result->getLinksDelta() );
		}
	}

	public static function provideComputeEventContribution(): array {
		$testData = [
			'Revision not found' => [
				'revisionId' => 123,
				'parentId' => null,
				'revisionSize' => 0,
				'parentSize' => null,
				'expectedBytesDelta' => 0,
				'expectedEditedType' => 0,
				'expectedLinksDelta' => 0,
				'shouldThrow' => true,
				'expectedExceptionMessage' => 'Revision 123 not found'
			],
			'Valid revision with parent (edit)' => [
				'revisionId' => 123,
				'parentId' => 122,
				'revisionSize' => 1000,
				'parentSize' => 800,
				// 1000 - 800
				'expectedBytesDelta' => 200,
				// edited (has parent)
				'expectedEditedType' => 0,
				// parser error returns 0
				'expectedLinksDelta' => 0
			],
			'Valid revision without parent (creation)' => [
				'revisionId' => 123,
				'parentId' => null,
				'revisionSize' => 1000,
				'parentSize' => null,
				// 1000 - 0
				'expectedBytesDelta' => 1000,
				// created (no parent)
				'expectedEditedType' => 1,
				// parser error returns 0
				'expectedLinksDelta' => 0
			],
			'Bytes delta negative (content removed)' => [
				'revisionId' => 123,
				'parentId' => 122,
				'revisionSize' => 500,
				'parentSize' => 800,
				// 500 - 800
				'expectedBytesDelta' => -300,
				// edited
				'expectedEditedType' => 0,
				// parser error returns 0
				'expectedLinksDelta' => 0
			],
			'No content in revision' => [
				'revisionId' => 123,
				'parentId' => 122,
				'revisionSize' => 0,
				'parentSize' => 0,
				// 0 - 0
				'expectedBytesDelta' => 0,
				// edited
				'expectedEditedType' => 0,
				// parser error returns 0
				'expectedLinksDelta' => 0
			]
		];

		// Convert associative arrays to positional arrays for PHPUnit
		$result = [];
		foreach ( $testData as $name => $data ) {
			$result[$name] = [
				$data['revisionId'],
				$data['parentId'],
				$data['revisionSize'],
				$data['parentSize'],
				$data['expectedBytesDelta'],
				$data['expectedEditedType'],
				$data['expectedLinksDelta'],
				$data['shouldThrow'] ?? false,
				$data['expectedExceptionMessage'] ?? ''
			];
		}
		return $result;
	}

	/**
	 * Helper method to create a simple mock revision
	 */
	private function createMockRevision(
		int $id,
		int $size,
		?int $parentId,
		?PageIdentity $page = null
	): MockObject&RevisionRecord {
		$revision = $this->createMock( RevisionRecord::class );

		$revision->method( 'getId' )->willReturn( $id );
		$revision->method( 'getParentId' )->willReturn( $parentId );
		$revision->method( 'getSize' )->willReturn( $size );
		$revision->method( 'getWikiId' )->willReturn( false );
		$revision->method( 'getTimestamp' )->willReturn( '20240101120000' );

		$content = $this->createMock( Content::class );
		$content->method( 'getSize' )->willReturn( $size );
		$revision->method( 'getContent' )->willReturn( $content );

		$page ??= new PageIdentityValue( 123, NS_MAIN, 'TestPage', WikiAwareEntity::LOCAL );
		$revision->method( 'getPage' )->willReturn( $page );

		return $revision;
	}

	public function testGetPagePrefixedText__local() {
		$this->overrideConfigValue( MainConfigNames::LanguageCode, 'en' );
		$revID = 987;
		$localPage = new PageIdentityValue( 123, NS_HELP, 'I need somebody', WikiAwareEntity::LOCAL );
		$prefixedText = 'Help:I need somebody';

		$revisionStore = $this->createMock( RevisionStore::class );
		$revisionStore->method( 'getRevisionById' )
			->with( $revID )
			->willReturn( $this->createMockRevision( $revID, 1000, null, $localPage ) );
		$titleFormatter = $this->createMock( TitleFormatter::class );
		$titleFormatter->expects( $this->atLeastOnce() )
			->method( 'getPrefixedText' )
			->with( $localPage )
			->willReturn( $prefixedText );
		$computeMetrics = $this->getComputeMetrics( $revisionStore, $titleFormatter );

		$contrib = $computeMetrics->computeEventContribution( $revID, 456, 789, WikiMap::getCurrentWikiId() );
		$this->assertSame( $prefixedText, $contrib->getPagePrefixedtext() );
	}

	public function testGetPagePrefixedText__foreign() {
		$this->overrideConfigValue( MainConfigNames::LanguageCode, 'en' );
		$revID = 987;
		$otherWikiID = 'someotherwiki';
		$foreignPage = new PageIdentityValue( 123, 999999, 'Foreign title', $otherWikiID );
		$foreignPrefixedText = 'CustomNS:Foreign title';

		$revisionStore = $this->createMock( RevisionStore::class );
		$revisionStore->method( 'getRevisionById' )
			->with( $revID )
			->willReturn( $this->createMockRevision( $revID, 1000, null, $foreignPage ) );
		// TitleFormatter shouldn't be used for foreign pages.
		$titleFormatter = $this->createNoOpMock( TitleFormatter::class );
		$httpResp = [
			'query' => [
				'pages' => [
					[ 'title' => $foreignPrefixedText ]
				],
			]
		];
		$httpReq = $this->makeFakeHttpRequest( json_encode( $httpResp ) );

		$computeMetrics = $this->getComputeMetrics(
			$revisionStore,
			$titleFormatter,
			[ [ 'wikiId' => $otherWikiID, 'server' => 'https://whatever.example.com' ] ],
			$httpReq
		);

		$contrib = $computeMetrics->computeEventContribution( $revID, 456, 789, $otherWikiID );
		$this->assertSame( $foreignPrefixedText, $contrib->getPagePrefixedtext() );
	}
}
