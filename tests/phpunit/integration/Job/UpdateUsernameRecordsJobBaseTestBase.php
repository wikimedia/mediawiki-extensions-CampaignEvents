<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Job;

use Generator;
use InvalidArgumentException;
use MediaWiki\Extension\CampaignEvents\Job\UpdateUsernameRecordsJobBase;
use MediaWikiIntegrationTestCase;

/**
 * @group Test
 */
abstract class UpdateUsernameRecordsJobBaseTestBase extends MediaWikiIntegrationTestCase {

	abstract protected static function getJobClass(): string;

	/** @dataProvider provideConstructor */
	public function testConstructor( array $params, ?string $expectedExceptionMessage ) {
		if ( $expectedExceptionMessage !== null ) {
			$this->expectException( InvalidArgumentException::class );
			$this->expectExceptionMessage( $expectedExceptionMessage );
		}
		$jobClass = static::getJobClass();
		new $jobClass( $params );
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
			[ 'type' => UpdateUsernameRecordsJobBase::TYPE_RENAME ],
			'Missing parameters: userID, newName'
		];
		yield 'Rename, valid' => [
			[
				'type' => UpdateUsernameRecordsJobBase::TYPE_RENAME,
				'userID' => 42,
				'newName' => 'Foo'
			],
			null
		];
		yield 'Delete, missing params' => [
			[ 'type' => UpdateUsernameRecordsJobBase::TYPE_DELETE ],
			'Missing parameters: userID'
		];
		yield 'Delete, valid' => [
			[ 'type' => UpdateUsernameRecordsJobBase::TYPE_DELETE, 'userID' => 42 ],
			null
		];
		yield 'Visibility change, missing params' => [
			[ 'type' => UpdateUsernameRecordsJobBase::TYPE_VISIBILITY ],
			'Missing parameters: userID, userName, isHidden'
		];
		yield 'Visibility change, valid' => [
			[
				'type' => UpdateUsernameRecordsJobBase::TYPE_VISIBILITY,
				'userID' => 42,
				'userName' => 'Foo',
				'isHidden' => true
			],
			null
		];
	}

	/** @dataProvider provideRun */
	public function testRun( array $params, string $expectedMethod ) {
		$this->setupStorageService( $expectedMethod );

		$jobClass = static::getJobClass();
		$job = new $jobClass( $params );
		$job->run();
	}

	abstract protected function setupStorageService( string $expectedCalledMethod ): void;

	public static function provideRun(): Generator {
		yield 'Rename' => [
			[ 'type' => UpdateUsernameRecordsJobBase::TYPE_RENAME, 'userID' => 42, 'newName' => 'Foo' ],
			'updateUserName'
		];
		yield 'Delete' => [
			[ 'type' => UpdateUsernameRecordsJobBase::TYPE_DELETE, 'userID' => 42 ],
			'updateUserVisibility'
		];
		yield 'Visibility change' => [
			[
				'type' => UpdateUsernameRecordsJobBase::TYPE_VISIBILITY,
				'userID' => 42,
				'userName' => null,
				'isHidden' => true
			],
			'updateUserVisibility'
		];
	}
}
