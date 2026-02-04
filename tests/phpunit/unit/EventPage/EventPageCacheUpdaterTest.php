<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\EventPage;

use MediaWiki\Cache\HTMLCacheUpdater;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\EventPage\EventPageCacheUpdater;
use MediaWiki\Output\OutputPage;
use MediaWiki\Utils\MWTimestamp;
use MediaWikiUnitTestCase;
use Wikimedia\Timestamp\TimestampFormat as TS;

/**
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\EventPage\EventPageCacheUpdater
 */
class EventPageCacheUpdaterTest extends MediaWikiUnitTestCase {
	private const FAKE_TIME = 123456789;

	/**
	 * @inheritDoc
	 */
	protected function setUp(): void {
		parent::setUp();
		MWTimestamp::setFakeTime( self::FAKE_TIME );
	}

	private function getCacheUpdater(): EventPageCacheUpdater {
		return new EventPageCacheUpdater(
			$this->createMock( HTMLCacheUpdater::class )
		);
	}

	/**
	 * @covers ::adjustCacheForPageWithRegistration
	 */
	public function testAdjustCacheForPageWithRegistration__pastEvent() {
		$pastEvent = $this->createMock( ExistingEventRegistration::class );
		$pastEvent->expects( $this->atLeastOnce() )
			->method( 'getEndUTCTimestamp' )
			->willReturn( wfTimestamp( TS::MW, self::FAKE_TIME - 1 ) );
		$out = $this->createMock( OutputPage::class );
		$out->expects( $this->never() )->method( 'lowerCdnMaxage' );

		$this->getCacheUpdater()->adjustCacheForPageWithRegistration( $out, $pastEvent );
		// OutputPage does not expose the max age, so we rely on soft assertions in the mocked OutputPage object.
		$this->addToAssertionCount( 1 );
	}

	/**
	 * @covers ::adjustCacheForPageWithRegistration
	 */
	public function testAdjustCacheForPageWithRegistration__futureEvent() {
		$futureDiff = 100;
		$futureEvent = $this->createMock( ExistingEventRegistration::class );
		$futureEvent->expects( $this->atLeastOnce() )
			->method( 'getEndUTCTimestamp' )
			->willReturn( wfTimestamp( TS::MW, self::FAKE_TIME + $futureDiff ) );
		$out = $this->createMock( OutputPage::class );
		$out->expects( $this->once() )
			->method( 'lowerCdnMaxage' )
			->with( $futureDiff );

		$this->getCacheUpdater()->adjustCacheForPageWithRegistration( $out, $futureEvent );
		// OutputPage does not expose the max age, so we rely on soft assertions in the mocked OutputPage object.
		$this->addToAssertionCount( 1 );
	}
}
