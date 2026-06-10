<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Worklist;

use Generator;
use MediaWiki\Extension\CampaignEvents\Worklist\WorklistContent;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\CampaignEvents\Worklist\WorklistContent
 */
class WorklistContentTest extends MediaWikiUnitTestCase {
	/** @dataProvider provideComputeDelta */
	public function testComputeDelta( ?WorklistContent $before, WorklistContent $after, array $expected ): void {
		$actual = WorklistContent::computeDelta( $before, $after );
		ksort( $actual['removed'] );
		ksort( $actual['added'] );
		$this->assertSame( $expected, $actual );
	}

	public static function provideComputeDelta(): Generator {
		if ( !defined( 'CONTENT_MODEL_WORKLIST' ) ) {
			// Gotta redefine the constant due to T428794
			define( 'CONTENT_MODEL_WORKLIST', 'worklist' );
		}

		$c = static fn ( array $content ): WorklistContent => new WorklistContent( json_encode( $content ) );

		$simpleMap = [ 'awiki' => [ 'x' ] ];
		yield 'No previous content' => [
			null,
			$c( $simpleMap ),
			[ 'removed' => [], 'added' => $simpleMap ]
		];
		yield 'Previous content is empty' => [
			$c( [] ),
			$c( $simpleMap ),
			[ 'removed' => [], 'added' => $simpleMap ]
		];
		yield 'Previous content has empty wiki' => [
			$c( [ 'awiki' => [] ] ),
			$c( $simpleMap ),
			[ 'removed' => [], 'added' => $simpleMap ]
		];

		yield 'New content is empty' => [
			$c( $simpleMap ),
			$c( [] ),
			[ 'removed' => $simpleMap, 'added' => [] ]
		];
		yield 'New content has empty wiki' => [
			$c( $simpleMap ),
			$c( [ 'awiki' => [] ] ),
			[ 'removed' => $simpleMap, 'added' => [] ]
		];

		yield 'Multiple differences' => [
			$c( [
				'awiki' => [ 'apage1', 'apage2' ],
				'bwiki' => [ 'bpage1' ],
				'dwiki' => [],
				'ewiki' => [ 'epage1', 'epage2' ],
				'fwiki' => [],
				'gwiki' => [ 'gpage1', 'gpage2' ],
				'hwiki' => [ 'hpage1', 'hpage2' ],
			] ),
			$c( [
				'awiki' => [ 'apage2', 'apage3' ],
				'bwiki' => [],
				'cwiki' => [ 'cpage1' ],
				'dwiki' => [],
				'ewiki' => [ 'epage1', 'epage2' ],
				'fwiki' => [ 'fpage1', 'fpage2' ],
				'hwiki' => [ 'hpage1', 'hpage3' ],
			] ),
			[
				'removed' => [
					'awiki' => [ 'apage1' ],
					'bwiki' => [ 'bpage1' ],
					'gwiki' => [ 'gpage1', 'gpage2' ],
					'hwiki' => [ 'hpage2' ],
				],
				'added' => [
					'awiki' => [ 'apage3' ],
					'cwiki' => [ 'cpage1' ],
					'fwiki' => [ 'fpage1', 'fpage2' ],
					'hwiki' => [ 'hpage3' ],
				]
			]
		];
	}
}
