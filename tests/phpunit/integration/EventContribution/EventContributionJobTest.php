<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\EventContribution;

use InvalidArgumentException;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContribution;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionComputeMetrics;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionJob;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionStore;
use MediaWikiIntegrationTestCase;

/**
 * @group Test
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionJob
 */
class EventContributionJobTest extends MediaWikiIntegrationTestCase {

	/**
	 * @covers ::__construct
	 * @dataProvider provideConstructorTests
	 */
	public function testConstructor( array $params, bool $shouldThrow = false, string $expectedExceptionMessage = '' ) {
		if ( $shouldThrow ) {
			$this->expectException( InvalidArgumentException::class );
			$this->expectExceptionMessage( $expectedExceptionMessage );
		}

		$job = new EventContributionJob( $params );

		if ( !$shouldThrow ) {
			$this->assertInstanceOf( EventContributionJob::class, $job );
			$this->assertEquals( 'CampaignEventsComputeEventContribution', $job->getType() );
		}
	}

	public static function provideConstructorTests(): array {
		$testData = [
			'Valid parameters' => [
				'params' => [
					'eventId' => 123,
					'revisionId' => 456,
					'userId' => 789,
					'wiki' => 'enwiki',
				],
				'shouldThrow' => false
			],
			'Missing eventId' => [
				'params' => [
					'revisionId' => 456,
					'userId' => 789,
					'wiki' => 'enwiki',
				],
				'shouldThrow' => true,
				'expectedExceptionMessage' => 'Missing parameters: eventId'
			],
			'Missing revisionId' => [
				'params' => [
					'eventId' => 123,
					'userId' => 789,
					'wiki' => 'enwiki',
				],
				'shouldThrow' => true,
				'expectedExceptionMessage' => 'Missing parameters: revisionId'
			],
			'Missing userId' => [
				'params' => [
					'eventId' => 123,
					'revisionId' => 456,
					'wiki' => 'enwiki',
				],
				'shouldThrow' => true,
				'expectedExceptionMessage' => 'Missing parameters: userId'
			],
			'Missing wiki' => [
				'params' => [
					'eventId' => 123,
					'revisionId' => 456,
					'userId' => 789,
				],
				'shouldThrow' => true,
				'expectedExceptionMessage' => 'Missing parameters: wiki'
			],
			'Missing all parameters' => [
				'params' => [],
				'shouldThrow' => true,
				'expectedExceptionMessage' => 'Missing parameters: eventId, revisionId, userId, wiki'
			]
		];

		// Convert associative arrays to positional arrays for PHPUnit
		$result = [];
		foreach ( $testData as $name => $data ) {
			$result[$name] = [
				$data['params'],
				$data['shouldThrow'],
				$data['expectedExceptionMessage'] ?? ''
			];
		}
		return $result;
	}

	/**
	 * @covers ::run
	 * @dataProvider provideRunTests
	 */
	public function testRun( array $params, array $expectedMetrics ) {
		$job = new EventContributionJob( $params );

		$timestamp = '20240101120000';
		$pageID = 123456;
		$pagePrefixedText = 'Test page';
		$userName = 'Some user';
		$expectedContribution = new EventContribution(
			$params['eventId'],
			$params['userId'],
			$userName,
			$params['wiki'],
			$pagePrefixedText,
			$pageID,
			$params['revisionId'],
			$expectedMetrics['editedType'],
			$expectedMetrics['bytesDelta'],
			$expectedMetrics['linksDelta'],
			$timestamp,
			false
		);

		$computeMetrics = $this->createMock( EventContributionComputeMetrics::class );
		$computeMetrics->expects( $this->once() )
			->method( 'computeEventContribution' )
			->with(
				$params['revisionId'],
				$params['eventId'],
				$params['userId'],
				$params['wiki']
			)
			->willReturn( $expectedContribution );
		$this->setService( EventContributionComputeMetrics::SERVICE_NAME, $computeMetrics );

		$store = $this->createMock( EventContributionStore::class );
		// Capture what's actually saved to the database
		$savedContribution = null;
		$store->expects( $this->once() )
			->method( 'saveEventContribution' )
			->willReturnCallback( static function ( EventContribution $contribution ) use ( &$savedContribution ) {
				$savedContribution = $contribution;
			} );
		$this->setService( EventContributionStore::SERVICE_NAME, $store );

		$result = $job->run();

		$this->assertTrue( $result );
		$this->assertNull( $job->getLastError() );

		// Verify what was actually saved to the database
		$this->assertNotNull( $savedContribution, 'EventContribution should have been saved' );
		$this->assertEquals( $params['eventId'], $savedContribution->getEventId() );
		$this->assertEquals( $params['userId'], $savedContribution->getUserId() );
		$this->assertEquals( $userName, $savedContribution->getUserName() );
		$this->assertEquals( $params['wiki'], $savedContribution->getWiki() );
		$this->assertEquals( $pageID, $savedContribution->getPageId() );
		$this->assertEquals( $pagePrefixedText, $savedContribution->getPagePrefixedtext() );
		$this->assertEquals( $params['revisionId'], $savedContribution->getRevisionId() );
		$this->assertEquals( $expectedMetrics['editedType'], $savedContribution->getEditFlags() );
		$this->assertEquals( $expectedMetrics['bytesDelta'], $savedContribution->getBytesDelta() );
		$this->assertEquals( $expectedMetrics['linksDelta'], $savedContribution->getLinksDelta() );
		$this->assertEquals( $timestamp, $savedContribution->getTimestamp() );
		$this->assertFalse( $savedContribution->isDeleted() );
	}

	public static function provideRunTests(): array {
		$standardParams = [
			'eventId' => 123,
			'revisionId' => 456,
			'userId' => 789,
			'wiki' => 'enwiki',
		];

		$editMetrics = [
			'bytesDelta' => 150,
			'editedType' => 0,
			'linksDelta' => 3
		];

		$creationMetrics = [
			'bytesDelta' => 500,
			'editedType' => EventContribution::EDIT_FLAG_PAGE_CREATION,
			'linksDelta' => 5
		];

		$removalMetrics = [
			'bytesDelta' => -200,
			'editedType' => 0,
			'linksDelta' => -2
		];

		$testData = [
			'Successful execution' => [
				'params' => $standardParams,
				'expectedMetrics' => $editMetrics
			],
			'Successful execution with creation' => [
				'params' => $standardParams,
				'expectedMetrics' => $creationMetrics
			],
			'Successful execution with negative metrics' => [
				'params' => $standardParams,
				'expectedMetrics' => $removalMetrics
			]
		];

		// Convert associative arrays to positional arrays for PHPUnit
		$result = [];
		foreach ( $testData as $name => $data ) {
			$result[$name] = [
				$data['params'],
				$data['expectedMetrics']
			];
		}
		return $result;
	}
}
