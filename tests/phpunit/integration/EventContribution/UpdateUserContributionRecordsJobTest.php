<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\EventContribution;

use MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionStore;
use MediaWiki\Extension\CampaignEvents\EventContribution\UpdateUserContributionRecordsJob;
use MediaWiki\Extension\CampaignEvents\Tests\Integration\Job\UpdateUsernameRecordsJobBaseTestBase;

/**
 * @group Test
 * @covers \MediaWiki\Extension\CampaignEvents\EventContribution\UpdateUserContributionRecordsJob
 */
class UpdateUserContributionRecordsJobTest extends UpdateUsernameRecordsJobBaseTestBase {
	protected static function getJobClass(): string {
		return UpdateUserContributionRecordsJob::class;
	}

	protected function setupStorageService( string $expectedCalledMethod ): void {
		$store = $this->createMock( EventContributionStore::class );
		$store->expects( $this->once() )->method( $expectedCalledMethod );
		$this->setService( EventContributionStore::SERVICE_NAME, $store );
	}
}
