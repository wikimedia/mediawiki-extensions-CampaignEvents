<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Worklist;

use Generator;
use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\Worklist\IWorklistPagesLookup as IWL;
use MediaWiki\Extension\CampaignEvents\Worklist\WikiPageBasedWorklistPagesLookup;
use MediaWiki\Extension\CampaignEvents\Worklist\WorklistArticleHelper;
use MediaWiki\Extension\CampaignEvents\Worklist\WorklistPagesSecondaryStore;
use MediaWiki\Extension\CampaignEvents\Worklist\WorklistSecondaryStore;
use MediaWiki\Page\PageIdentityValue;

/**
 * @covers \MediaWiki\Extension\CampaignEvents\Worklist\WikiPageBasedWorklistPagesLookup
 * @group Database
 */
class WikiPageBasedWorklistPagesLookupTest extends WorklistPagesLookupTestBase {
	protected function getLookup(): WikiPageBasedWorklistPagesLookup {
		$services = $this->getServiceContainer();
		return new WikiPageBasedWorklistPagesLookup(
			$services->get( WorklistArticleHelper::SERVICE_NAME ),
			$services->get( WorklistSecondaryStore::SERVICE_NAME ),
			$services->get( WorklistPagesSecondaryStore::SERVICE_NAME ),
			$services->get( CampaignsDatabaseHelper::SERVICE_NAME ),
			new PageIdentityValue( 123, NS_MAIN, 'Test_worklist', WikiAwareEntity::LOCAL ),
		);
	}

	public function testGetWorklistPages__noWorklistContent() {
		$worklistArticleHelper = $this->createMock( WorklistArticleHelper::class );
		$worklistArticleHelper->method( 'getRawWorklistContentCached' )->willReturn( null );
		$this->setService( WorklistArticleHelper::SERVICE_NAME, $worklistArticleHelper );

		$lookup = $this->getLookup();
		$this->assertSame(
			[ 'result' => [], 'paginationOffsets' => [] ],
			$lookup->getWorklistPages(
				IWL::SORT_TIMESTAMP,
				12345,
				null,
				IWL::DIR_ASCENDING,
				IWL::NOT_BACKWARDS
			),
		);
	}

	public function testGetWorklistPages__noWorklistDatabaseRecord() {
		$worklistSecondaryStore = $this->createMock( WorklistSecondaryStore::class );
		$worklistSecondaryStore->method( 'getWorklistIDFromPage' )->willReturn( null );
		$this->setService( WorklistSecondaryStore::SERVICE_NAME, $worklistSecondaryStore );

		$results = $this->getLookup()->getWorklistPages(
			IWL::SORT_TIMESTAMP,
			100_000,
			null,
			IWL::DIR_ASCENDING,
			IWL::NOT_BACKWARDS
		);

		$this->assertSame(
			[
				'result' => [
					[
						'wiki' => 'awiki',
						'prefixedtext' => 'Page 1',
						'timestamp' => null,
					],
					[
						'wiki' => 'awiki',
						'prefixedtext' => 'Page 2',
						'timestamp' => null,
					],
					[
						'wiki' => 'awiki',
						'prefixedtext' => 'Page 3',
						'timestamp' => null,
					],
					[
						'wiki' => 'awiki',
						'prefixedtext' => 'Page 4',
						'timestamp' => null,
					],
					[
						'wiki' => 'awiki',
						'prefixedtext' => 'Page 5',
						'timestamp' => null,
					],
					[
						'wiki' => 'awiki',
						'prefixedtext' => 'Page 6',
						'timestamp' => null,
					],
					[
						'wiki' => 'awiki',
						'prefixedtext' => 'Page 99',
						'timestamp' => null,
					],
					[
						'wiki' => 'bwiki',
						'prefixedtext' => 'Page 1',
						'timestamp' => null,
					],
					[
						'wiki' => 'bwiki',
						'prefixedtext' => 'Page 4',
						'timestamp' => null,
					],
					[
						'wiki' => 'bwiki',
						'prefixedtext' => 'Page 100',
						'timestamp' => null,
					],
					[
						'wiki' => 'bwiki',
						'prefixedtext' => 'Page 101',
						'timestamp' => null,
					],
					[
						'wiki' => 'bwiki',
						'prefixedtext' => 'Page 102',
						'timestamp' => null,
					],
					[
						'wiki' => 'zwiki',
						'prefixedtext' => 'Page 1',
						'timestamp' => null,
					],
					[
						'wiki' => 'zwiki',
						'prefixedtext' => 'Page 200',
						'timestamp' => null,
					],
				],
				'paginationOffsets' => [
					IWL::PAGINATION_CUR => null,
				],
			],
			$results,
		);
	}

	public static function provideGetWorklistPages(): Generator {
		yield 'Timestamp descending, first page, missing rows only' => [
			IWL::SORT_TIMESTAMP,
			5,
			null,
			IWL::DIR_DESCENDING,
			IWL::NOT_BACKWARDS,
			[
				[
					'wiki' => 'zwiki',
					'prefixedtext' => 'Page 200',
					'timestamp' => null,
				],
				[
					'wiki' => 'zwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => null,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 102',
					'timestamp' => null,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 100',
					'timestamp' => null,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 99',
					'timestamp' => null,
				],
			],
			[
				IWL::PAGINATION_CUR => null,
				IWL::PAGINATION_NEXT => '|7',
				IWL::PAGINATION_LAST => null,
			],
		];
		yield 'Timestamp descending, second page after missing rows' => [
			IWL::SORT_TIMESTAMP,
			5,
			'|7',
			IWL::DIR_DESCENDING,
			IWL::NOT_BACKWARDS,
			[
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 5',
					'timestamp' => null,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => null,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 6',
					'timestamp' => '20',
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => '15',
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 101',
					'timestamp' => '10',
				],
			],
			[
				IWL::PAGINATION_CUR => '|7',
				IWL::PAGINATION_PREV => '|5',
				IWL::PAGINATION_NEXT => '10|11',
				IWL::PAGINATION_FIRST => null,
				IWL::PAGINATION_LAST => null,
			],
		];
		yield 'Timestamp descending, first page, both page-only and augmented entries' => [
			IWL::SORT_TIMESTAMP,
			10,
			null,
			IWL::DIR_DESCENDING,
			IWL::NOT_BACKWARDS,
			[
				[
					'wiki' => 'zwiki',
					'prefixedtext' => 'Page 200',
					'timestamp' => null,
				],
				[
					'wiki' => 'zwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => null,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 102',
					'timestamp' => null,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 100',
					'timestamp' => null,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 99',
					'timestamp' => null,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 5',
					'timestamp' => null,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => null,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 6',
					'timestamp' => '20',
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => '15',
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 101',
					'timestamp' => '10',
				],
			],
			[
				IWL::PAGINATION_CUR => null,
				IWL::PAGINATION_NEXT => '10|11',
				IWL::PAGINATION_LAST => null,
			],
		];
		yield 'Timestamp descending, second page after augmented entries' => [
			IWL::SORT_TIMESTAMP,
			10,
			'10|11',
			IWL::DIR_DESCENDING,
			IWL::NOT_BACKWARDS,
			[
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 3',
					'timestamp' => '5',
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => '1',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 2',
					'timestamp' => '1',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => '1',
				],
			],
			[
				IWL::PAGINATION_CUR => '10|11',
				IWL::PAGINATION_PREV => '5|3',
				IWL::PAGINATION_FIRST => null,
			],
		];
		yield 'Timestamp descending, first page with offset before' => [
			IWL::SORT_TIMESTAMP,
			5,
			'|9999999999999',
			IWL::DIR_DESCENDING,
			IWL::NOT_BACKWARDS,
			[
				[
					'wiki' => 'zwiki',
					'prefixedtext' => 'Page 200',
					'timestamp' => null,
				],
				[
					'wiki' => 'zwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => null,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 102',
					'timestamp' => null,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 100',
					'timestamp' => null,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 99',
					'timestamp' => null,
				],
			],
			[
				IWL::PAGINATION_CUR => '|9999999999999',
				IWL::PAGINATION_NEXT => '|7',
				IWL::PAGINATION_LAST => null,
			],
		];
		yield 'Timestamp descending, last page, augmented items only' => [
			IWL::SORT_TIMESTAMP,
			4,
			null,
			IWL::DIR_DESCENDING,
			IWL::BACKWARDS,
			[
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 3',
					'timestamp' => '5',
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => '1',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 2',
					'timestamp' => '1',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => '1',
				],
			],
			[
				IWL::PAGINATION_CUR => '10|11',
				IWL::PAGINATION_PREV => '5|3',
				IWL::PAGINATION_FIRST => null,
			],
		];
		yield 'Timestamp descending, penultimate page before augmented items only' => [
			IWL::SORT_TIMESTAMP,
			4,
			'5|3',
			IWL::DIR_DESCENDING,
			IWL::BACKWARDS,
			[
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => null,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 6',
					'timestamp' => '20',
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => '15',
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 101',
					'timestamp' => '10',
				],
			],
			[
				IWL::PAGINATION_CUR => '|5',
				IWL::PAGINATION_PREV => '|4',
				IWL::PAGINATION_NEXT => '10|11',
				IWL::PAGINATION_FIRST => null,
				IWL::PAGINATION_LAST => null,
			],
		];
		yield 'Timestamp descending, last page, both page-only and augmented entries' => [
			IWL::SORT_TIMESTAMP,
			10,
			null,
			IWL::DIR_DESCENDING,
			IWL::BACKWARDS,
			[
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 99',
					'timestamp' => null,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 5',
					'timestamp' => null,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => null,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 6',
					'timestamp' => '20',
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => '15',
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 101',
					'timestamp' => '10',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 3',
					'timestamp' => '5',
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => '1',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 2',
					'timestamp' => '1',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => '1',
				],
			],
			[
				IWL::PAGINATION_CUR => '|10',
				IWL::PAGINATION_PREV => '|7',
				IWL::PAGINATION_FIRST => null,
			],
		];
		yield 'Timestamp descending, penultimate page before page-only items' => [
			IWL::SORT_TIMESTAMP,
			10,
			'|7',
			IWL::DIR_DESCENDING,
			IWL::BACKWARDS,
			[
				[
					'wiki' => 'zwiki',
					'prefixedtext' => 'Page 200',
					'timestamp' => null,
				],
				[
					'wiki' => 'zwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => null,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 102',
					'timestamp' => null,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 100',
					'timestamp' => null,
				],
			],
			[
				IWL::PAGINATION_CUR => null,
				IWL::PAGINATION_NEXT => '|10',
				IWL::PAGINATION_LAST => null,
			],
		];
		yield 'Timestamp descending, last page with offset after' => [
			IWL::SORT_TIMESTAMP,
			4,
			'-1|-1',
			IWL::DIR_DESCENDING,
			IWL::BACKWARDS,
			[
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 3',
					'timestamp' => '5',
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => '1',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 2',
					'timestamp' => '1',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => '1',
				],
			],
			[
				IWL::PAGINATION_CUR => '10|11',
				IWL::PAGINATION_PREV => '5|3',
				IWL::PAGINATION_FIRST => null,
			],
		];
		yield 'Timestamp descending, all in one page' => [
			IWL::SORT_TIMESTAMP,
			100_000,
			null,
			IWL::DIR_DESCENDING,
			IWL::NOT_BACKWARDS,
			[
				[
					'wiki' => 'zwiki',
					'prefixedtext' => 'Page 200',
					'timestamp' => null,
				],
				[
					'wiki' => 'zwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => null,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 102',
					'timestamp' => null,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 100',
					'timestamp' => null,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 99',
					'timestamp' => null,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 5',
					'timestamp' => null,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => null,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 6',
					'timestamp' => '20',
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => '15',
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 101',
					'timestamp' => '10',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 3',
					'timestamp' => '5',
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => '1',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 2',
					'timestamp' => '1',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => '1',
				],
			],
			[
				IWL::PAGINATION_CUR => null,
			],
		];
		yield 'Timestamp descending, not backwards, offset after last page' => [
			IWL::SORT_TIMESTAMP,
			5,
			'-1|-1',
			IWL::DIR_DESCENDING,
			IWL::NOT_BACKWARDS,
			[],
			[
				IWL::PAGINATION_CUR => '-1|-1',
				IWL::PAGINATION_PREV => null,
				IWL::PAGINATION_FIRST => null,
			],
		];
		yield 'Timestamp descending, backwards, offset before first page' => [
			IWL::SORT_TIMESTAMP,
			5,
			'|99999999',
			IWL::DIR_DESCENDING,
			IWL::BACKWARDS,
			[],
			[
				IWL::PAGINATION_CUR => null,
				IWL::PAGINATION_NEXT => null,
				IWL::PAGINATION_LAST => null,
			],
		];

		yield 'Timestamp ascending, first page, augmented rows only' => [
			IWL::SORT_TIMESTAMP,
			4,
			null,
			IWL::DIR_ASCENDING,
			IWL::NOT_BACKWARDS,
			[
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => '1',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 2',
					'timestamp' => '1',
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => '1',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 3',
					'timestamp' => '5',
				],
			],
			[
				IWL::PAGINATION_CUR => null,
				IWL::PAGINATION_NEXT => '5|3',
				IWL::PAGINATION_LAST => null,
			],
		];
		yield 'Timestamp ascending, second page after augmented rows' => [
			IWL::SORT_TIMESTAMP,
			4,
			'5|3',
			IWL::DIR_ASCENDING,
			IWL::NOT_BACKWARDS,
			[
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 101',
					'timestamp' => '10',
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => '15',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 6',
					'timestamp' => '20',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => null,
				],
			],
			[
				IWL::PAGINATION_CUR => '5|3',
				IWL::PAGINATION_PREV => '10|11',
				IWL::PAGINATION_NEXT => '|4',
				IWL::PAGINATION_FIRST => null,
				IWL::PAGINATION_LAST => null,
			],
		];
		yield 'Timestamp ascending, first page, both augmented and page-only entries' => [
			IWL::SORT_TIMESTAMP,
			10,
			null,
			IWL::DIR_ASCENDING,
			IWL::NOT_BACKWARDS,
			[
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => '1',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 2',
					'timestamp' => '1',
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => '1',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 3',
					'timestamp' => '5',
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 101',
					'timestamp' => '10',
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => '15',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 6',
					'timestamp' => '20',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => null,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 5',
					'timestamp' => null,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 99',
					'timestamp' => null,
				],
			],
			[
				IWL::PAGINATION_CUR => null,
				IWL::PAGINATION_NEXT => '|7',
				IWL::PAGINATION_LAST => null,
			],
		];
		yield 'Timestamp ascending, second page after page-only entries' => [
			IWL::SORT_TIMESTAMP,
			10,
			'|7',
			IWL::DIR_ASCENDING,
			IWL::NOT_BACKWARDS,
			[
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 100',
					'timestamp' => null,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 102',
					'timestamp' => null,
				],
				[
					'wiki' => 'zwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => null,
				],
				[
					'wiki' => 'zwiki',
					'prefixedtext' => 'Page 200',
					'timestamp' => null,
				],
			],
			[
				IWL::PAGINATION_CUR => '|7',
				IWL::PAGINATION_PREV => '|10',
				IWL::PAGINATION_FIRST => null,
			],
		];
		yield 'Timestamp ascending, first page with offset before' => [
			IWL::SORT_TIMESTAMP,
			4,
			'-1|-1',
			IWL::DIR_ASCENDING,
			IWL::NOT_BACKWARDS,
			[
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => '1',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 2',
					'timestamp' => '1',
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => '1',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 3',
					'timestamp' => '5',
				],
			],
			[
				IWL::PAGINATION_CUR => '-1|-1',
				IWL::PAGINATION_NEXT => '5|3',
				IWL::PAGINATION_LAST => null,
			],
		];
		yield 'Timestamp ascending, last page, missing rows only' => [
			IWL::SORT_TIMESTAMP,
			3,
			null,
			IWL::DIR_ASCENDING,
			IWL::BACKWARDS,
			[
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 102',
					'timestamp' => null,
				],
				[
					'wiki' => 'zwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => null,
				],
				[
					'wiki' => 'zwiki',
					'prefixedtext' => 'Page 200',
					'timestamp' => null,
				],
			],
			[
				IWL::PAGINATION_CUR => '|10',
				IWL::PAGINATION_PREV => '|12',
				IWL::PAGINATION_FIRST => null,
			],
		];
		yield 'Timestamp ascending, penultimate page before missing rows' => [
			IWL::SORT_TIMESTAMP,
			3,
			'|12',
			IWL::DIR_ASCENDING,
			IWL::BACKWARDS,
			[
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 5',
					'timestamp' => null,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 99',
					'timestamp' => null,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 100',
					'timestamp' => null,
				],
			],
			[
				IWL::PAGINATION_CUR => '|4',
				IWL::PAGINATION_PREV => '|5',
				IWL::PAGINATION_NEXT => '|10',
				IWL::PAGINATION_FIRST => null,
				IWL::PAGINATION_LAST => null,
			],
		];
		yield 'Timestamp ascending, last page, both page-only and augmented entries' => [
			IWL::SORT_TIMESTAMP,
			10,
			null,
			IWL::DIR_ASCENDING,
			IWL::BACKWARDS,
			[
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 101',
					'timestamp' => '10',
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => '15',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 6',
					'timestamp' => '20',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => null,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 5',
					'timestamp' => null,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 99',
					'timestamp' => null,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 100',
					'timestamp' => null,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 102',
					'timestamp' => null,
				],
				[
					'wiki' => 'zwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => null,
				],
				[
					'wiki' => 'zwiki',
					'prefixedtext' => 'Page 200',
					'timestamp' => null,
				],
			],
			[
				IWL::PAGINATION_CUR => '5|3',
				IWL::PAGINATION_PREV => '10|11',
				IWL::PAGINATION_FIRST => null,
			],
		];
		yield 'Timestamp ascending, penultimate page before augmented entries' => [
			IWL::SORT_TIMESTAMP,
			10,
			'10|11',
			IWL::DIR_ASCENDING,
			IWL::BACKWARDS,
			[
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => '1',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 2',
					'timestamp' => '1',
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => '1',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 3',
					'timestamp' => '5',
				],
			],
			[
				IWL::PAGINATION_CUR => null,
				IWL::PAGINATION_NEXT => '5|3',
				IWL::PAGINATION_LAST => null,
			],
		];
		yield 'Timestamp ascending, last page with offset after' => [
			IWL::SORT_TIMESTAMP,
			3,
			'|99999999',
			IWL::DIR_ASCENDING,
			IWL::BACKWARDS,
			[
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 102',
					'timestamp' => null,
				],
				[
					'wiki' => 'zwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => null,
				],
				[
					'wiki' => 'zwiki',
					'prefixedtext' => 'Page 200',
					'timestamp' => null,
				],
			],
			[
				IWL::PAGINATION_CUR => '|10',
				IWL::PAGINATION_PREV => '|12',
				IWL::PAGINATION_FIRST => null,
			],
		];
		yield 'Timestamp ascending, all in one page' => [
			IWL::SORT_TIMESTAMP,
			100_000,
			null,
			IWL::DIR_ASCENDING,
			IWL::NOT_BACKWARDS,
			[
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => '1',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 2',
					'timestamp' => '1',
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => '1',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 3',
					'timestamp' => '5',
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 101',
					'timestamp' => '10',
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => '15',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 6',
					'timestamp' => '20',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => null,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 5',
					'timestamp' => null,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 99',
					'timestamp' => null,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 100',
					'timestamp' => null,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 102',
					'timestamp' => null,
				],
				[
					'wiki' => 'zwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => null,
				],
				[
					'wiki' => 'zwiki',
					'prefixedtext' => 'Page 200',
					'timestamp' => null,
				],
			],
			[
				IWL::PAGINATION_CUR => null,
			],
		];
		yield 'Timestamp ascending, not backwards, offset after last page' => [
			IWL::SORT_TIMESTAMP,
			5,
			'|99999999',
			IWL::DIR_ASCENDING,
			IWL::NOT_BACKWARDS,
			[],
			[
				IWL::PAGINATION_CUR => '|99999999',
				IWL::PAGINATION_PREV => null,
				IWL::PAGINATION_FIRST => null,
			],
		];
		yield 'Timestamp ascending, backwards, offset before first page' => [
			IWL::SORT_TIMESTAMP,
			5,
			'-1|-1',
			IWL::DIR_ASCENDING,
			IWL::BACKWARDS,
			[],
			[
				IWL::PAGINATION_CUR => null,
				IWL::PAGINATION_NEXT => null,
				IWL::PAGINATION_LAST => null,
			],
		];

		yield 'Title ascending, first page' => [
			IWL::SORT_TITLE,
			7,
			null,
			IWL::DIR_ASCENDING,
			IWL::NOT_BACKWARDS,
			[
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => '1',
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => '1',
				],
				[
					'wiki' => 'zwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => null,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 2',
					'timestamp' => '1',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 3',
					'timestamp' => '5',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => null,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => '15',
				],
			],
			[
				IWL::PAGINATION_CUR => null,
				IWL::PAGINATION_NEXT => 'Page 4|bwiki',
				IWL::PAGINATION_LAST => null,
			],
		];
		yield 'Title ascending, second page' => [
			IWL::SORT_TITLE,
			7,
			'Page 4|bwiki',
			IWL::DIR_ASCENDING,
			IWL::NOT_BACKWARDS,
			[
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 5',
					'timestamp' => null,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 6',
					'timestamp' => '20',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 99',
					'timestamp' => null,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 100',
					'timestamp' => null,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 101',
					'timestamp' => '10',
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 102',
					'timestamp' => null,
				],
				[
					'wiki' => 'zwiki',
					'prefixedtext' => 'Page 200',
					'timestamp' => null,
				],
			],
			[
				IWL::PAGINATION_CUR => 'Page 4|bwiki',
				IWL::PAGINATION_PREV => 'Page 5|awiki',
				IWL::PAGINATION_FIRST => null,
			],
		];
		yield 'Title ascending, first page with offset before' => [
			IWL::SORT_TITLE,
			3,
			'Page 0|aaaawiki',
			IWL::DIR_ASCENDING,
			IWL::NOT_BACKWARDS,
			[
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => '1',
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => '1',
				],
				[
					'wiki' => 'zwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => null,
				],
			],
			[
				IWL::PAGINATION_CUR => 'Page 0|aaaawiki',
				IWL::PAGINATION_NEXT => 'Page 1|zwiki',
				IWL::PAGINATION_LAST => null,
			],
		];
		yield 'Title ascending, last page' => [
			IWL::SORT_TITLE,
			5,
			null,
			IWL::DIR_ASCENDING,
			IWL::BACKWARDS,
			[
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 99',
					'timestamp' => null,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 100',
					'timestamp' => null,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 101',
					'timestamp' => '10',
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 102',
					'timestamp' => null,
				],
				[
					'wiki' => 'zwiki',
					'prefixedtext' => 'Page 200',
					'timestamp' => null,
				],
			],
			[
				IWL::PAGINATION_CUR => 'Page 6|awiki',
				IWL::PAGINATION_PREV => 'Page 99|awiki',
				IWL::PAGINATION_FIRST => null,
			],
		];
		yield 'Title ascending, penultimate page' => [
			IWL::SORT_TITLE,
			5,
			'Page 99|awiki',
			IWL::DIR_ASCENDING,
			IWL::BACKWARDS,
			[
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 3',
					'timestamp' => '5',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => null,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => '15',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 5',
					'timestamp' => null,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 6',
					'timestamp' => '20',
				],
			],
			[
				IWL::PAGINATION_CUR => 'Page 2|awiki',
				IWL::PAGINATION_PREV => 'Page 3|awiki',
				IWL::PAGINATION_NEXT => 'Page 6|awiki',
				IWL::PAGINATION_FIRST => null,
				IWL::PAGINATION_LAST => null,
			],
		];
		yield 'Title ascending, last page with offset after' => [
			IWL::SORT_TITLE,
			3,
			'Page 999999|zzzzwiki',
			IWL::DIR_ASCENDING,
			IWL::BACKWARDS,
			[
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 101',
					'timestamp' => '10',
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 102',
					'timestamp' => null,
				],
				[
					'wiki' => 'zwiki',
					'prefixedtext' => 'Page 200',
					'timestamp' => null,
				],
			],
			[
				IWL::PAGINATION_CUR => 'Page 100|bwiki',
				IWL::PAGINATION_PREV => 'Page 101|bwiki',
				IWL::PAGINATION_FIRST => null,
			],
		];
		yield 'Title ascending, all in one page' => [
			IWL::SORT_TITLE,
			100_000,
			null,
			IWL::DIR_ASCENDING,
			IWL::NOT_BACKWARDS,
			[
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => '1',
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => '1',
				],
				[
					'wiki' => 'zwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => null,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 2',
					'timestamp' => '1',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 3',
					'timestamp' => '5',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => null,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => '15',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 5',
					'timestamp' => null,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 6',
					'timestamp' => '20',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 99',
					'timestamp' => null,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 100',
					'timestamp' => null,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 101',
					'timestamp' => '10',
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 102',
					'timestamp' => null,
				],
				[
					'wiki' => 'zwiki',
					'prefixedtext' => 'Page 200',
					'timestamp' => null,
				],
			],
			[
				IWL::PAGINATION_CUR => null,
			],
		];
		yield 'Title ascending, not backwards, offset after last page' => [
			IWL::SORT_TITLE,
			5,
			'Page 999999|zzzzwiki',
			IWL::DIR_ASCENDING,
			IWL::NOT_BACKWARDS,
			[],
			[
				IWL::PAGINATION_CUR => 'Page 999999|zzzzwiki',
				IWL::PAGINATION_PREV => null,
				IWL::PAGINATION_FIRST => null,
			],
		];
		yield 'Title ascending, backwards, offset before first page' => [
			IWL::SORT_TITLE,
			5,
			'Page 0|aaaawiki',
			IWL::DIR_ASCENDING,
			IWL::BACKWARDS,
			[],
			[
				IWL::PAGINATION_CUR => null,
				IWL::PAGINATION_NEXT => null,
				IWL::PAGINATION_LAST => null,
			],
		];

		yield 'Title descending, first page' => [
			IWL::SORT_TITLE,
			5,
			null,
			IWL::DIR_DESCENDING,
			IWL::NOT_BACKWARDS,
			[
				[
					'wiki' => 'zwiki',
					'prefixedtext' => 'Page 200',
					'timestamp' => null,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 102',
					'timestamp' => null,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 101',
					'timestamp' => '10',
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 100',
					'timestamp' => null,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 99',
					'timestamp' => null,
				],
			],
			[
				IWL::PAGINATION_CUR => null,
				IWL::PAGINATION_NEXT => 'Page 99|awiki',
				IWL::PAGINATION_LAST => null,
			],
		];
		yield 'Title descending, second page' => [
			IWL::SORT_TITLE,
			5,
			'Page 99|awiki',
			IWL::DIR_DESCENDING,
			IWL::NOT_BACKWARDS,
			[
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 6',
					'timestamp' => '20',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 5',
					'timestamp' => null,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => '15',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => null,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 3',
					'timestamp' => '5',
				],
			],
			[
				IWL::PAGINATION_CUR => 'Page 99|awiki',
				IWL::PAGINATION_PREV => 'Page 6|awiki',
				IWL::PAGINATION_NEXT => 'Page 3|awiki',
				IWL::PAGINATION_FIRST => null,
				IWL::PAGINATION_LAST => null,
			],
		];
		yield 'Title descending, first page with offset before' => [
			IWL::SORT_TITLE,
			3,
			'Page 999999|zzzzzwiki',
			IWL::DIR_DESCENDING,
			IWL::NOT_BACKWARDS,
			[
				[
					'wiki' => 'zwiki',
					'prefixedtext' => 'Page 200',
					'timestamp' => null,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 102',
					'timestamp' => null,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 101',
					'timestamp' => '10',
				],
			],
			[
				IWL::PAGINATION_CUR => 'Page 999999|zzzzzwiki',
				IWL::PAGINATION_NEXT => 'Page 101|bwiki',
				IWL::PAGINATION_LAST => null,
			],
		];
		yield 'Title descending, last page' => [
			IWL::SORT_TITLE,
			7,
			null,
			IWL::DIR_DESCENDING,
			IWL::BACKWARDS,
			[
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => '15',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => null,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 3',
					'timestamp' => '5',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 2',
					'timestamp' => '1',
				],
				[
					'wiki' => 'zwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => null,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => '1',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => '1',
				],
			],
			[
				IWL::PAGINATION_CUR => 'Page 5|awiki',
				IWL::PAGINATION_PREV => 'Page 4|bwiki',
				IWL::PAGINATION_FIRST => null,
			],
		];
		yield 'Title descending, penultimate page' => [
			IWL::SORT_TITLE,
			7,
			'Page 4|bwiki',
			IWL::DIR_DESCENDING,
			IWL::BACKWARDS,
			[
				[
					'wiki' => 'zwiki',
					'prefixedtext' => 'Page 200',
					'timestamp' => null,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 102',
					'timestamp' => null,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 101',
					'timestamp' => '10',
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 100',
					'timestamp' => null,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 99',
					'timestamp' => null,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 6',
					'timestamp' => '20',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 5',
					'timestamp' => null,
				],
			],
			[
				IWL::PAGINATION_CUR => null,
				IWL::PAGINATION_NEXT => 'Page 5|awiki',
				IWL::PAGINATION_LAST => null,
			],
		];
		yield 'Title descending, last page with offset after' => [
			IWL::SORT_TITLE,
			3,
			'Page 0|aaaaawiki',
			IWL::DIR_DESCENDING,
			IWL::BACKWARDS,
			[
				[
					'wiki' => 'zwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => null,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => '1',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => '1',
				],
			],
			[
				IWL::PAGINATION_CUR => 'Page 2|awiki',
				IWL::PAGINATION_PREV => 'Page 1|zwiki',
				IWL::PAGINATION_FIRST => null,
			],
		];
		yield 'Title descending, all in one page' => [
			IWL::SORT_TITLE,
			100_000,
			null,
			IWL::DIR_DESCENDING,
			IWL::NOT_BACKWARDS,
			[
				[
					'wiki' => 'zwiki',
					'prefixedtext' => 'Page 200',
					'timestamp' => null,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 102',
					'timestamp' => null,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 101',
					'timestamp' => '10',
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 100',
					'timestamp' => null,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 99',
					'timestamp' => null,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 6',
					'timestamp' => '20',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 5',
					'timestamp' => null,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => '15',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => null,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 3',
					'timestamp' => '5',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 2',
					'timestamp' => '1',
				],
				[
					'wiki' => 'zwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => null,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => '1',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => '1',
				],
			],
			[
				IWL::PAGINATION_CUR => null,
			],
		];
		yield 'Title descending, not backwards, offset after last page' => [
			IWL::SORT_TITLE,
			5,
			'Page 0|aaaawiki',
			IWL::DIR_DESCENDING,
			IWL::NOT_BACKWARDS,
			[],
			[
				IWL::PAGINATION_CUR => 'Page 0|aaaawiki',
				IWL::PAGINATION_PREV => null,
				IWL::PAGINATION_FIRST => null,
			],
		];
		yield 'Title descending, backwards, offset before first page' => [
			IWL::SORT_TITLE,
			5,
			'Page 999999|zzzzwiki',
			IWL::DIR_DESCENDING,
			IWL::BACKWARDS,
			[],
			[
				IWL::PAGINATION_CUR => null,
				IWL::PAGINATION_NEXT => null,
				IWL::PAGINATION_LAST => null,
			],
		];

		yield 'Wiki ascending, first page' => [
			IWL::SORT_WIKI,
			11,
			null,
			IWL::DIR_ASCENDING,
			IWL::NOT_BACKWARDS,
			[
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => '1',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 2',
					'timestamp' => '1',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 3',
					'timestamp' => '5',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 6',
					'timestamp' => '20',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => null,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 5',
					'timestamp' => null,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 99',
					'timestamp' => null,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => '1',
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 101',
					'timestamp' => '10',
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => '15',
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 100',
					'timestamp' => null,
				],
			],
			[
				IWL::PAGINATION_CUR => null,
				IWL::PAGINATION_NEXT => 'bwiki||10',
				IWL::PAGINATION_LAST => null,
			],
		];
		yield 'Wiki ascending, second page' => [
			IWL::SORT_WIKI,
			11,
			'bwiki||10',
			IWL::DIR_ASCENDING,
			IWL::NOT_BACKWARDS,
			[
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 102',
					'timestamp' => null,
				],
				[
					'wiki' => 'zwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => null,
				],
				[
					'wiki' => 'zwiki',
					'prefixedtext' => 'Page 200',
					'timestamp' => null,
				],
			],
			[
				IWL::PAGINATION_CUR => 'bwiki||10',
				IWL::PAGINATION_PREV => 'bwiki||12',
				IWL::PAGINATION_FIRST => null,
			],
		];
		yield 'Wiki ascending, first page with offset before' => [
			IWL::SORT_WIKI,
			3,
			'aaaawiki|-1|-1',
			IWL::DIR_ASCENDING,
			IWL::NOT_BACKWARDS,
			[
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => '1',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 2',
					'timestamp' => '1',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 3',
					'timestamp' => '5',
				],
			],
			[
				IWL::PAGINATION_CUR => 'aaaawiki|-1|-1',
				IWL::PAGINATION_NEXT => 'awiki|5|3',
				IWL::PAGINATION_LAST => null,
			],
		];
		yield 'Wiki ascending, last page' => [
			IWL::SORT_WIKI,
			10,
			null,
			IWL::DIR_ASCENDING,
			IWL::BACKWARDS,
			[
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => null,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 5',
					'timestamp' => null,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 99',
					'timestamp' => null,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => '1',
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 101',
					'timestamp' => '10',
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => '15',
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 100',
					'timestamp' => null,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 102',
					'timestamp' => null,
				],
				[
					'wiki' => 'zwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => null,
				],
				[
					'wiki' => 'zwiki',
					'prefixedtext' => 'Page 200',
					'timestamp' => null,
				],
			],
			[
				IWL::PAGINATION_CUR => 'awiki|20|6',
				IWL::PAGINATION_PREV => 'awiki||4',
				IWL::PAGINATION_FIRST => null,
			],
		];
		yield 'Wiki ascending, penultimate page' => [
			IWL::SORT_WIKI,
			10,
			'awiki||4',
			IWL::DIR_ASCENDING,
			IWL::BACKWARDS,
			[
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => '1',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 2',
					'timestamp' => '1',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 3',
					'timestamp' => '5',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 6',
					'timestamp' => '20',
				],
			],
			[
				IWL::PAGINATION_CUR => null,
				IWL::PAGINATION_NEXT => 'awiki|20|6',
				IWL::PAGINATION_LAST => null,
			],
		];
		yield 'Wiki ascending, last page with offset after' => [
			IWL::SORT_WIKI,
			3,
			'zzzzwiki||9999999',
			IWL::DIR_ASCENDING,
			IWL::BACKWARDS,
			[
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 102',
					'timestamp' => null,
				],
				[
					'wiki' => 'zwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => null,
				],
				[
					'wiki' => 'zwiki',
					'prefixedtext' => 'Page 200',
					'timestamp' => null,
				],
			],
			[
				IWL::PAGINATION_CUR => 'bwiki||10',
				IWL::PAGINATION_PREV => 'bwiki||12',
				IWL::PAGINATION_FIRST => null,
			],
		];
		yield 'Wiki ascending, all in one page' => [
			IWL::SORT_WIKI,
			100_000,
			null,
			IWL::DIR_ASCENDING,
			IWL::NOT_BACKWARDS,
			[
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => '1',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 2',
					'timestamp' => '1',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 3',
					'timestamp' => '5',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 6',
					'timestamp' => '20',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => null,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 5',
					'timestamp' => null,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 99',
					'timestamp' => null,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => '1',
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 101',
					'timestamp' => '10',
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => '15',
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 100',
					'timestamp' => null,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 102',
					'timestamp' => null,
				],
				[
					'wiki' => 'zwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => null,
				],
				[
					'wiki' => 'zwiki',
					'prefixedtext' => 'Page 200',
					'timestamp' => null,
				],
			],
			[
				IWL::PAGINATION_CUR => null,
			],
		];
		yield 'Wiki ascending, not backwards, offset after last page' => [
			IWL::SORT_WIKI,
			5,
			'zzzzwiki||9999999',
			IWL::DIR_ASCENDING,
			IWL::NOT_BACKWARDS,
			[],
			[
				IWL::PAGINATION_CUR => 'zzzzwiki||9999999',
				IWL::PAGINATION_PREV => null,
				IWL::PAGINATION_FIRST => null,
			],
		];
		yield 'Wiki ascending, backwards, offset before first page' => [
			IWL::SORT_WIKI,
			5,
			'aaaawiki|-1|-1',
			IWL::DIR_ASCENDING,
			IWL::BACKWARDS,
			[],
			[
				IWL::PAGINATION_CUR => null,
				IWL::PAGINATION_NEXT => null,
				IWL::PAGINATION_LAST => null,
			],
		];
		// Test offset filtering when most of the items in the first pass (in this case, pages in awiki) will be
		// discarded.
		yield 'Wiki ascending, middle page' => [
			IWL::SORT_WIKI,
			3,
			'awiki||5',
			IWL::DIR_ASCENDING,
			IWL::NOT_BACKWARDS,
			[
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 99',
					'timestamp' => null,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => '1',
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 101',
					'timestamp' => '10',
				],
			],
			[
				IWL::PAGINATION_CUR => 'awiki||5',
				IWL::PAGINATION_PREV => 'awiki||7',
				IWL::PAGINATION_NEXT => 'bwiki|10|11',
				IWL::PAGINATION_FIRST => null,
				IWL::PAGINATION_LAST => null,
			],
		];

		yield 'Wiki descending, first page' => [
			IWL::SORT_WIKI,
			10,
			null,
			IWL::DIR_DESCENDING,
			IWL::NOT_BACKWARDS,
			[
				[
					'wiki' => 'zwiki',
					'prefixedtext' => 'Page 200',
					'timestamp' => null,
				],
				[
					'wiki' => 'zwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => null,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 102',
					'timestamp' => null,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 100',
					'timestamp' => null,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => '15',
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 101',
					'timestamp' => '10',
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => '1',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 99',
					'timestamp' => null,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 5',
					'timestamp' => null,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => null,
				],
			],
			[
				IWL::PAGINATION_CUR => null,
				IWL::PAGINATION_NEXT => 'awiki||4',
				IWL::PAGINATION_LAST => null,
			],
		];
		yield 'Wiki descending, second page' => [
			IWL::SORT_WIKI,
			10,
			'awiki||4',
			IWL::DIR_DESCENDING,
			IWL::NOT_BACKWARDS,
			[
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 6',
					'timestamp' => '20',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 3',
					'timestamp' => '5',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 2',
					'timestamp' => '1',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => '1',
				],
			],
			[
				IWL::PAGINATION_CUR => 'awiki||4',
				IWL::PAGINATION_PREV => 'awiki|20|6',
				IWL::PAGINATION_FIRST => null,
			],
		];
		yield 'Wiki descending, first page with offset before' => [
			IWL::SORT_WIKI,
			3,
			'zzzzwiki||999999',
			IWL::DIR_DESCENDING,
			IWL::NOT_BACKWARDS,
			[
				[
					'wiki' => 'zwiki',
					'prefixedtext' => 'Page 200',
					'timestamp' => null,
				],
				[
					'wiki' => 'zwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => null,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 102',
					'timestamp' => null,
				],
			],
			[
				IWL::PAGINATION_CUR => 'zzzzwiki||999999',
				IWL::PAGINATION_NEXT => 'bwiki||12',
				IWL::PAGINATION_LAST => null,
			],
		];
		yield 'Wiki descending, last page' => [
			IWL::SORT_WIKI,
			11,
			null,
			IWL::DIR_DESCENDING,
			IWL::BACKWARDS,
			[
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 100',
					'timestamp' => null,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => '15',
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 101',
					'timestamp' => '10',
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => '1',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 99',
					'timestamp' => null,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 5',
					'timestamp' => null,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => null,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 6',
					'timestamp' => '20',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 3',
					'timestamp' => '5',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 2',
					'timestamp' => '1',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => '1',
				],
			],
			[
				IWL::PAGINATION_CUR => 'bwiki||12',
				IWL::PAGINATION_PREV => 'bwiki||10',
				IWL::PAGINATION_FIRST => null,
			],
		];
		yield 'Wiki descending, penultimate page' => [
			IWL::SORT_WIKI,
			11,
			'bwiki||10',
			IWL::DIR_DESCENDING,
			IWL::BACKWARDS,
			[
				[
					'wiki' => 'zwiki',
					'prefixedtext' => 'Page 200',
					'timestamp' => null,
				],
				[
					'wiki' => 'zwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => null,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 102',
					'timestamp' => null,
				],
			],
			[
				IWL::PAGINATION_CUR => null,
				IWL::PAGINATION_NEXT => 'bwiki||12',
				IWL::PAGINATION_LAST => null,
			],
		];
		yield 'Wiki descending, last page with offset after' => [
			IWL::SORT_WIKI,
			3,
			'aaaawiki|-1|-1',
			IWL::DIR_DESCENDING,
			IWL::BACKWARDS,
			[
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 3',
					'timestamp' => '5',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 2',
					'timestamp' => '1',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => '1',
				],
			],
			[
				IWL::PAGINATION_CUR => 'awiki|20|6',
				IWL::PAGINATION_PREV => 'awiki|5|3',
				IWL::PAGINATION_FIRST => null,
			],
		];
		yield 'Wiki descending, all in one page' => [
			IWL::SORT_WIKI,
			100_000,
			null,
			IWL::DIR_DESCENDING,
			IWL::NOT_BACKWARDS,
			[
				[
					'wiki' => 'zwiki',
					'prefixedtext' => 'Page 200',
					'timestamp' => null,
				],
				[
					'wiki' => 'zwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => null,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 102',
					'timestamp' => null,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 100',
					'timestamp' => null,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => '15',
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 101',
					'timestamp' => '10',
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => '1',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 99',
					'timestamp' => null,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 5',
					'timestamp' => null,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => null,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 6',
					'timestamp' => '20',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 3',
					'timestamp' => '5',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 2',
					'timestamp' => '1',
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => '1',
				],
			],
			[
				IWL::PAGINATION_CUR => null,
			],
		];
		yield 'Wiki descending, not backwards, offset after last page' => [
			IWL::SORT_WIKI,
			5,
			'aaaawiki|-1|-1',
			IWL::DIR_DESCENDING,
			IWL::NOT_BACKWARDS,
			[],
			[
				IWL::PAGINATION_CUR => 'aaaawiki|-1|-1',
				IWL::PAGINATION_PREV => null,
				IWL::PAGINATION_FIRST => null,
			],
		];
		yield 'Wiki descending, backwards, offset before first page' => [
			IWL::SORT_WIKI,
			5,
			'zzzzwiki||9999999',
			IWL::DIR_DESCENDING,
			IWL::BACKWARDS,
			[],
			[
				IWL::PAGINATION_CUR => null,
				IWL::PAGINATION_NEXT => null,
				IWL::PAGINATION_LAST => null,
			],
		];
	}

	public static function provideInvalidOffsets(): Generator {
		yield from parent::provideInvalidOffsets();

		// An offset containing empty fields where a value is expected (i.e. for fields that don't get a placeholder)
		// is also considered invalid by this lookup.
		yield 'Unexpected empty fields' => [ '|', '|' ];
	}
}
