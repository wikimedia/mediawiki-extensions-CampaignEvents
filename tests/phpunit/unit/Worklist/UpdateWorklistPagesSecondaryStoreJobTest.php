<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Worklist;

use Generator;
use MediaWiki\Extension\CampaignEvents\Worklist\UpdateWorklistPagesSecondaryStoreJob;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\CampaignEvents\Worklist\UpdateWorklistPagesSecondaryStoreJob
 */
class UpdateWorklistPagesSecondaryStoreJobTest extends MediaWikiUnitTestCase {
	/** @dataProvider provideConstructorErrors */
	public function testConstructorErrors( array $params, ?string $expectedException ): void {
		if ( $expectedException ) {
			$this->expectExceptionMessage( $expectedException );
		}
		new UpdateWorklistPagesSecondaryStoreJob( $params );
		if ( !$expectedException ) {
			$this->addToAssertionCount( 1 );
		}
	}

	public static function provideConstructorErrors(): Generator {
		yield 'No type' => [ [], 'Invalid job type' ];
		yield 'Invalid type' => [ [ 'type' => 'garbage' ], 'Invalid job type' ];
		yield 'Missing params' => [
			[ 'type' => UpdateWorklistPagesSecondaryStoreJob::TYPE_DELETE ],
			'Missing params: namespace, title, worklistID, triggeringRevID',
		];
		yield 'Missing triggering revision for update' => [
			[
				'type' => UpdateWorklistPagesSecondaryStoreJob::TYPE_UPDATE,
				'namespace' => NS_MAIN,
				'title' => 'Foo',
				'worklistID' => 1,
				'performerCentralID' => 2,
				'triggeringRevID' => null,
			],
			'triggeringRevID cannot be null for updates',
		];
		yield 'Missing performer for update' => [
			[
				'type' => UpdateWorklistPagesSecondaryStoreJob::TYPE_UPDATE,
				'namespace' => NS_MAIN,
				'title' => 'Foo',
				'worklistID' => 1,
				'triggeringRevID' => 2,
			],
			'performerCentralID cannot be null for updates',
		];
		yield 'Null performer for update' => [
			[
				'type' => UpdateWorklistPagesSecondaryStoreJob::TYPE_UPDATE,
				'namespace' => NS_MAIN,
				'title' => 'Foo',
				'worklistID' => 1,
				'performerCentralID' => null,
				'triggeringRevID' => 2,
			],
			'performerCentralID cannot be null for updates',
		];
		yield 'Success' => [
			[
				'type' => UpdateWorklistPagesSecondaryStoreJob::TYPE_DELETE,
				'namespace' => NS_MAIN,
				'title' => 'Foo',
				'worklistID' => 1,
				'triggeringRevID' => 1
			],
			null,
		];
	}
}
