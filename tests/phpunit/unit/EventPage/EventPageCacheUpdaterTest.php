<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\EventPage;

use Generator;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\EventPage\EventPageCacheUpdater;
use MediaWikiUnitTestCase;
use MWTimestamp;
use OutputPage;

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
		return new EventPageCacheUpdater();
	}

	/**
	 * @param OutputPage $out
	 * @param ExistingEventRegistration $registration
	 * @covers ::adjustCacheForPageWithRegistration
	 * @dataProvider provideRegistrations
	 */
	public function testAdjustCacheForPageWithRegistration( OutputPage $out, ExistingEventRegistration $registration ) {
		$this->getCacheUpdater()->adjustCacheForPageWithRegistration( $out, $registration );
		// OutputPage does not expose the max age, so we rely on soft assertions in the mocked OutputPage object.
		$this->addToAssertionCount( 1 );
	}

	public function provideRegistrations(): Generator {
		$pastEvent = $this->createMock( ExistingEventRegistration::class );
		$pastEvent->expects( $this->atLeastOnce() )
			->method( 'getEndUTCTimestamp' )
			->willReturn( wfTimestamp( TS_MW, self::FAKE_TIME - 1 ) );
		$pastOut = $this->createMock( OutputPage::class );
		$pastOut->expects( $this->never() )->method( 'lowerCdnMaxage' );
		yield 'Event in the past' => [ $pastOut, $pastEvent ];

		$futureDiff = 100;
		$futureEvent = $this->createMock( ExistingEventRegistration::class );
		$futureEvent->expects( $this->atLeastOnce() )
			->method( 'getEndUTCTimestamp' )
			->willReturn( wfTimestamp( TS_MW, self::FAKE_TIME + $futureDiff ) );
		$futureOut = $this->createMock( OutputPage::class );
		$futureOut->expects( $this->once() )
			->method( 'lowerCdnMaxage' )
			->with( $futureDiff );
		yield 'Event in the future' => [ $futureOut, $futureEvent ];
	}
}
