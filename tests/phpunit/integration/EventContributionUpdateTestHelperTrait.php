<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration;

use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContribution;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Helpers for tests involving code that updates event contribution rows.
 */
trait EventContributionUpdateTestHelperTrait {
	private function getStoredContrib(): EventContribution {
		$row = $this->getDb()->newSelectQueryBuilder()
			->select( '*' )
			->from( 'ce_event_contributions' )
			->fetchRow();
		$store = CampaignEventsServices::getEventContributionStore();
		return $store->newFromRow( $row );
	}

	private static function makeContributionWithUser( int $userID, string $userName ): EventContribution {
		return new EventContribution(
			123,
			$userID,
			$userName,
			WikiMap::getCurrentWikiId(),
			'Some page',
			456,
			789,
			0,
			111,
			22,
			ConvertibleTimestamp::now(),
			false
		);
	}

	private function runUserUpdateJob(): void {
		$this->runJobs(
			[ 'minJobs' => 1, 'maxJobs' => 1 ],
			[ 'type' => 'CampaignEventsUpdateUserContributionRecords' ]
		);
	}
}
