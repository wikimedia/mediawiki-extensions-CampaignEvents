<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\EventContribution;

use Generator;
use InvalidArgumentException;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionStore;
use MediaWiki\Extension\CampaignEvents\EventContribution\UpdateUserContributionRecordsJob;
use MediaWikiIntegrationTestCase;

/**
 * @group Test
 * @covers \MediaWiki\Extension\CampaignEvents\EventContribution\UpdateUserContributionRecordsJob
 */
class UpdateUserContributionRecordsJobTest extends MediaWikiIntegrationTestCase {

	/** @dataProvider provideConstructor */
	public function testConstructor( array $params, ?string $expectedExceptionMessage ) {
		if ( $expectedExceptionMessage !== null ) {
			$this->expectException( InvalidArgumentException::class );
			$this->expectExceptionMessage( $expectedExceptionMessage );
		}
		new UpdateUserContributionRecordsJob( $params );
		if ( $expectedExceptionMessage === null ) {
			$this->addToAssertionCount( 1 );
		}
	}

	public static function provideConstructor(): Generator {
		yield 'Bad type' => [
			[ 'type' => 'doesnotexist' ],
			'Invalid type "doesnotexist"'
		];
		yield 'Rename, missing params' => [
			[ 'type' => UpdateUserContributionRecordsJob::TYPE_RENAME ],
			'Missing parameters: userID, newName'
		];
		yield 'Rename, valid' => [
			[
				'type' => UpdateUserContributionRecordsJob::TYPE_RENAME,
				'userID' => 42,
				'newName' => 'Foo'
			],
			null
		];
		yield 'Delete, missing params' => [
			[ 'type' => UpdateUserContributionRecordsJob::TYPE_DELETE ],
			'Missing parameters: userID'
		];
		yield 'Delete, valid' => [
			[ 'type' => UpdateUserContributionRecordsJob::TYPE_DELETE, 'userID' => 42 ],
			null
		];
		yield 'Visibility change, missing params' => [
			[ 'type' => UpdateUserContributionRecordsJob::TYPE_VISIBILITY ],
			'Missing parameters: userID, userName, isHidden'
		];
		yield 'Visibility change, valid' => [
			[
				'type' => UpdateUserContributionRecordsJob::TYPE_VISIBILITY,
				'userID' => 42,
				'userName' => 'Foo',
				'isHidden' => true
			],
			null
		];
	}

	/** @dataProvider provideRun */
	public function testRun( array $params, string $expectedMethod ) {
		$store = $this->createMock( EventContributionStore::class );
		$store->expects( $this->once() )->method( $expectedMethod );
		$this->setService( EventContributionStore::SERVICE_NAME, $store );

		$job = new UpdateUserContributionRecordsJob( $params );
		$job->run();
	}

	public static function provideRun(): Generator {
		yield 'Rename' => [
			[ 'type' => UpdateUserContributionRecordsJob::TYPE_RENAME, 'userID' => 42, 'newName' => 'Foo' ],
			'updateUserName'
		];
		yield 'Delete' => [
			[ 'type' => UpdateUserContributionRecordsJob::TYPE_DELETE, 'userID' => 42 ],
			'updateUserVisibility'
		];
		yield 'Visibility change' => [
			[
				'type' => UpdateUserContributionRecordsJob::TYPE_VISIBILITY,
				'userID' => 42,
				'userName' => null,
				'isHidden' => true
			],
			'updateUserVisibility'
		];
	}
}
