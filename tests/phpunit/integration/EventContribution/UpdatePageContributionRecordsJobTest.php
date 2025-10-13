<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\EventContribution;

use Generator;
use InvalidArgumentException;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionStore;
use MediaWiki\Extension\CampaignEvents\EventContribution\UpdatePageContributionRecordsJob;
use MediaWikiIntegrationTestCase;

/**
 * @group Test
 * @covers \MediaWiki\Extension\CampaignEvents\EventContribution\UpdatePageContributionRecordsJob
 */
class UpdatePageContributionRecordsJobTest extends MediaWikiIntegrationTestCase {

	/** @dataProvider provideConstructor */
	public function testConstructor( array $params, ?string $expectedExceptionMessage ) {
		if ( $expectedExceptionMessage !== null ) {
			$this->expectException( InvalidArgumentException::class );
			$this->expectExceptionMessage( $expectedExceptionMessage );
		}
		new UpdatePageContributionRecordsJob( $params );
		if ( $expectedExceptionMessage === null ) {
			$this->addToAssertionCount( 1 );
		}
	}

	public static function provideConstructor(): Generator {
		yield 'Bad type' => [
			[ 'type' => 'doesnotexist' ],
			'Invalid type "doesnotexist"'
		];
		yield 'Move, missing params' => [
			[ 'type' => UpdatePageContributionRecordsJob::TYPE_MOVE ],
			'Missing parameters: wiki, pageID, newPrefixedText'
		];
		yield 'Move, valid' => [
			[
				'type' => UpdatePageContributionRecordsJob::TYPE_MOVE,
				'wiki' => 'awiki',
				'pageID' => 42,
				'newPrefixedText' => 'Foo'
			],
			null
		];
		yield 'Delete, missing params' => [
			[ 'type' => UpdatePageContributionRecordsJob::TYPE_DELETE ],
			'Missing parameters: wiki, pageID'
		];
		yield 'Delete, valid' => [
			[ 'type' => UpdatePageContributionRecordsJob::TYPE_DELETE, 'wiki' => 'awiki', 'pageID' => 42 ],
			null
		];
		yield 'Restore, missing params' => [
			[ 'type' => UpdatePageContributionRecordsJob::TYPE_RESTORE ],
			'Missing parameters: wiki, pageID'
		];
		yield 'Restore, valid' => [
			[ 'type' => UpdatePageContributionRecordsJob::TYPE_RESTORE, 'wiki' => 'awiki', 'pageID' => 42 ],
			null
		];
		yield 'Revdel, missing params' => [
			[ 'type' => UpdatePageContributionRecordsJob::TYPE_REV_DELETE ],
			'Missing parameters: wiki, pageID, deletedRevIDs, restoredRevIDs'
		];
		yield 'Revdel, valid' => [
			[
				'type' => UpdatePageContributionRecordsJob::TYPE_REV_DELETE,
				'wiki' => 'awiki',
				'pageID' => 1000,
				'deletedRevIDs' => [ 123 ],
				'restoredRevIDs' => [ 456 ]
			],
			null
		];
	}

	/** @dataProvider provideRun */
	public function testRun( array $params, string $expectedMethod ) {
		$store = $this->createMock( EventContributionStore::class );
		$store->expects( $this->once() )->method( $expectedMethod );
		$this->setService( EventContributionStore::SERVICE_NAME, $store );

		$job = new UpdatePageContributionRecordsJob( $params );
		$job->run();
	}

	public static function provideRun(): Generator {
		$basePageParams = [
			'wiki' => 'awiki',
			'pageID' => 1234,
		];

		yield 'Move' => [
			[ 'type' => UpdatePageContributionRecordsJob::TYPE_MOVE, 'newPrefixedText' => 'Foo' ] + $basePageParams,
			'updateTitle'
		];
		yield 'Delete' => [
			[ 'type' => UpdatePageContributionRecordsJob::TYPE_DELETE ] + $basePageParams,
			'updateForPageDeleted'
		];
		yield 'Restore' => [
			[ 'type' => UpdatePageContributionRecordsJob::TYPE_RESTORE ] + $basePageParams,
			'updateForPageRestored'
		];
		yield 'Revdel' => [
			[
				'type' => UpdatePageContributionRecordsJob::TYPE_REV_DELETE,
				'wiki' => 'awiki',
				'pageID' => 999,
				'deletedRevIDs' => [ 123 ],
				'restoredRevIDs' => [ 456 ],
			],
			'updateRevisionVisibility'
		];
	}
}
