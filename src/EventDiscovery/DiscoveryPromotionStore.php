<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\EventDiscovery;

use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Utils\MWTimestamp;
use Wikimedia\ObjectCache\BagOStuff;
use Wikimedia\Timestamp\TimestampFormat as TS;

readonly class DiscoveryPromotionStore implements IDiscoveryPromotionStore {

	public function __construct( private BagOStuff $stash ) {
	}

	/**
	 * @inheritDoc
	 */
	public function tryRecordPromotion( int $eventID, CentralUser $user, string $eventEndTimestamp ): bool {
		$ttl = (int)wfTimestamp( TS::UNIX, $eventEndTimestamp ) - (int)MWTimestamp::now( TS::UNIX );
		if ( $ttl <= 0 ) {
			return false;
		}
		$key = $this->stash->makeGlobalKey(
			'CampaignEvents-discovery-promotion',
			(string)$eventID,
			(string)$user->getCentralID()
		);
		return $this->stash->add( $key, true, $ttl );
	}
}
