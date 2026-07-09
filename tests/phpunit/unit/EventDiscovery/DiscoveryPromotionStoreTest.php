<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\EventDiscovery;

use MediaWiki\Extension\CampaignEvents\EventDiscovery\DiscoveryPromotionStore;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWikiUnitTestCase;
use Wikimedia\ObjectCache\HashBagOStuff;
use Wikimedia\Timestamp\TimestampFormat as TS;

/**
 * @covers \MediaWiki\Extension\CampaignEvents\EventDiscovery\DiscoveryPromotionStore
 */
class DiscoveryPromotionStoreTest extends MediaWikiUnitTestCase {

	private function getStore(): DiscoveryPromotionStore {
		return new DiscoveryPromotionStore( new HashBagOStuff() );
	}

	private function getFutureTimestamp( int $secondsFromNow = 3600 ): string {
		return wfTimestamp( TS::MW, time() + $secondsFromNow );
	}

	public function testTryRecordPromotion_firstTime_returnsTrue(): void {
		$result = $this->getStore()->tryRecordPromotion( 42, new CentralUser( 1 ), $this->getFutureTimestamp() );
		$this->assertTrue( $result );
	}

	public function testTryRecordPromotion_alreadySeen_returnsFalse(): void {
		$store = $this->getStore();
		$endTimestamp = $this->getFutureTimestamp();
		$store->tryRecordPromotion( 42, new CentralUser( 1 ), $endTimestamp );

		$result = $store->tryRecordPromotion( 42, new CentralUser( 1 ), $endTimestamp );
		$this->assertFalse( $result );
	}

	public function testTryRecordPromotion_differentUsers_independentKeys(): void {
		$store = $this->getStore();
		$endTimestamp = $this->getFutureTimestamp();

		$this->assertTrue( $store->tryRecordPromotion( 42, new CentralUser( 1 ), $endTimestamp ) );
		$this->assertTrue( $store->tryRecordPromotion( 42, new CentralUser( 2 ), $endTimestamp ) );
	}

	public function testTryRecordPromotion_differentEvents_independentKeys(): void {
		$store = $this->getStore();
		$user = new CentralUser( 1 );
		$endTimestamp = $this->getFutureTimestamp();

		$this->assertTrue( $store->tryRecordPromotion( 42, $user, $endTimestamp ) );
		$this->assertTrue( $store->tryRecordPromotion( 99, $user, $endTimestamp ) );
	}

	public function testTryRecordPromotion_eventAlreadyEnded_returnsFalse(): void {
		$pastTimestamp = wfTimestamp( TS::MW, time() - 3600 );
		$result = $this->getStore()->tryRecordPromotion( 42, new CentralUser( 1 ), $pastTimestamp );
		$this->assertFalse( $result );
	}
}
