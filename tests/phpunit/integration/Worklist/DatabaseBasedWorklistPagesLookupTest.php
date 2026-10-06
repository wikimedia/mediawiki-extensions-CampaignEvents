<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Worklist;

use Generator;
use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Extension\CampaignEvents\Pager\WorklistPagesPagerFactory;
use MediaWiki\Extension\CampaignEvents\Worklist\DatabaseBasedWorklistPagesLookup;
use MediaWiki\Extension\CampaignEvents\Worklist\IWorklistPagesLookup as IWL;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Page\PageIdentityValue;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use Wikimedia\Timestamp\TimestampFormat;

/**
 * @covers \MediaWiki\Extension\CampaignEvents\Worklist\DatabaseBasedWorklistPagesLookup
 * @group Database
 */
class DatabaseBasedWorklistPagesLookupTest extends WorklistPagesLookupTestBase {
	protected function setUp(): void {
		parent::setUp();
		// The data provider heavily needs to format timestamps according to the storage layer, which we can't access
		// in a data provider, so we hardcode the format used by MySQL and SQLite and skip under postgres, rather than
		// complicating things with runtime timestamp resolution/replacement and the like.
		$this->markTestSkippedIfDbType( 'postgres' );
	}

	protected function getLookup(): DatabaseBasedWorklistPagesLookup {
		$services = $this->getServiceContainer();
		return new DatabaseBasedWorklistPagesLookup(
			$services->get( WorklistPagesPagerFactory::SERVICE_NAME ),
			$this->createMock( LinkRenderer::class ),
			new PageIdentityValue( 123, NS_MAIN, 'Test_worklist', WikiAwareEntity::LOCAL ),
		);
	}

	public static function provideGetWorklistPages(): Generator {
		// Hardcode the format used by MySQL and SQLite, as the test is skipped under postgres.
		$ts = static fn ( int $ts ): string => ConvertibleTimestamp::convert( TimestampFormat::MW, $ts );

		yield 'Timestamp descending, first page' => [
			IWL::SORT_TIMESTAMP,
			3,
			null,
			IWL::DIR_DESCENDING,
			IWL::NOT_BACKWARDS,
			[
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 6',
					'timestamp' => 20,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => 15,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 101',
					'timestamp' => 10,
				],
			],
			[
				IWL::PAGINATION_CUR => null,
				IWL::PAGINATION_NEXT => $ts( 10 ) . '|7',
				IWL::PAGINATION_LAST => null,
			],
		];
		yield 'Timestamp descending, second page' => [
			IWL::SORT_TIMESTAMP,
			3,
			$ts( 10 ) . '|7',
			IWL::DIR_DESCENDING,
			IWL::NOT_BACKWARDS,
			[
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 3',
					'timestamp' => 5,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => 1,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 2',
					'timestamp' => 1,
				],
			],
			[
				IWL::PAGINATION_CUR => $ts( 10 ) . '|7',
				IWL::PAGINATION_PREV => $ts( 5 ) . '|5',
				IWL::PAGINATION_NEXT => $ts( 1 ) . '|2',
				IWL::PAGINATION_FIRST => null,
				IWL::PAGINATION_LAST => null,
			],
		];
		yield 'Timestamp descending, first page with offset before' => [
			IWL::SORT_TIMESTAMP,
			3,
			$ts( 9999999 ) . '|9999999999999',
			IWL::DIR_DESCENDING,
			IWL::NOT_BACKWARDS,
			[
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 6',
					'timestamp' => 20,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => 15,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 101',
					'timestamp' => 10,
				],
			],
			[
				IWL::PAGINATION_CUR => $ts( 9999999 ) . '|9999999999999',
				IWL::PAGINATION_NEXT => $ts( 10 ) . '|7',
				IWL::PAGINATION_LAST => null,
			],
		];
		yield 'Timestamp descending, last page' => [
			IWL::SORT_TIMESTAMP,
			3,
			null,
			IWL::DIR_DESCENDING,
			IWL::BACKWARDS,
			[
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => 1,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 2',
					'timestamp' => 1,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => 1,
				],
			],
			[
				IWL::PAGINATION_CUR => $ts( 5 ) . '|5',
				IWL::PAGINATION_PREV => $ts( 1 ) . '|3',
				IWL::PAGINATION_FIRST => null,
			],
		];
		yield 'Timestamp descending, penultimate page' => [
			IWL::SORT_TIMESTAMP,
			3,
			$ts( 1 ) . '|3',
			IWL::DIR_DESCENDING,
			IWL::BACKWARDS,
			[
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => 15,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 101',
					'timestamp' => 10,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 3',
					'timestamp' => 5,
				],
			],
			[
				IWL::PAGINATION_CUR => $ts( 20 ) . '|6',
				IWL::PAGINATION_PREV => $ts( 15 ) . '|4',
				IWL::PAGINATION_NEXT => $ts( 5 ) . '|5',
				IWL::PAGINATION_FIRST => null,
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
					'timestamp' => 5,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => 1,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 2',
					'timestamp' => 1,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => 1,
				],
			],
			[
				IWL::PAGINATION_CUR => $ts( 10 ) . '|7',
				IWL::PAGINATION_PREV => $ts( 5 ) . '|5',
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
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 6',
					'timestamp' => 20,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => 15,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 101',
					'timestamp' => 10,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 3',
					'timestamp' => 5,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => 1,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 2',
					'timestamp' => 1,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => 1,
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
			$ts( 99999999 ) . '|99999999',
			IWL::DIR_DESCENDING,
			IWL::BACKWARDS,
			[],
			[
				IWL::PAGINATION_CUR => null,
				IWL::PAGINATION_NEXT => null,
				IWL::PAGINATION_LAST => null,
			],
		];

		yield 'Timestamp ascending, first page' => [
			IWL::SORT_TIMESTAMP,
			4,
			null,
			IWL::DIR_ASCENDING,
			IWL::NOT_BACKWARDS,
			[
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => 1,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 2',
					'timestamp' => 1,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => 1,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 3',
					'timestamp' => 5,
				],
			],
			[
				IWL::PAGINATION_CUR => null,
				IWL::PAGINATION_NEXT => $ts( 5 ) . '|5',
				IWL::PAGINATION_LAST => null,
			],
		];
		yield 'Timestamp ascending, second page' => [
			IWL::SORT_TIMESTAMP,
			2,
			$ts( 5 ) . '|5',
			IWL::DIR_ASCENDING,
			IWL::NOT_BACKWARDS,
			[
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 101',
					'timestamp' => 10,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => 15,
				],
			],
			[
				IWL::PAGINATION_CUR => $ts( 5 ) . '|5',
				IWL::PAGINATION_PREV => $ts( 10 ) . '|7',
				IWL::PAGINATION_NEXT => $ts( 15 ) . '|4',
				IWL::PAGINATION_FIRST => null,
				IWL::PAGINATION_LAST => null,
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
					'timestamp' => 1,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 2',
					'timestamp' => 1,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => 1,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 3',
					'timestamp' => 5,
				],
			],
			[
				IWL::PAGINATION_CUR => '-1|-1',
				IWL::PAGINATION_NEXT => $ts( 5 ) . '|5',
				IWL::PAGINATION_LAST => null,
			],
		];
		yield 'Timestamp ascending, last page' => [
			IWL::SORT_TIMESTAMP,
			3,
			null,
			IWL::DIR_ASCENDING,
			IWL::BACKWARDS,
			[
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 101',
					'timestamp' => 10,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => 15,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 6',
					'timestamp' => 20,
				],
			],
			[
				IWL::PAGINATION_CUR => $ts( 5 ) . '|5',
				IWL::PAGINATION_PREV => $ts( 10 ) . '|7',
				IWL::PAGINATION_FIRST => null,
			],
		];
		yield 'Timestamp ascending, penultimate page' => [
			IWL::SORT_TIMESTAMP,
			3,
			$ts( 10 ) . '|7',
			IWL::DIR_ASCENDING,
			IWL::BACKWARDS,
			[
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 2',
					'timestamp' => 1,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => 1,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 3',
					'timestamp' => 5,
				],
			],
			[
				IWL::PAGINATION_CUR => $ts( 1 ) . '|1',
				IWL::PAGINATION_PREV => $ts( 1 ) . '|2',
				IWL::PAGINATION_NEXT => $ts( 5 ) . '|5',
				IWL::PAGINATION_FIRST => null,
				IWL::PAGINATION_LAST => null,
			],
		];
		yield 'Timestamp ascending, last page with offset after' => [
			IWL::SORT_TIMESTAMP,
			3,
			$ts( 9999999 ) . '|99999999',
			IWL::DIR_ASCENDING,
			IWL::BACKWARDS,
			[
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 101',
					'timestamp' => 10,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => 15,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 6',
					'timestamp' => 20,
				],
			],
			[
				IWL::PAGINATION_CUR => $ts( 5 ) . '|5',
				IWL::PAGINATION_PREV => $ts( 10 ) . '|7',
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
					'timestamp' => 1,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 2',
					'timestamp' => 1,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => 1,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 3',
					'timestamp' => 5,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 101',
					'timestamp' => 10,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => 15,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 6',
					'timestamp' => 20,
				],
			],
			[
				IWL::PAGINATION_CUR => null,
			],
		];
		yield 'Timestamp ascending, not backwards, offset after last page' => [
			IWL::SORT_TIMESTAMP,
			5,
			$ts( 9999999 ) . '|99999999',
			IWL::DIR_ASCENDING,
			IWL::NOT_BACKWARDS,
			[],
			[
				IWL::PAGINATION_CUR => $ts( 9999999 ) . '|99999999',
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
			5,
			null,
			IWL::DIR_ASCENDING,
			IWL::NOT_BACKWARDS,
			[
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => 1,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => 1,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 101',
					'timestamp' => 10,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 2',
					'timestamp' => 1,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 3',
					'timestamp' => 5,
				],
			],
			[
				IWL::PAGINATION_CUR => null,
				IWL::PAGINATION_NEXT => 'Page 3|awiki',
				IWL::PAGINATION_LAST => null,
			],
		];
		yield 'Title ascending, second page' => [
			IWL::SORT_TITLE,
			2,
			'Page 3|awiki',
			IWL::DIR_ASCENDING,
			IWL::NOT_BACKWARDS,
			[
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => 15,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 6',
					'timestamp' => 20,
				],
			],
			[
				IWL::PAGINATION_CUR => 'Page 3|awiki',
				IWL::PAGINATION_PREV => 'Page 4|bwiki',
				IWL::PAGINATION_FIRST => null,
			],
		];
		yield 'Title ascending, first page with offset before' => [
			IWL::SORT_TITLE,
			2,
			'Page 0|aaaawiki',
			IWL::DIR_ASCENDING,
			IWL::NOT_BACKWARDS,
			[
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => 1,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => 1,
				],
			],
			[
				IWL::PAGINATION_CUR => 'Page 0|aaaawiki',
				IWL::PAGINATION_NEXT => 'Page 1|bwiki',
				IWL::PAGINATION_LAST => null,
			],
		];
		yield 'Title ascending, last page' => [
			IWL::SORT_TITLE,
			3,
			null,
			IWL::DIR_ASCENDING,
			IWL::BACKWARDS,
			[
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 3',
					'timestamp' => 5,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => 15,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 6',
					'timestamp' => 20,
				],
			],
			[
				IWL::PAGINATION_CUR => 'Page 2|awiki',
				IWL::PAGINATION_PREV => 'Page 3|awiki',
				IWL::PAGINATION_FIRST => null,
			],
		];
		yield 'Title ascending, penultimate page' => [
			IWL::SORT_TITLE,
			3,
			'Page 3|awiki',
			IWL::DIR_ASCENDING,
			IWL::BACKWARDS,
			[
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => 1,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 101',
					'timestamp' => 10,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 2',
					'timestamp' => 1,
				],
			],
			[
				IWL::PAGINATION_CUR => 'Page 1|awiki',
				IWL::PAGINATION_PREV => 'Page 1|bwiki',
				IWL::PAGINATION_NEXT => 'Page 2|awiki',
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
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 3',
					'timestamp' => 5,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => 15,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 6',
					'timestamp' => 20,
				],
			],
			[
				IWL::PAGINATION_CUR => 'Page 2|awiki',
				IWL::PAGINATION_PREV => 'Page 3|awiki',
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
					'timestamp' => 1,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => 1,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 101',
					'timestamp' => 10,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 2',
					'timestamp' => 1,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 3',
					'timestamp' => 5,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => 15,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 6',
					'timestamp' => 20,
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
			3,
			null,
			IWL::DIR_DESCENDING,
			IWL::NOT_BACKWARDS,
			[
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 6',
					'timestamp' => 20,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => 15,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 3',
					'timestamp' => 5,
				],
			],
			[
				IWL::PAGINATION_CUR => null,
				IWL::PAGINATION_NEXT => 'Page 3|awiki',
				IWL::PAGINATION_LAST => null,
			],
		];
		yield 'Title descending, second page' => [
			IWL::SORT_TITLE,
			3,
			'Page 3|awiki',
			IWL::DIR_DESCENDING,
			IWL::NOT_BACKWARDS,
			[
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 2',
					'timestamp' => 1,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 101',
					'timestamp' => 10,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => 1,
				],
			],
			[
				IWL::PAGINATION_CUR => 'Page 3|awiki',
				IWL::PAGINATION_PREV => 'Page 2|awiki',
				IWL::PAGINATION_NEXT => 'Page 1|bwiki',
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
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 6',
					'timestamp' => 20,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => 15,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 3',
					'timestamp' => 5,
				],
			],
			[
				IWL::PAGINATION_CUR => 'Page 999999|zzzzzwiki',
				IWL::PAGINATION_NEXT => 'Page 3|awiki',
				IWL::PAGINATION_LAST => null,
			],
		];
		yield 'Title descending, last page' => [
			IWL::SORT_TITLE,
			4,
			null,
			IWL::DIR_DESCENDING,
			IWL::BACKWARDS,
			[
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 2',
					'timestamp' => 1,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 101',
					'timestamp' => 10,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => 1,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => 1,
				],
			],
			[
				IWL::PAGINATION_CUR => 'Page 3|awiki',
				IWL::PAGINATION_PREV => 'Page 2|awiki',
				IWL::PAGINATION_FIRST => null,
			],
		];
		yield 'Title descending, penultimate page' => [
			IWL::SORT_TITLE,
			3,
			'Page 2|awiki',
			IWL::DIR_DESCENDING,
			IWL::BACKWARDS,
			[
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 6',
					'timestamp' => 20,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => 15,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 3',
					'timestamp' => 5,
				],
			],
			[
				IWL::PAGINATION_CUR => null,
				IWL::PAGINATION_NEXT => 'Page 3|awiki',
				IWL::PAGINATION_LAST => null,
			],
		];
		yield 'Title descending, last page with offset after' => [
			IWL::SORT_TITLE,
			4,
			'Page 0|aaaaawiki',
			IWL::DIR_DESCENDING,
			IWL::BACKWARDS,
			[
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 2',
					'timestamp' => 1,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 101',
					'timestamp' => 10,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => 1,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => 1,
				],
			],
			[
				IWL::PAGINATION_CUR => 'Page 3|awiki',
				IWL::PAGINATION_PREV => 'Page 2|awiki',
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
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 6',
					'timestamp' => 20,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => 15,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 3',
					'timestamp' => 5,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 2',
					'timestamp' => 1,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 101',
					'timestamp' => 10,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => 1,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => 1,
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
			5,
			null,
			IWL::DIR_ASCENDING,
			IWL::NOT_BACKWARDS,
			[
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => 1,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 2',
					'timestamp' => 1,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 3',
					'timestamp' => 5,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 6',
					'timestamp' => 20,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => 1,
				],
			],
			[
				IWL::PAGINATION_CUR => null,
				IWL::PAGINATION_NEXT => 'bwiki|' . $ts( 1 ) . '|3',
				IWL::PAGINATION_LAST => null,
			],
		];
		yield 'Wiki ascending, second page' => [
			IWL::SORT_WIKI,
			5,
			'bwiki|' . $ts( 1 ) . '|3',
			IWL::DIR_ASCENDING,
			IWL::NOT_BACKWARDS,
			[
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 101',
					'timestamp' => 10,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => 15,
				],
			],
			[
				IWL::PAGINATION_CUR => 'bwiki|' . $ts( 1 ) . '|3',
				IWL::PAGINATION_PREV => 'bwiki|' . $ts( 10 ) . '|7',
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
					'timestamp' => 1,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 2',
					'timestamp' => 1,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 3',
					'timestamp' => 5,
				],
			],
			[
				IWL::PAGINATION_CUR => 'aaaawiki|-1|-1',
				IWL::PAGINATION_NEXT => 'awiki|' . $ts( 5 ) . '|5',
				IWL::PAGINATION_LAST => null,
			],
		];
		yield 'Wiki ascending, last page' => [
			IWL::SORT_WIKI,
			5,
			null,
			IWL::DIR_ASCENDING,
			IWL::BACKWARDS,
			[
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 3',
					'timestamp' => 5,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 6',
					'timestamp' => 20,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => 1,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 101',
					'timestamp' => 10,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => 15,
				],
			],
			[
				IWL::PAGINATION_CUR => 'awiki|' . $ts( 1 ) . '|2',
				IWL::PAGINATION_PREV => 'awiki|' . $ts( 5 ) . '|5',
				IWL::PAGINATION_FIRST => null,
			],
		];
		yield 'Wiki ascending, penultimate page' => [
			IWL::SORT_WIKI,
			5,
			'awiki|' . $ts( 5 ) . '|5',
			IWL::DIR_ASCENDING,
			IWL::BACKWARDS,
			[
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => 1,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 2',
					'timestamp' => 1,
				],
			],
			[
				IWL::PAGINATION_CUR => null,
				IWL::PAGINATION_NEXT => 'awiki|' . $ts( 1 ) . '|2',
				IWL::PAGINATION_LAST => null,
			],
		];
		yield 'Wiki ascending, last page with offset after' => [
			IWL::SORT_WIKI,
			3,
			'zzzzwiki|' . $ts( 999999999 ) . '|9999999',
			IWL::DIR_ASCENDING,
			IWL::BACKWARDS,
			[
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => 1,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 101',
					'timestamp' => 10,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => 15,
				],
			],
			[
				IWL::PAGINATION_CUR => 'awiki|' . $ts( 20 ) . '|6',
				IWL::PAGINATION_PREV => 'bwiki|' . $ts( 1 ) . '|3',
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
					'timestamp' => 1,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 2',
					'timestamp' => 1,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 3',
					'timestamp' => 5,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 6',
					'timestamp' => 20,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => 1,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 101',
					'timestamp' => 10,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => 15,
				],
			],
			[
				IWL::PAGINATION_CUR => null,
			],
		];
		yield 'Wiki ascending, not backwards, offset after last page' => [
			IWL::SORT_WIKI,
			5,
			'zzzzwiki|' . $ts( 999999999 ) . '|9999999',
			IWL::DIR_ASCENDING,
			IWL::NOT_BACKWARDS,
			[],
			[
				IWL::PAGINATION_CUR => 'zzzzwiki|' . $ts( 999999999 ) . '|9999999',
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

		yield 'Wiki descending, first page' => [
			IWL::SORT_WIKI,
			5,
			null,
			IWL::DIR_DESCENDING,
			IWL::NOT_BACKWARDS,
			[
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => 15,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 101',
					'timestamp' => 10,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => 1,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 6',
					'timestamp' => 20,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 3',
					'timestamp' => 5,
				],
			],
			[
				IWL::PAGINATION_CUR => null,
				IWL::PAGINATION_NEXT => 'awiki|' . $ts( 5 ) . '|5',
				IWL::PAGINATION_LAST => null,
			],
		];
		yield 'Wiki descending, second page' => [
			IWL::SORT_WIKI,
			5,
			'awiki|' . $ts( 5 ) . '|5',
			IWL::DIR_DESCENDING,
			IWL::NOT_BACKWARDS,
			[
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 2',
					'timestamp' => 1,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => 1,
				],
			],
			[
				IWL::PAGINATION_CUR => 'awiki|' . $ts( 5 ) . '|5',
				IWL::PAGINATION_PREV => 'awiki|' . $ts( 1 ) . '|2',
				IWL::PAGINATION_FIRST => null,
			],
		];
		yield 'Wiki descending, first page with offset before' => [
			IWL::SORT_WIKI,
			3,
			'zzzzwiki|' . $ts( 9999999 ) . '|999999',
			IWL::DIR_DESCENDING,
			IWL::NOT_BACKWARDS,
			[
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => 15,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 101',
					'timestamp' => 10,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => 1,
				],
			],
			[
				IWL::PAGINATION_CUR => 'zzzzwiki|' . $ts( 9999999 ) . '|999999',
				IWL::PAGINATION_NEXT => 'bwiki|' . $ts( 1 ) . '|3',
				IWL::PAGINATION_LAST => null,
			],
		];
		yield 'Wiki descending, last page' => [
			IWL::SORT_WIKI,
			5,
			null,
			IWL::DIR_DESCENDING,
			IWL::BACKWARDS,
			[
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => 1,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 6',
					'timestamp' => 20,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 3',
					'timestamp' => 5,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 2',
					'timestamp' => 1,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => 1,
				],
			],
			[
				IWL::PAGINATION_CUR => 'bwiki|' . $ts( 10 ) . '|7',
				IWL::PAGINATION_PREV => 'bwiki|' . $ts( 1 ) . '|3',
				IWL::PAGINATION_FIRST => null,
			],
		];
		yield 'Wiki descending, penultimate page' => [
			IWL::SORT_WIKI,
			5,
			'bwiki|' . $ts( 1 ) . '|3',
			IWL::DIR_DESCENDING,
			IWL::BACKWARDS,
			[
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => 15,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 101',
					'timestamp' => 10,
				],
			],
			[
				IWL::PAGINATION_CUR => null,
				IWL::PAGINATION_NEXT => 'bwiki|' . $ts( 10 ) . '|7',
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
					'timestamp' => 5,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 2',
					'timestamp' => 1,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => 1,
				],
			],
			[
				IWL::PAGINATION_CUR => 'awiki|' . $ts( 20 ) . '|6',
				IWL::PAGINATION_PREV => 'awiki|' . $ts( 5 ) . '|5',
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
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 4',
					'timestamp' => 15,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 101',
					'timestamp' => 10,
				],
				[
					'wiki' => 'bwiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => 1,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 6',
					'timestamp' => 20,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 3',
					'timestamp' => 5,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 2',
					'timestamp' => 1,
				],
				[
					'wiki' => 'awiki',
					'prefixedtext' => 'Page 1',
					'timestamp' => 1,
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
			'zzzzwiki|' . $ts( 99999999 ) . '|9999999',
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
}
