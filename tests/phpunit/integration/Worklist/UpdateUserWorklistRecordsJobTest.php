<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Worklist;

use MediaWiki\Extension\CampaignEvents\Tests\Integration\Job\UpdateUsernameRecordsJobBaseTestBase;
use MediaWiki\Extension\CampaignEvents\Worklist\UpdateUserWorklistRecordsJob;
use MediaWiki\Extension\CampaignEvents\Worklist\WorklistSecondaryStore;

/**
 * @group Test
 * @covers \MediaWiki\Extension\CampaignEvents\Worklist\UpdateUserWorklistRecordsJob
 */
class UpdateUserWorklistRecordsJobTest extends UpdateUsernameRecordsJobBaseTestBase {
	protected static function getJobClass(): string {
		return UpdateUserWorklistRecordsJob::class;
	}

	protected function setupStorageService( string $expectedCalledMethod ): void {
		$store = $this->createMock( WorklistSecondaryStore::class );
		$store->expects( $this->once() )->method( $expectedCalledMethod );
		$this->setService( WorklistSecondaryStore::SERVICE_NAME, $store );
	}
}
