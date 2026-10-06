<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Worklist;

use Exception;
use Generator;
use MediaWiki\Extension\CampaignEvents\Worklist\IWorklistPagesLookup;
use MediaWiki\Extension\CampaignEvents\Worklist\WorklistArticleHelper;
use MediaWiki\Extension\CampaignEvents\Worklist\WorklistSecondaryStore;
use MediaWikiIntegrationTestCase;

/**
 * Base class for testing implementations of IWorklistPagesLookup
 */
abstract class WorklistPagesLookupTestBase extends MediaWikiIntegrationTestCase {
	abstract protected function getLookup(): IWorklistPagesLookup;

	protected const WORKLIST_ID = 1;

	private const WORKLIST_CONTENT = [
		'awiki' => [
			'Page 1',
			'Page 2',
			'Page 3',
			'Page 4',
			'Page 5',
			'Page 6',
			'Page 99',
		],
		'bwiki' => [
			'Page 1',
			'Page 4',
			'Page 100',
			'Page 101',
			'Page 102',
		],
		'zwiki' => [
			'Page 1',
			'Page 200',
		]
	];

	public function addDBDataOnce() {
		$db = $this->getDb();
		$baseRow = [
			'cewp_cew_id' => self::WORKLIST_ID,
			'cewp_user_id' => 1,
		];

		$rowExtras = [
			[
				'cewp_wiki' => 'awiki',
				'cewp_page_prefixedtext' => 'Page 1',
				'cewp_timestamp' => $db->timestamp( 1 ),
			],
			[
				'cewp_wiki' => 'awiki',
				'cewp_page_prefixedtext' => 'Page 2',
				'cewp_timestamp' => $db->timestamp( 1 ),
			],
			[
				'cewp_wiki' => 'bwiki',
				'cewp_page_prefixedtext' => 'Page 1',
				'cewp_timestamp' => $db->timestamp( 1 ),
			],
			[
				'cewp_wiki' => 'bwiki',
				'cewp_page_prefixedtext' => 'Page 4',
				'cewp_timestamp' => $db->timestamp( 15 ),
			],
			[
				'cewp_wiki' => 'awiki',
				'cewp_page_prefixedtext' => 'Page 3',
				'cewp_timestamp' => $db->timestamp( 5 ),
			],
			[
				'cewp_wiki' => 'awiki',
				'cewp_page_prefixedtext' => 'Page 6',
				'cewp_timestamp' => $db->timestamp( 20 ),
			],
			[
				'cewp_wiki' => 'bwiki',
				'cewp_page_prefixedtext' => 'Page 101',
				'cewp_timestamp' => $db->timestamp( 10 ),
			],
		];
		$rows = array_map( static fn ( array $extra ): array => $extra + $baseRow, $rowExtras );
		$db->newInsertQueryBuilder()
			->insertInto( 'ce_worklist_pages' )
			->rows( $rows )
			->caller( __METHOD__ )
			->execute();
	}

	protected function setUp(): void {
		$worklistSecondaryStore = $this->createMock( WorklistSecondaryStore::class );
		$worklistSecondaryStore->method( 'getWorklistIDFromPage' )->willReturn( self::WORKLIST_ID );
		$this->setService( WorklistSecondaryStore::SERVICE_NAME, $worklistSecondaryStore );

		$worklistHelper = $this->createMock( WorklistArticleHelper::class );
		$worklistHelper->method( 'getRawWorklistContentCached' )->willReturn( self::WORKLIST_CONTENT );
		$this->setService( WorklistArticleHelper::SERVICE_NAME, $worklistHelper );
	}

	/** @dataProvider provideGetWorklistPages */
	public function testGetWorklistPages(
		string $sort,
		int $limit,
		?string $offset,
		bool $direction,
		bool $isBackwards,
		array $expectedResult,
		array $expectedOffsets,
	) {
		$lookup = $this->getLookup();
		$this->assertArrayEquals(
			[
				'result' => $expectedResult,
				'paginationOffsets' => $expectedOffsets,
			],
			$lookup->getWorklistPages( $sort, $limit, $offset, $direction, $isBackwards ),
			false,
			true
		);
	}

	abstract public static function provideGetWorklistPages(): Generator;

	/** @dataProvider provideInvalidLimit */
	public function testInvalidLimit( int $limit ) {
		$lookup = $this->getLookup();
		// Keep this generic, as implementation might use different exception classes.
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'limit' );
		$lookup->getWorklistPages(
			IWorklistPagesLookup::SORT_TIMESTAMP,
			$limit,
			null,
			IWorklistPagesLookup::DIR_ASCENDING,
			IWorklistPagesLookup::NOT_BACKWARDS,
		);
	}

	public static function provideInvalidLimit(): Generator {
		yield 'Zero' => [ 0 ];
		yield 'Negative' => [ -1 ];
	}

	/** @dataProvider provideInvalidOffsets */
	public function testInvalidOffsetsAreDiscarded( string $offset, ?string $expectedCur ) {
		$sort = IWorklistPagesLookup::SORT_TIMESTAMP;
		$limit = 5;
		$dir = IWorklistPagesLookup::DIR_ASCENDING;
		$backwards = IWorklistPagesLookup::NOT_BACKWARDS;

		$lookup = $this->getLookup();
		// Call the lookup without the offset to get the expected result, regardless of the implementation.
		$baseline = $lookup->getWorklistPages( $sort, $limit, null, $dir, $backwards );
		// Adjust expected result for the presence of an offset.
		$expected = $baseline;
		$expected['paginationOffsets']['cur'] = $expectedCur;

		$this->assertSame( $expected, $lookup->getWorklistPages( $sort, $limit, $offset, $dir, $backwards ) );
	}

	public static function provideInvalidOffsets(): Generator {
		yield 'Empty' => [ '', null ];
		$manyFields = '||||||||||||||||||||';
		yield 'Lots of fields' => [ $manyFields, $manyFields ];
	}
}
