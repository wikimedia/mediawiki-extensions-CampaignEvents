<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\EventContribution;

use InvalidArgumentException;
use MediaWiki\Content\Content;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContribution;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionComputeMetrics;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Revision\RevisionStoreFactory;
use MediaWiki\Title\TitleFormatter;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * @group Test
 * @covers \MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionComputeMetrics
 */
class EventContributionComputeMetricsTest extends MediaWikiIntegrationTestCase {
	private EventContributionComputeMetrics $computeMetrics;
	private MockObject $revisionStore;
	private MockObject $revisionStoreFactory;
	private MockObject $titleFormatter;

	protected function setUp(): void {
		parent::setUp();

		$this->revisionStore = $this->createMock( RevisionStore::class );
		$this->revisionStoreFactory = $this->createMock( RevisionStoreFactory::class );
		$this->revisionStoreFactory->method( 'getRevisionStore' )->willReturn( $this->revisionStore );

		$this->titleFormatter = $this->createMock( TitleFormatter::class );
		$this->titleFormatter->method( 'getPrefixedText' )->willReturn( 'TestPage' );

		// Mock the services
		$this->setService( 'RevisionStoreFactory', $this->revisionStoreFactory );
		$this->setService( 'TitleFormatter', $this->titleFormatter );

		// Get the service through the proper DI system
		$this->computeMetrics = CampaignEventsServices::getEventContributionComputeMetrics();
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

		if ( $shouldThrow ) {
			$this->revisionStore->expects( $this->once() )
				->method( 'getRevisionById' )
				->with( $revisionId )
				->willReturn( null );
		} else {
			$this->revisionStore->expects( $this->exactly( 2 ) )
				->method( 'getRevisionById' )
				->willReturnMap( [
					[ $revisionId, 0, null, $revision ],
					[ $parentId, 0, null, $parentRevision ]
				] );
		}

		$result = $this->computeMetrics->computeEventContribution(
			$revisionId,
			123,
			456,
			WikiMap::getCurrentWikiId(),
			'20240101120000'
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
	private function createMockRevision( int $id, int $size, ?int $parentId ): MockObject {
		$revision = $this->createMock( RevisionRecord::class );

		$revision->method( 'getId' )->willReturn( $id );
		$revision->method( 'getParentId' )->willReturn( $parentId );
		$revision->method( 'getSize' )->willReturn( $size );
		$revision->method( 'getWikiId' )->willReturn( false );
		$revision->method( 'getTimestamp' )->willReturn( '20240101120000' );

		$content = $this->createMock( Content::class );
		$content->method( 'getSize' )->willReturn( $size );
		$revision->method( 'getContent' )->willReturn( $content );

		$page = $this->createMock( PageIdentity::class );
		$page->method( 'getNamespace' )->willReturn( 0 );
		$page->method( 'getDBkey' )->willReturn( 'TestPage' );
		$page->method( 'getId' )->willReturn( 123 );
		$revision->method( 'getPage' )->willReturn( $page );

		return $revision;
	}
}
