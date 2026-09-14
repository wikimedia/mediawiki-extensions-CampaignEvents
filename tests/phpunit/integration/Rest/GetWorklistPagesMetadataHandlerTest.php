<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Rest;

use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventNotFoundException;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\Rest\GetWorklistPagesMetadataHandler;
use MediaWiki\Extension\CampaignEvents\Worklist\WorklistPagesSecondaryStore;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWikiIntegrationTestCase;

/**
 * @group Test
 * @group Database
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\GetWorklistPagesMetadataHandler
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\EventIDParamTrait
 */
class GetWorklistPagesMetadataHandlerTest extends MediaWikiIntegrationTestCase {
	use HandlerTestTrait;

	private const EVENT_ID = 42;
	private const REQ_DATA = [
		'pathParams' => [ 'id' => self::EVENT_ID ],
	];

	/**
	 * @param list<array{wiki: string, prefixedtext: string, timestamp: string}> $storedMetadata
	 */
	private function newHandler(
		array $storedMetadata,
		bool $eventExists = true,
		?string $deletionTimestamp = null
	): GetWorklistPagesMetadataHandler {
		$eventLookup = $this->createMock( IEventLookup::class );
		if ( $eventExists ) {
			$event = $this->createMock( ExistingEventRegistration::class );
			$event->method( 'getDeletionTimestamp' )->willReturn( $deletionTimestamp );
			$eventLookup->method( 'getEventByID' )->willReturn( $event );
		} else {
			$eventLookup->method( 'getEventByID' )
				->willThrowException( $this->createMock( EventNotFoundException::class ) );
		}

		$store = $this->createMock( WorklistPagesSecondaryStore::class );
		$store->method( 'getPagesMetadataForEvent' )
			->with( self::EVENT_ID )
			->willReturn( $storedMetadata );

		return new GetWorklistPagesMetadataHandler( $eventLookup, $store );
	}

	public function testRun__noPages(): void {
		$respData = $this->executeHandlerAndGetBodyData(
			$this->newHandler( [] ),
			new RequestData( self::REQ_DATA )
		);

		$this->assertSame( [ 'pages' => [] ], $respData );
	}

	public function testRun__returnsTheDateInBothForms(): void {
		$respData = $this->executeHandlerAndGetBodyData(
			$this->newHandler( [
				[ 'wiki' => 'awiki', 'prefixedtext' => 'Bears', 'timestamp' => '20260903143200' ],
			] ),
			new RequestData( self::REQ_DATA )
		);

		$this->assertCount( 1, $respData['pages'] );
		$article = $respData['pages'][0];
		$this->assertSame( 'awiki', $article['wiki'] );
		$this->assertSame( 'Bears', $article['title'] );
		// Machine-readable, for the datetime attribute of the element that shows the date.
		$this->assertSame( '2026-09-03T14:32:00Z', $article['added_at'] );
		// Formatted for the reader; the exact wording depends on their language and preferences,
		// so this only asserts that something was formatted.
		$this->assertStringContainsString( '2026', $article['added'] );
	}

	public function testRun__keepsTheOrderTheStoreReturned(): void {
		$respData = $this->executeHandlerAndGetBodyData(
			$this->newHandler( [
				[ 'wiki' => 'awiki', 'prefixedtext' => 'Newer', 'timestamp' => '20260903143200' ],
				[ 'wiki' => 'bwiki', 'prefixedtext' => 'Older', 'timestamp' => '20260101120000' ],
			] ),
			new RequestData( self::REQ_DATA )
		);

		$this->assertSame(
			[ 'Newer', 'Older' ],
			array_column( $respData['pages'], 'title' )
		);
	}

	public function testRun__deletedEvent(): void {
		// A deleted registration answers like a missing one, as the other event endpoints do.
		$this->expectException( LocalizedHttpException::class );
		$this->expectExceptionCode( 404 );
		$this->executeHandler(
			$this->newHandler( [], true, '20260101120000' ),
			new RequestData( self::REQ_DATA )
		);
	}

	public function testRun__eventNotFound(): void {
		$this->expectException( LocalizedHttpException::class );
		$this->expectExceptionCode( 404 );
		$this->executeHandler(
			$this->newHandler( [], false ),
			new RequestData( self::REQ_DATA )
		);
	}
}
